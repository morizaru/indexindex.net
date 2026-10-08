<?php
// admin/ndl_collection_extract.php
// INDEXINDEX - NDL Search 漫画雑誌候補抽出（登録ボタン付き）

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text($value): string {
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~[\r\n\t]+~u', ' ', $value);
    $value = preg_replace('~[ 　]+~u', ' ', $value);
    return trim((string)$value);
}

function first_non_empty(array $values): string {
    foreach ($values as $v) {
        $v = clean_text($v);
        if ($v !== '') return $v;
    }
    return '';
}

function fetch_url(string $url, int $timeout = 90): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'INDEXINDEX/0.1 (+https://indexindex.net/)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml,text/xml;q=0.9,*/*;q=0.7',
            'Accept-Language: ja,en-US;q=0.8,en;q=0.6',
            'Connection: close',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $totalTime = (string)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'ok' => ($errno === 0 && $httpCode >= 200 && $httpCode < 300 && is_string($body)),
        'url' => $url,
        'effective_url' => $effectiveUrl,
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'total_time' => $totalTime,
        'errno' => $errno,
        'error' => $error,
        'body' => is_string($body) ? $body : '',
    ];
}

function cql_quote(string $s): string {
    $s = trim($s);
    $s = str_replace('"', '\\"', $s);
    return '"' . $s . '"';
}

function build_sru_url(array $params): array {
    $year = trim((string)($params['year'] ?? ''));
    $keyword = trim((string)($params['keyword'] ?? '漫画'));
    $title = trim((string)($params['title'] ?? ''));
    $publisher = trim((string)($params['publisher'] ?? ''));
    $startRecord = max(1, (int)($params['startRecord'] ?? 1));
    $maximumRecords = (int)($params['maximumRecords'] ?? 50);
    if ($maximumRecords < 1) $maximumRecords = 50;
    if ($maximumRecords > 200) $maximumRecords = 200;

    $conditions = [];

    // 資料種別：雑誌
    $conditions[] = 'mediatype=' . cql_quote('periodicals');

    // 漫画/マンガっぽいものを拾う
    if ($keyword !== '') {
        $conditions[] = 'anywhere=' . cql_quote($keyword);
    }

    if ($title !== '') {
        $conditions[] = 'title=' . cql_quote($title);
    }

    if ($publisher !== '') {
        $conditions[] = 'publisher=' . cql_quote($publisher);
    }

    if ($year !== '' && preg_match('~^\d{4}$~', $year)) {
        $conditions[] = 'from=' . cql_quote($year);
        $conditions[] = 'until=' . cql_quote($year);
    }

    $cql = implode(' AND ', $conditions);

    $url = 'https://ndlsearch.ndl.go.jp/api/sru?' . http_build_query([
        'operation' => 'searchRetrieve',
        'version' => '1.2',
        'recordSchema' => 'dcndl',
        'recordPacking' => 'xml',
        'startRecord' => $startRecord,
        'maximumRecords' => $maximumRecords,
        'query' => $cql,
    ], '', '&', PHP_QUERY_RFC3986);

    return [$url, $cql];
}

function xpath_texts(DOMXPath $xp, DOMNode $ctx, string $query): array {
    $out = [];
    $nodes = $xp->query($query, $ctx);
    if (!$nodes) return $out;

    foreach ($nodes as $node) {
        $v = clean_text($node->textContent);
        if ($v !== '') $out[] = $v;
    }

    return array_values(array_unique($out));
}

function xpath_first_text(DOMXPath $xp, DOMNode $ctx, string $query): string {
    return first_non_empty(xpath_texts($xp, $ctx, $query));
}

function attr_values_by_localname(DOMXPath $xp, DOMNode $ctx, string $query, string $attrLocalName): array {
    $out = [];
    $nodes = $xp->query($query, $ctx);
    if (!$nodes) return $out;

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement || !$node->hasAttributes()) continue;

        foreach ($node->attributes as $attr) {
            if ($attr->localName === $attrLocalName) {
                $v = clean_text($attr->value);
                if ($v !== '') $out[] = $v;
            }
        }
    }

    return array_values(array_unique($out));
}

function attr_values_resource(DOMXPath $xp, DOMNode $ctx, string $query): array {
    return attr_values_by_localname($xp, $ctx, $query, 'resource');
}

function extract_record_id_from_text(string $text): string {
    if (preg_match('~R\d+-I[0-9A-Za-z]+(?:-d\d+)?~u', $text, $m)) {
        return $m[0];
    }
    return '';
}

function extract_pid_from_text(string $text): string {
    $patterns = [
        '~info:ndljp/pid/(\d+)~u',
        '~https?://dl\.ndl\.go\.jp/pid/(\d+)~u',
        '~https?://doi\.org/10\.11501/(\d+)~u',
        '~R100000039-I(\d+)~u',
        '~-d(\d+)~u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return $m[1];
        }
    }

    return '';
}

function extract_all_pids(string $text): array {
    $patterns = [
        '~info:ndljp/pid/(\d+)~u',
        '~https?://dl\.ndl\.go\.jp/pid/(\d+)~u',
        '~https?://doi\.org/10\.11501/(\d+)~u',
        '~R100000039-I(\d+)~u',
    ];

    $ids = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $m)) {
            foreach ($m[1] as $id) {
                if ($id !== '') $ids[] = $id;
            }
        }
    }

    return array_values(array_unique($ids));
}

function extract_parent_pid_from_resources(array $resources): string {
    foreach ($resources as $res) {
        // dcterms:isPartOf や 親誌の R100000039-Ixxxx#item を優先
        if (preg_match('~dl\.ndl\.go\.jp/pid/(\d+)~u', $res, $m)) {
            return $m[1];
        }
        if (preg_match('~R100000039-I(\d+)~u', $res, $m)) {
            return $m[1];
        }
    }
    return '';
}

function parse_sru_records(string $xml): array {
    $records = [];

    if (trim($xml) === '') {
        return $records;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();

    if (!$loaded) {
        return $records;
    }

    $xp = new DOMXPath($dom);

    $recordNodes = $xp->query('//*[local-name()="record" and .//*[local-name()="recordData"]]');
    if (!$recordNodes) return $records;

    foreach ($recordNodes as $recordNode) {
        $recordXml = $dom->saveXML($recordNode) ?: '';
        $raw = clean_text($recordNode->textContent . ' ' . $recordXml);

        $adminAbouts = attr_values_by_localname($xp, $recordNode, './/*[local-name()="BibAdminResource"]', 'about');
        $materialNodes = $xp->query('.//*[local-name()="BibResource" and contains(@*[local-name()="about"], "#material")]', $recordNode);
        $material = ($materialNodes && $materialNodes->length > 0) ? $materialNodes->item(0) : $recordNode;

        $recordId = '';
        foreach (array_merge($adminAbouts, [$recordXml]) as $v) {
            $recordId = extract_record_id_from_text($v);
            if ($recordId !== '') break;
        }

        $isIssue = preg_match('~-d\d+$~u', $recordId) === 1;
        $parentRecordId = $recordId;
        if ($isIssue) {
            $parentRecordId = preg_replace('~-d\d+$~u', '', $recordId) ?? $recordId;
        }

        $title = xpath_first_text($xp, $material, './*[local-name()="title" and namespace-uri()="http://purl.org/dc/terms/"][1]');
        if ($title === '') {
            $title = xpath_first_text($xp, $material, './*[local-name()="title"]//*[local-name()="value"][1]');
        }

        $publisher = xpath_first_text($xp, $material, './*[local-name()="publisher"]//*[local-name()="name"][1]');
        if ($publisher === '') {
            $publisher = xpath_first_text($xp, $material, './/*[local-name()="publisher"][1]');
        }

        $issued = xpath_first_text($xp, $material, './*[local-name()="issued" and contains(@*[local-name()="datatype"], "W3CDTF")][1]');
        if ($issued === '') {
            $issued = xpath_first_text($xp, $material, './*[local-name()="issued"][1]');
        }
        if ($issued === '') {
            $issued = xpath_first_text($xp, $material, './*[local-name()="date"][1]');
        }

        $volume = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="value"][1]');
        $volumeTranscription = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="transcription"][1]');
        $volumeRange = xpath_first_text($xp, $material, './*[local-name()="volumeRange"][1]');

        $genreValues = xpath_texts($xp, $material, './/*[local-name()="genre"]//*[local-name()="value" or local-name()="transcription"]');
        $materialLabels = attr_values_by_localname($xp, $material, './/*[local-name()="materialType"]', 'label');
        $materialResources = attr_values_resource($xp, $material, './/*[local-name()="materialType"]');

        $tocTitles = xpath_texts($xp, $material, './/*[local-name()="tableOfContents"]//*[local-name()="title"]');
        $tocCount = count($tocTitles);

        $identifierTexts = xpath_texts($xp, $material, './/*[local-name()="identifier" or local-name()="sourceIdentifier"]');
        $seeAlsoResources = attr_values_resource($xp, $material, './/*[local-name()="seeAlso"]');
        $sourceResources = attr_values_resource($xp, $material, './/*[local-name()="source" or local-name()="isPartOf"]');
        $recordResources = attr_values_resource($xp, $recordNode, './/*[local-name()="record"]');

        $issuePid = '';
        if ($isIssue && preg_match('~-d(\d+)$~u', $recordId, $m)) {
            $issuePid = $m[1];
        }
        if ($issuePid === '') {
            foreach (array_merge($identifierTexts, $seeAlsoResources, [$recordXml]) as $v) {
                $issuePid = extract_pid_from_text($v);
                if ($issuePid !== '') break;
            }
        }

        $parentPid = '';
        if ($isIssue) {
            $parentPid = extract_parent_pid_from_resources($sourceResources);
        } else {
            // 親誌レコードでは R100000039-Ixxxx#item が親pid相当
            $parentPid = extract_parent_pid_from_resources($recordResources);
        }

        if ($parentPid === '') {
            foreach (extract_all_pids($recordXml) as $pid) {
                if ($pid !== $issuePid) {
                    $parentPid = $pid;
                    break;
                }
            }
        }

        $genreText = implode(' ', $genreValues);
        $materialText = implode(' ', array_merge($materialLabels, $materialResources));
        // 漫画判定は dcndl:genre のみで行う。
        // raw全体で判定すると、目次や説明文に「漫画」が含まれる一般雑誌まで拾ってしまう。
        $isManga = (mb_stripos($genreText, '漫画') !== false || mb_stripos($genreText, 'マンガ') !== false);

        // 雑誌判定は materialType を優先し、念のため ndltype/Journal も見る。
        $isJournal = (mb_stripos($materialText, '雑誌') !== false || mb_stripos($materialText, 'Journal') !== false || mb_stripos($raw, 'ndltype/Journal') !== false);

        $ndlSearchUrl = $recordId !== '' ? 'https://ndlsearch.ndl.go.jp/books/' . rawurlencode($recordId) : '';
        $indexGetUrl = ($isIssue && $recordId !== '') ? 'https://ndlsearch.ndl.go.jp/api/index/get/' . rawurlencode($recordId) . '?' : '';
        $dlUrl = $issuePid !== '' ? 'https://dl.ndl.go.jp/pid/' . rawurlencode($issuePid) : '';
        $parentUrl = $parentPid !== '' ? 'https://dl.ndl.go.jp/pid/' . rawurlencode($parentPid) : '';

        $records[] = [
            'record_id' => $recordId,
            'parent_record_id' => $parentRecordId,
            'is_issue' => $isIssue,
            'title' => $title,
            'publisher' => $publisher,
            'issued' => $issued,
            'volume' => $volume,
            'volume_transcription' => $volumeTranscription,
            'volume_range' => $volumeRange,
            'issue_pid' => $isIssue ? $issuePid : '',
            'parent_pid' => $parentPid,
            'genre_values' => $genreValues,
            'material_labels' => $materialLabels,
            'toc_count' => $tocCount,
            'is_manga' => $isManga,
            'is_journal' => $isJournal,
            'ndlsearch_url' => $ndlSearchUrl,
            'index_get_url' => $indexGetUrl,
            'dl_url' => $dlUrl,
            'parent_url' => $parentUrl,
            'raw' => $raw,
        ];
    }

    return $records;
}

function group_magazines(array $records): array {
    $groups = [];

    foreach ($records as $r) {
        // 漫画・雑誌以外は候補から外す。ただし詳細表には残す。
        if (!$r['is_manga'] || !$r['is_journal']) {
            continue;
        }

        $key = '';
        if ($r['parent_pid'] !== '') {
            $key = 'NDL_PARENT_PID:' . $r['parent_pid'];
        } elseif ($r['parent_record_id'] !== '') {
            $key = 'NDL_PARENT_RECORD:' . $r['parent_record_id'];
        } else {
            $key = 'NDL_TITLE:' . md5($r['title'] . '|' . $r['publisher']);
        }

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'source_id' => $r['parent_pid'] !== '' ? 'NDL-PARENT-PID-' . $r['parent_pid'] : $key,
                'parent_pid' => $r['parent_pid'],
                'parent_record_id' => $r['parent_record_id'],
                'title' => $r['title'],
                'publisher' => $r['publisher'],
                'volume_range' => $r['volume_range'],
                'record_count' => 0,
                'issue_count' => 0,
                'toc_issue_count' => 0,
                'issues' => [],
                'parent_url' => $r['parent_url'],
                'ndlsearch_url' => $r['ndlsearch_url'],
            ];
        }

        if ($groups[$key]['title'] === '' && $r['title'] !== '') $groups[$key]['title'] = $r['title'];
        if ($groups[$key]['publisher'] === '' && $r['publisher'] !== '') $groups[$key]['publisher'] = $r['publisher'];
        if ($groups[$key]['volume_range'] === '' && $r['volume_range'] !== '') $groups[$key]['volume_range'] = $r['volume_range'];
        if ($groups[$key]['parent_pid'] === '' && $r['parent_pid'] !== '') $groups[$key]['parent_pid'] = $r['parent_pid'];
        if ($groups[$key]['parent_url'] === '' && $r['parent_url'] !== '') $groups[$key]['parent_url'] = $r['parent_url'];

        $groups[$key]['record_count']++;

        if ($r['is_issue']) {
            $groups[$key]['issue_count']++;
            if ((int)$r['toc_count'] > 0) $groups[$key]['toc_issue_count']++;
            $groups[$key]['issues'][] = $r;
        }
    }

    // 同じ parent_record_id で、親pidありグループと親pidなしグループが混在する場合は、
    // 親pidありを正とし、親pidなしの重複候補は一覧から外す。
    $recordIdsWithParentPid = [];
    foreach ($groups as $g) {
        if (($g['parent_record_id'] ?? '') !== '' && ($g['parent_pid'] ?? '') !== '') {
            $recordIdsWithParentPid[$g['parent_record_id']] = true;
        }
    }

    foreach ($groups as $k => $g) {
        if (($g['parent_pid'] ?? '') === ''
            && ($g['parent_record_id'] ?? '') !== ''
            && isset($recordIdsWithParentPid[$g['parent_record_id']])) {
            unset($groups[$k]);
        }
    }

    uasort($groups, function ($a, $b) {
        return [$a['publisher'], $a['title'], $a['source_id']] <=> [$b['publisher'], $b['title'], $b['source_id']];
    });

    return array_values($groups);
}


function register_ndl_collections(PDO $pdo, array $groups, string $year, string $keyword, string $title, string $publisher): array {
    if (empty($groups)) {
        throw new Exception('登録対象がありません。');
    }

    $pdo->beginTransaction();

    $inserted = 0;
    $updated = 0;

    foreach ($groups as $g) {
        $externalCollectionId = clean_text($g['source_id'] ?? '');
        $externalCollectionUrl = clean_text($g['parent_url'] ?? '');
        if ($externalCollectionUrl === '') {
            $externalCollectionUrl = clean_text($g['ndlsearch_url'] ?? '');
        }

        $publisherName = clean_text($g['publisher'] ?? '');
        $magazineName = clean_text($g['title'] ?? '');

        if ($externalCollectionId === '' || $magazineName === '') {
            continue;
        }

        $noteParts = [];
        $noteParts[] = 'NDLから抽出';
        if ($year !== '') $noteParts[] = '抽出年：' . $year;
        if (($g['parent_pid'] ?? '') !== '') $noteParts[] = '親pid：' . $g['parent_pid'];
        if (($g['parent_record_id'] ?? '') !== '') $noteParts[] = '親record_id：' . $g['parent_record_id'];
        if (($g['volume_range'] ?? '') !== '') $noteParts[] = '刊行範囲：' . $g['volume_range'];
        $noteParts[] = '対象号数：' . (int)($g['issue_count'] ?? 0);
        $noteParts[] = '目次あり号数：' . (int)($g['toc_issue_count'] ?? 0);
        if ($keyword !== '') $noteParts[] = 'キーワード：' . $keyword;
        if ($title !== '') $noteParts[] = 'タイトル条件：' . $title;
        if ($publisher !== '') $noteParts[] = '出版社条件：' . $publisher;

        $note = implode(' / ', $noteParts);

        $stmt = $pdo->prepare("\n            SELECT id\n            FROM external_magazine_collections\n            WHERE source_name = '国立国会図書館'\n              AND external_collection_id = :external_collection_id\n            LIMIT 1\n        ");
        $stmt->execute([
            ':external_collection_id' => $externalCollectionId,
        ]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $pdo->prepare("\n                UPDATE external_magazine_collections\n                SET\n                    external_collection_url = :external_collection_url,\n                    publisher_name = :publisher_name,\n                    magazine_name = :magazine_name,\n                    note = :note,\n                    updated_at = CURRENT_TIMESTAMP\n                WHERE id = :id\n            ");
            $stmt->execute([
                ':external_collection_url' => $externalCollectionUrl !== '' ? $externalCollectionUrl : null,
                ':publisher_name' => $publisherName !== '' ? $publisherName : null,
                ':magazine_name' => $magazineName,
                ':note' => $note,
                ':id' => (int)$exists['id'],
            ]);
            $updated++;
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO external_magazine_collections (\n                    source_name,\n                    external_collection_id,\n                    external_collection_url,\n                    publisher_name,\n                    magazine_name,\n                    note\n                ) VALUES (\n                    '国立国会図書館',\n                    :external_collection_id,\n                    :external_collection_url,\n                    :publisher_name,\n                    :magazine_name,\n                    :note\n                )\n            ");
            $stmt->execute([
                ':external_collection_id' => $externalCollectionId,
                ':external_collection_url' => $externalCollectionUrl !== '' ? $externalCollectionUrl : null,
                ':publisher_name' => $publisherName !== '' ? $publisherName : null,
                ':magazine_name' => $magazineName,
                ':note' => $note,
            ]);
            $inserted++;
        }
    }

    $pdo->commit();

    return [
        'inserted' => $inserted,
        'updated' => $updated,
    ];
}

function extract_number_of_records(string $xml): string {
    if (preg_match('~<[^>]*numberOfRecords[^>]*>(\d+)</[^>]*numberOfRecords>~u', $xml, $m)) {
        return $m[1];
    }
    return '';
}

$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$year = isset($request['year']) ? trim((string)$request['year']) : '1987';
$keyword = isset($request['keyword']) ? trim((string)$request['keyword']) : '漫画';
$title = isset($request['title']) ? trim((string)$request['title']) : '';
$publisher = isset($request['publisher']) ? trim((string)$request['publisher']) : '';
$startRecord = isset($request['startRecord']) ? (int)$request['startRecord'] : 1;
$maximumRecords = isset($request['maximumRecords']) ? (int)$request['maximumRecords'] : 50;
$run = isset($request['run']) || isset($request['register']);
$doRegister = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']));

$message = '';
$error = '';
$result = null;
$records = [];
$groups = [];
$cql = '';
$sruUrl = '';
$totalHits = '';

if ($run) {
    try {
        [$sruUrl, $cql] = build_sru_url([
            'year' => $year,
            'keyword' => $keyword,
            'title' => $title,
            'publisher' => $publisher,
            'startRecord' => $startRecord,
            'maximumRecords' => $maximumRecords,
        ]);

        $result = fetch_url($sruUrl, 90);

        if (!$result['ok']) {
            throw new Exception('SRU取得失敗 HTTP ' . $result['http_code'] . ' / errno ' . $result['errno'] . ' / ' . $result['error']);
        }

        $totalHits = extract_number_of_records($result['body']);
        $records = parse_sru_records($result['body']);
        $groups = group_magazines($records);

        if ($doRegister) {
            $registered = register_ndl_collections($pdo, $groups, $year, $keyword, $title, $publisher);
            $message = '登録完了：新規 ' . $registered['inserted'] . '件 / 更新 ' . $registered['updated'] . '件';
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$nextStart = $startRecord + max(1, min(200, $maximumRecords));
$prevStart = max(1, $startRecord - max(1, min(200, $maximumRecords)));

function query_link(array $extra): string {
    global $year, $keyword, $title, $publisher, $startRecord, $maximumRecords;
    $base = [
        'run' => '1',
        'year' => $year,
        'keyword' => $keyword,
        'title' => $title,
        'publisher' => $publisher,
        'startRecord' => $startRecord,
        'maximumRecords' => $maximumRecords,
    ];
    return '?' . http_build_query(array_merge($base, $extra));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>NDL漫画雑誌候補抽出・登録 - INDEXINDEX</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; background: #f5f5f5; color: #222; }
        .container { max-width: 1320px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        h1 { margin-top: 0; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 12px; color: #0066cc; font-weight: bold; text-decoration: none; }
        .search-box { display: grid; grid-template-columns: 120px 1fr 120px 1fr; gap: 10px; align-items: center; margin-bottom: 20px; }
        input[type="text"], input[type="number"] { width: 100%; padding: 9px; font-size: 15px; box-sizing: border-box; }
        button, .button { display: inline-block; padding: 9px 14px; font-size: 15px; border: 1px solid #333; background: #333; color: #fff; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .button.secondary { background: #666; border-color: #666; }
        .box { background: #f3f3f3; border: 1px solid #ddd; padding: 12px; margin-bottom: 16px; border-radius: 4px; line-height: 1.8; }
        .message { padding: 12px; background: #e8f5e9; border: 1px solid #81c784; margin-bottom: 16px; border-radius: 4px; }
        .error { padding: 12px; background: #ffebee; border: 1px solid #e57373; color: #b00020; margin-bottom: 16px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 16px; }
        th, td { border: 1px solid #ccc; padding: 8px; word-break: break-all; vertical-align: top; }
        th { background: #eee; text-align: left; white-space: nowrap; }
        .id { font-weight: bold; color: #b00020; white-space: nowrap; }
        .num { text-align: right; white-space: nowrap; }
        .small { color: #666; font-size: 13px; line-height: 1.6; }
        .strong { font-weight: bold; color: #b00020; }
        .ok { color: #067d17; font-weight: bold; }
        .ng { color: #b00020; font-weight: bold; }
        details { margin-top: 8px; }
        textarea { width: 100%; height: 240px; font-family: Consolas, Menlo, monospace; font-size: 12px; box-sizing: border-box; }
        code { background:#eee; padding:2px 4px; border-radius:3px; }
        @media (max-width: 760px) {
            body { margin: 8px; }
            .container { padding: 12px; }
            .search-box { display: block; }
            .search-box label { display:block; margin-top: 8px; font-weight:bold; }
            button, .button { width:100%; box-sizing:border-box; margin-top:8px; text-align:center; }
            table, thead, tbody, tr, th, td { display: block; }
            thead { display: none; }
            tr { border: 1px solid #ddd; margin-bottom: 12px; padding: 8px; }
            td { border: none; padding: 4px 0; }
            .num { text-align: left; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>NDL漫画雑誌候補抽出・登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="get" action="ndl_collection_extract.php">
        <input type="hidden" name="run" value="1">

        <div class="search-box">
            <label>対象年</label>
            <input type="number" name="year" value="<?= h($year) ?>" placeholder="例：1987">

            <label>キーワード</label>
            <input type="text" name="keyword" value="<?= h($keyword) ?>" placeholder="例：漫画 / マンガ">

            <label>タイトル絞込</label>
            <input type="text" name="title" value="<?= h($title) ?>" placeholder="例：フレッシュジャンプ">

            <label>出版社絞込</label>
            <input type="text" name="publisher" value="<?= h($publisher) ?>" placeholder="例：集英社">

            <label>開始位置</label>
            <input type="number" name="startRecord" value="<?= h($startRecord) ?>" min="1">

            <label>取得件数</label>
            <input type="number" name="maximumRecords" value="<?= h($maximumRecords) ?>" min="1" max="200">
        </div>

        <button type="submit">抽出する</button>
    </form>

    <div class="box small">
        NDL Search SRU APIで、<code>mediatype="periodicals"</code> とキーワード条件から候補を取得し、<code>dcndl:genre</code> が「漫画 / マンガ」のものだけを漫画雑誌候補にします。登録ボタンを押すと <code>external_magazine_collections</code> に <code>source_name=国立国会図書館</code> で登録します。<br>
        NDL版の「C ID相当」は、まず <code>parent_pid</code>、無ければ <code>parent_record_id</code> で確認します。<br>
        ※この版は正規表現の区切りをすべて <code>~...~</code> に統一しているため、<code>Unknown modifier ?</code> 対策済みです。
    </div>

    <?php if ($run): ?>
        <div class="message">
            抽出完了：SRU総件数 <?= h($totalHits !== '' ? $totalHits : '未取得') ?>件 / 解析レコード <?= h(count($records)) ?>件 / 雑誌候補 <?= h(count($groups)) ?>件
        </div>

        <div class="box">
            <strong>SRU条件</strong><br>
            CQL：<code><?= h($cql) ?></code><br>
            API URL：<a href="<?= h($sruUrl) ?>" target="_blank" rel="noopener">開く</a><br>
            HTTP：<?= h($result['http_code'] ?? '') ?> / Content-Type：<?= h($result['content_type'] ?? '') ?> / 秒：<?= h($result['total_time'] ?? '') ?><br>
            SRU総件数：<span class="strong"><?= h($totalHits !== '' ? $totalHits : '未取得') ?></span><br>
            解析レコード数：<span class="strong"><?= h(count($records)) ?></span><br>
            雑誌候補グループ数：<span class="strong"><?= h(count($groups)) ?></span>
        </div>

        <?php if (!empty($groups)): ?>
            <form method="post" action="ndl_collection_extract.php" style="margin-bottom:16px;">
                <input type="hidden" name="register" value="1">
                <input type="hidden" name="run" value="1">
                <input type="hidden" name="year" value="<?= h($year) ?>">
                <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
                <input type="hidden" name="title" value="<?= h($title) ?>">
                <input type="hidden" name="publisher" value="<?= h($publisher) ?>">
                <input type="hidden" name="startRecord" value="<?= h($startRecord) ?>">
                <input type="hidden" name="maximumRecords" value="<?= h($maximumRecords) ?>">
                <button type="submit" onclick="return confirm('表示中のNDL漫画雑誌候補を external_magazine_collections に登録しますか？');">
                    この内容で登録する
                </button>
                <span class="small">
                    source_name は <code>国立国会図書館</code>、external_collection_id は <code>NDL-PARENT-PID-xxxx</code> で登録します。
                </span>
            </form>
        <?php endif; ?>

        <p>
            <?php if ($startRecord > 1): ?>
                <a class="button secondary" href="<?= h(query_link(['startRecord' => $prevStart])) ?>">前へ</a>
            <?php endif; ?>
            <a class="button secondary" href="<?= h(query_link(['startRecord' => $nextStart])) ?>">次へ</a>
        </p>

        <?php if (!empty($groups)): ?>
            <h2>雑誌候補グループ</h2>
            <table>
                <thead>
                    <tr>
                        <th>NDL識別子候補</th>
                        <th>出版社</th>
                        <th>雑誌名</th>
                        <th>親pid</th>
                        <th>親record_id</th>
                        <th>対象号数</th>
                        <th>目次あり号数</th>
                        <th>刊行範囲</th>
                        <th>リンク</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td class="id"><?= h($g['source_id']) ?></td>
                        <td><?= h($g['publisher']) ?></td>
                        <td><?= h($g['title']) ?></td>
                        <td><?= h($g['parent_pid']) ?></td>
                        <td><?= h($g['parent_record_id']) ?></td>
                        <td class="num"><span class="strong"><?= h($g['issue_count']) ?></span></td>
                        <td class="num"><span class="strong"><?= h($g['toc_issue_count']) ?></span></td>
                        <td><?= h($g['volume_range']) ?></td>
                        <td>
                            <?php if ($g['parent_url'] !== ''): ?>
                                <a href="<?= h($g['parent_url']) ?>" target="_blank" rel="noopener">親pid</a><br>
                            <?php endif; ?>
                            <?php if ($g['ndlsearch_url'] !== ''): ?>
                                <a href="<?= h($g['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL Search</a>
                            <?php endif; ?>

                            <?php if (!empty($g['issues'])): ?>
                                <details>
                                    <summary>号を表示</summary>
                                    <ul>
                                        <?php foreach ($g['issues'] as $issue): ?>
                                            <li>
                                                <?= h($issue['issued']) ?>
                                                <?= h($issue['volume']) ?>
                                                pid: <?= h($issue['issue_pid']) ?>
                                                目次: <?= h((string)$issue['toc_count']) ?>件
                                                <?php if ($issue['ndlsearch_url'] !== ''): ?>
                                                    / <a href="<?= h($issue['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL</a>
                                                <?php endif; ?>
                                                <?php if ($issue['index_get_url'] !== ''): ?>
                                                    / <a href="<?= h($issue['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="ng">漫画雑誌候補が見つかりませんでした。</p>
        <?php endif; ?>

        <?php if (!empty($records)): ?>
            <h2>デバッグ：取得レコード一覧</h2>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>判定</th>
                        <th>record_id</th>
                        <th>タイトル</th>
                        <th>巻次</th>
                        <th>出版年月</th>
                        <th>出版社</th>
                        <th>各号pid</th>
                        <th>親pid</th>
                        <th>目次</th>
                        <th>ジャンル/種別</th>
                        <th>リンク</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="num"><?= h($startRecord + $i) ?></td>
                        <td class="small">
                            <?= $r['is_issue'] ? '<span class="ok">各号</span>' : '<span class="strong">親誌</span>' ?><br>
                            漫画：<?= $r['is_manga'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?><br>
                            雑誌：<?= $r['is_journal'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?>
                        </td>
                        <td class="id"><?= h($r['record_id']) ?></td>
                        <td><?= h($r['title']) ?></td>
                        <td>
                            <?= h($r['volume']) ?>
                            <?php if ($r['volume_transcription'] !== ''): ?>
                                <br><span class="small"><?= h($r['volume_transcription']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['issued']) ?></td>
                        <td><?= h($r['publisher']) ?></td>
                        <td><?= h($r['issue_pid']) ?></td>
                        <td><?= h($r['parent_pid']) ?></td>
                        <td class="num"><?= h((string)$r['toc_count']) ?></td>
                        <td class="small">
                            ジャンル：<?= h(implode(' / ', $r['genre_values'])) ?><br>
                            種別：<?= h(implode(' / ', $r['material_labels'])) ?>
                        </td>
                        <td>
                            <?php if ($r['ndlsearch_url'] !== ''): ?>
                                <a href="<?= h($r['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL</a><br>
                            <?php endif; ?>
                            <?php if ($r['dl_url'] !== ''): ?>
                                <a href="<?= h($r['dl_url']) ?>" target="_blank" rel="noopener">DL</a><br>
                            <?php endif; ?>
                            <?php if ($r['index_get_url'] !== ''): ?>
                                <a href="<?= h($r['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <details>
            <summary>SRUレスポンス本文を確認</summary>
            <textarea readonly><?= h($result['body'] ?? '') ?></textarea>
        </details>

    <?php endif; ?>

</div>
</body>
</html>
