<?php
// admin/ndl_import_year.php
// INDEXINDEX - NDL Search 年単位 雑誌号取込（雑誌号登録まで / 目次本登録は別画面）

declare(strict_types=1);

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

function fetch_url(string $url, int $timeout = 120): array {
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

function build_sru_url(string $title, string $year, int $startRecord, int $maximumRecords, string $publisher = ''): array {
    $conditions = [];

    // 資料種別：雑誌
    $conditions[] = 'mediatype=' . cql_quote('periodicals');

    if ($title !== '') {
        $conditions[] = 'title=' . cql_quote($title);
    }

    if ($publisher !== '') {
        $conditions[] = 'publisher=' . cql_quote($publisher);
    }

    if (preg_match('~^\d{4}$~', $year)) {
        $conditions[] = 'from=' . cql_quote($year);
        $conditions[] = 'until=' . cql_quote($year);
    }

    $cql = implode(' AND ', $conditions);

    $url = 'https://ndlsearch.ndl.go.jp/api/sru?' . http_build_query([
        'operation' => 'searchRetrieve',
        'version' => '1.2',
        'recordSchema' => 'dcndl',
        'recordPacking' => 'xml',
        'startRecord' => max(1, $startRecord),
        'maximumRecords' => max(1, min(200, $maximumRecords)),
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
        if (preg_match($pattern, $text, $m)) return $m[1];
    }
    return '';
}

function extract_parent_pid_from_resources(array $resources): string {
    foreach ($resources as $res) {
        if (preg_match('~dl\.ndl\.go\.jp/pid/(\d+)~u', $res, $m)) return $m[1];
        if (preg_match('~R100000039-I(\d+)~u', $res, $m)) return $m[1];
    }
    return '';
}

function extract_number_of_records(string $xml): string {
    if (preg_match('~<[^>]*numberOfRecords[^>]*>(\d+)</[^>]*numberOfRecords>~u', $xml, $m)) return $m[1];
    return '';
}

function parse_sru_records(string $xml): array {
    $records = [];
    if (trim($xml) === '') return $records;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();
    if (!$loaded) return $records;

    $xp = new DOMXPath($dom);
    $recordNodes = $xp->query('//*[local-name()="record" and .//*[local-name()="recordData"]]');
    if (!$recordNodes) return $records;

    foreach ($recordNodes as $recordNode) {
        $recordXml = $dom->saveXML($recordNode) ?: '';
        $adminAbouts = attr_values_by_localname($xp, $recordNode, './/*[local-name()="BibAdminResource"]', 'about');
        $materialNodes = $xp->query('.//*[local-name()="BibResource" and contains(@*[local-name()="about"], "#material")]', $recordNode);
        $material = ($materialNodes && $materialNodes->length > 0) ? $materialNodes->item(0) : $recordNode;

        $recordId = '';
        foreach (array_merge($adminAbouts, [$recordXml]) as $v) {
            $recordId = extract_record_id_from_text($v);
            if ($recordId !== '') break;
        }

        $isIssue = preg_match('~-d\d+$~u', $recordId) === 1;
        $parentRecordId = $isIssue ? (preg_replace('~-d\d+$~u', '', $recordId) ?? $recordId) : $recordId;

        $title = xpath_first_text($xp, $material, './*[local-name()="title" and namespace-uri()="http://purl.org/dc/terms/"][1]');
        if ($title === '') $title = xpath_first_text($xp, $material, './*[local-name()="title"]//*[local-name()="value"][1]');

        $publisher = xpath_first_text($xp, $material, './*[local-name()="publisher"]//*[local-name()="name"][1]');
        if ($publisher === '') $publisher = xpath_first_text($xp, $material, './/*[local-name()="publisher"][1]');

        $issued = xpath_first_text($xp, $material, './*[local-name()="issued" and contains(@*[local-name()="datatype"], "W3CDTF")][1]');
        if ($issued === '') $issued = xpath_first_text($xp, $material, './*[local-name()="issued"][1]');
        if ($issued === '') $issued = xpath_first_text($xp, $material, './*[local-name()="date"][1]');

        $volume = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="value"][1]');
        $volumeTranscription = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="transcription"][1]');
        $volumeRange = xpath_first_text($xp, $material, './*[local-name()="volumeRange"][1]');
        $tocTitles = xpath_texts($xp, $material, './/*[local-name()="tableOfContents"]//*[local-name()="title"]');

        $identifierTexts = xpath_texts($xp, $material, './/*[local-name()="identifier" or local-name()="sourceIdentifier"]');
        $seeAlsoResources = attr_values_resource($xp, $material, './/*[local-name()="seeAlso"]');
        $sourceResources = attr_values_resource($xp, $material, './/*[local-name()="source" or local-name()="isPartOf"]');
        $recordResources = attr_values_resource($xp, $recordNode, './/*[local-name()="record"]');
        $genreValues = xpath_texts($xp, $material, './/*[local-name()="genre"]//*[local-name()="value" or local-name()="transcription"]');
        $materialLabels = attr_values_by_localname($xp, $material, './/*[local-name()="materialType"]', 'label');
        $materialResources = attr_values_resource($xp, $material, './/*[local-name()="materialType"]');

        $issuePid = '';
        if ($isIssue && preg_match('~-d(\d+)$~u', $recordId, $m)) $issuePid = $m[1];
        if ($issuePid === '') {
            foreach (array_merge($identifierTexts, $seeAlsoResources, [$recordXml]) as $v) {
                $issuePid = extract_pid_from_text($v);
                if ($issuePid !== '') break;
            }
        }

        $parentPid = $isIssue ? extract_parent_pid_from_resources($sourceResources) : extract_parent_pid_from_resources($recordResources);

        $genreText = implode(' ', $genreValues);
        $materialText = implode(' ', array_merge($materialLabels, $materialResources));
        $isManga = (mb_stripos($genreText, '漫画') !== false || mb_stripos($genreText, 'マンガ') !== false);
        $isJournal = (mb_stripos($materialText, '雑誌') !== false || mb_stripos($materialText, 'Journal') !== false);

        $records[] = [
            'record_id' => $recordId,
            'parent_record_id' => $parentRecordId,
            'is_issue' => $isIssue,
            'title' => $title,
            'publisher' => $publisher,
            'issued' => $issued,
            'release_date' => ndl_date_to_mysql_date($issued),
            'volume' => $volume,
            'volume_transcription' => $volumeTranscription,
            'volume_range' => $volumeRange,
            'issue_pid' => $isIssue ? $issuePid : '',
            'parent_pid' => $parentPid,
            'toc_count' => count($tocTitles),
            'is_manga' => $isManga,
            'is_journal' => $isJournal,
            'ndlsearch_url' => $recordId !== '' ? 'https://ndlsearch.ndl.go.jp/books/' . rawurlencode($recordId) : '',
            'index_get_url' => ($isIssue && $recordId !== '') ? 'https://ndlsearch.ndl.go.jp/api/index/get/' . rawurlencode($recordId) . '?' : '',
            'dl_url' => $issuePid !== '' ? 'https://dl.ndl.go.jp/pid/' . rawurlencode($issuePid) : '',
            'genre_values' => $genreValues,
            'material_labels' => $materialLabels,
        ];
    }

    return $records;
}

function ndl_date_to_mysql_date(string $issued): ?string {
    $issued = trim($issued);
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $issued, $m)) return $m[1] . '-' . $m[2] . '-' . $m[3];
    if (preg_match('~^(\d{4})-(\d{2})$~', $issued, $m)) return $m[1] . '-' . $m[2] . '-01';
    if (preg_match('~^(\d{4})$~', $issued, $m)) return $m[1] . '-01-01';
    return null;
}

function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['Field']] = $row;
        }
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function first_existing_col(array $cols, array $candidates): string {
    foreach ($candidates as $c) {
        if (isset($cols[$c])) return $c;
    }
    return '';
}

function load_external_collection(PDO $pdo, string $collectionId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM external_magazine_collections WHERE source_name = '国立国会図書館' AND external_collection_id = :id LIMIT 1");
    $stmt->execute([':id' => $collectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function extract_parent_record_id_from_collection(array $collection): string {
    $note = (string)($collection['note'] ?? '');
    if (preg_match('~(R100000002-I[0-9A-Za-z]+)~u', $note, $m)) return $m[1];
    return '';
}

function extract_parent_pid_from_collection(array $collection): string {
    $id = (string)($collection['external_collection_id'] ?? '');
    $url = (string)($collection['external_collection_url'] ?? '');
    $note = (string)($collection['note'] ?? '');
    foreach ([$id, $url, $note] as $v) {
        if (preg_match('~(\d{4,})~u', $v, $m)) return $m[1];
    }
    return '';
}

function load_magazines(PDO $pdo): array {
    $cols = table_columns($pdo, 'magazines');
    if (empty($cols) || !isset($cols['id'])) return [];

    $nameCol = first_existing_col($cols, ['name', 'magazine_name', 'title']);
    $publisherTextCol = first_existing_col($cols, ['publisher', 'publisher_name']);
    $publisherIdCol = first_existing_col($cols, ['publisher_id']);

    $select = 'm.`id`';
    $select .= $nameCol !== '' ? ', m.`' . $nameCol . '` AS display_name' : ', m.`id` AS display_name';

    $join = '';
    if ($publisherTextCol !== '') {
        $select .= ', m.`' . $publisherTextCol . '` AS publisher_name';
    } elseif ($publisherIdCol !== '') {
        $publisherCols = table_columns($pdo, 'publishers');
        if (!empty($publisherCols) && isset($publisherCols['id'])) {
            $pubNameCol = first_existing_col($publisherCols, ['name', 'publisher_name', 'title']);
            if ($pubNameCol !== '') {
                $join = ' LEFT JOIN publishers p ON p.`id` = m.`' . $publisherIdCol . '`';
                $select .= ', p.`' . $pubNameCol . '` AS publisher_name';
            }
        }
    }

    $order = $nameCol !== '' ? 'm.`' . $nameCol . '`' : 'm.`id`';
    $stmt = $pdo->query("SELECT {$select} FROM magazines m{$join} ORDER BY {$order} LIMIT 2000");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_or_create_publisher(PDO $pdo, string $publisherName): ?int {
    $publisherName = clean_text($publisherName);

    $cols = table_columns($pdo, 'publishers');
    if (empty($cols) || !isset($cols['id'])) {
        return null;
    }

    $nameCol = first_existing_col($cols, ['name', 'publisher_name', 'title']);
    if ($nameCol === '') {
        return null;
    }

    if ($publisherName === '') {
        $publisherName = '不明';
    }

    $stmt = $pdo->prepare('SELECT `id` FROM `publishers` WHERE `' . $nameCol . '` = :name LIMIT 1');
    $stmt->execute([':name' => $publisherName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $data = [];
    $data[$nameCol] = $publisherName;

    $noteCol = first_existing_col($cols, ['note', 'notes', 'memo', 'remarks']);
    if ($noteCol !== '') {
        $data[$noteCol] = 'NDL取込時に自動作成';
    }

    if (isset($cols['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
    if (isset($cols['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

    $insertCols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $insertCols);
    $sql = 'INSERT INTO `publishers` (`' . implode('`,`', $insertCols) . '`) VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($data as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function guess_magazine_id(array $magazines, string $name): int {
    $name = clean_text($name);
    if ($name === '') return 0;
    foreach ($magazines as $m) {
        if (clean_text($m['display_name'] ?? '') === $name) return (int)$m['id'];
    }
    foreach ($magazines as $m) {
        if (mb_stripos(clean_text($m['display_name'] ?? ''), $name) !== false) return (int)$m['id'];
    }
    return 0;
}

function find_or_create_magazine(PDO $pdo, array $magazines, string $name, string $publisher): int {
    $name = clean_text($name);
    $publisher = clean_text($publisher);

    if ($name === '') {
        throw new Exception('内部雑誌を新規作成できません。雑誌名が空です。');
    }

    $existingId = guess_magazine_id($magazines, $name);
    if ($existingId > 0) {
        return $existingId;
    }

    $cols = table_columns($pdo, 'magazines');
    if (empty($cols)) {
        throw new Exception('magazines テーブルが見つかりません。');
    }

    $nameCol = first_existing_col($cols, ['name', 'magazine_name', 'title']);
    if ($nameCol === '') {
        throw new Exception('magazines に雑誌名カラム（name / magazine_name / title）が見つかりません。');
    }

    $data = [];
    $data[$nameCol] = $name;

    $publisherTextCol = first_existing_col($cols, ['publisher', 'publisher_name']);
    if ($publisherTextCol !== '') {
        $data[$publisherTextCol] = $publisher !== '' ? $publisher : null;
    } elseif (isset($cols['publisher_id'])) {
        $publisherId = find_or_create_publisher($pdo, $publisher);
        if ($publisherId === null || $publisherId <= 0) {
            throw new Exception('magazines.publisher_id が必須ですが、publishers テーブルから出版社IDを作成/取得できませんでした。publishers テーブル構造を確認してください。');
        }
        $data['publisher_id'] = $publisherId;
    }

    $noteCol = first_existing_col($cols, ['note', 'notes', 'memo', 'remarks']);
    if ($noteCol !== '') {
        $data[$noteCol] = 'NDL取込時に自動作成';
    }

    if (isset($cols['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
    if (isset($cols['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

    $insertCols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $insertCols);
    $sql = 'INSERT INTO `magazines` (`' . implode('`,`', $insertCols) . '`) VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($data as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function find_existing_issue(PDO $pdo, array $cols, int $magazineId, array $issue): ?array {
    $table = 'magazine_issues';
    $magCol = first_existing_col($cols, ['magazine_id']);
    $extCol = first_existing_col($cols, ['external_issue_id', 'external_id', 'source_record_id', 'ndl_record_id']);
    if ($extCol !== '' && $issue['record_id'] !== '') {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$extCol}` = :v LIMIT 1");
        $stmt->execute([':v' => $issue['record_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    $issueNoCol = first_existing_col($cols, ['issue_number', 'issue_no', 'number', 'volume', 'name', 'title']);
    $dateCol = first_existing_col($cols, ['release_date', 'published_date', 'issue_date', 'publication_date', 'date']);
    if ($magCol !== '' && $issueNoCol !== '' && $dateCol !== '') {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$magCol}` = :magazine_id AND `{$issueNoCol}` = :issue_no AND `{$dateCol}` = :release_date LIMIT 1");
        $stmt->execute([
            ':magazine_id' => $magazineId,
            ':issue_no' => $issue['volume'],
            ':release_date' => $issue['release_date'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    return null;
}

function insert_issue(PDO $pdo, int $magazineId, array $issue, array $collection): array {
    $table = 'magazine_issues';
    $cols = table_columns($pdo, $table);
    if (empty($cols)) throw new Exception('magazine_issues テーブルが見つかりません。');

    $magCol = first_existing_col($cols, ['magazine_id']);
    if ($magCol === '') throw new Exception('magazine_issues に magazine_id カラムが見つかりません。');

    $existing = find_existing_issue($pdo, $cols, $magazineId, $issue);
    if ($existing) {
        return ['status' => 'exists', 'id' => (int)($existing['id'] ?? 0)];
    }

    $data = [];
    $data[$magCol] = $magazineId;

    $issueValue = $issue['volume'] !== '' ? $issue['volume'] : $issue['issued'];

    $issueNoCol = first_existing_col($cols, ['issue_number', 'issue_no', 'number', 'volume', 'name', 'title']);
    if ($issueNoCol !== '') $data[$issueNoCol] = $issueValue;

    // INDEXINDEX本体では issue_label が NOT NULL の場合があるため、巻次を必ず入れる
    if (isset($cols['issue_label']) && !isset($data['issue_label'])) {
        $data['issue_label'] = $issueValue;
    }

    // 号数表示用の候補カラムにも、存在する場合は同じ値を入れておく
    foreach (['label', 'display_name', 'display_label'] as $labelCol) {
        if (isset($cols[$labelCol]) && !isset($data[$labelCol])) {
            $data[$labelCol] = $issueValue;
            break;
        }
    }

    $dateCol = first_existing_col($cols, ['release_date', 'published_date', 'issue_date', 'publication_date', 'date']);
    if ($dateCol !== '') $data[$dateCol] = $issue['release_date'];

    if (isset($cols['year']) && !isset($data['year'])) {
        $data['year'] = substr((string)$issue['release_date'], 0, 4);
    }
    if (isset($cols['issue_year']) && !isset($data['issue_year'])) {
        $data['issue_year'] = substr((string)$issue['release_date'], 0, 4);
    }
    if (isset($cols['month']) && !isset($data['month'])) {
        $data['month'] = substr((string)$issue['release_date'], 5, 2);
    }
    if (isset($cols['issue_month']) && !isset($data['issue_month'])) {
        $data['issue_month'] = substr((string)$issue['release_date'], 5, 2);
    }

    $extCol = first_existing_col($cols, ['external_issue_id', 'external_id', 'source_record_id', 'ndl_record_id']);
    if ($extCol !== '') $data[$extCol] = $issue['record_id'];

    $pidCol = first_existing_col($cols, ['external_issue_pid', 'external_pid', 'source_pid', 'ndl_pid']);
    if ($pidCol !== '') $data[$pidCol] = $issue['issue_pid'];

    $sourceCol = first_existing_col($cols, ['source_name', 'source']);
    if ($sourceCol !== '') $data[$sourceCol] = '国立国会図書館';

    $urlCol = first_existing_col($cols, ['external_issue_url', 'external_url', 'source_url', 'ndl_url']);
    if ($urlCol !== '') $data[$urlCol] = $issue['ndlsearch_url'];

    $noteCol = first_existing_col($cols, ['note', 'notes', 'memo', 'remarks']);
    if ($noteCol !== '') {
        $data[$noteCol] = 'NDLから雑誌号登録 / 親ID：' . ($collection['external_collection_id'] ?? '') .
            ' / 各号pid：' . $issue['issue_pid'] .
            ' / record_id：' . $issue['record_id'] .
            ' / 目次件数：' . $issue['toc_count'];
    }

    if (isset($cols['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
    if (isset($cols['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

    $insertCols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $insertCols);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $insertCols) . '`) VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($data as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);

    return ['status' => 'inserted', 'id' => (int)$pdo->lastInsertId()];
}

function filter_target_issues(array $records, string $parentPid, string $parentRecordId, string $year): array {
    $issues = [];
    foreach ($records as $r) {
        if (!$r['is_issue']) continue;
        if (!$r['is_journal']) continue;
        if ($parentPid !== '' && $r['parent_pid'] !== '' && $r['parent_pid'] !== $parentPid) continue;
        if ($parentRecordId !== '' && $r['parent_record_id'] !== $parentRecordId) continue;
        if ($year !== '' && $r['issued'] !== '' && !str_starts_with($r['issued'], $year)) continue;
        if ($r['record_id'] === '') continue;
        $issues[$r['record_id']] = $r;
    }
    uasort($issues, function($a, $b) {
        return [$a['issued'], $a['volume'], $a['record_id']] <=> [$b['issued'], $b['volume'], $b['record_id']];
    });
    return array_values($issues);
}

$collectionId = trim((string)($_GET['collection_id'] ?? $_GET['parent_id'] ?? $_POST['collection_id'] ?? $_POST['parent_id'] ?? ''));
$year = trim((string)($_GET['year'] ?? $_POST['year'] ?? date('Y')));
$startRecord = max(1, (int)($_GET['startRecord'] ?? $_POST['startRecord'] ?? 1));
$maximumRecords = max(1, min(200, (int)($_GET['maximumRecords'] ?? $_POST['maximumRecords'] ?? 100)));
$action = (string)($_POST['action'] ?? '');
$selectedMagazineId = (int)($_POST['magazine_id'] ?? $_GET['magazine_id'] ?? 0);
$createMagazine = isset($_POST['create_magazine']) && (string)$_POST['create_magazine'] === '1';

$error = '';
$message = '';
$collection = null;
$magazines = [];
$records = [];
$issues = [];
$result = null;
$totalHits = '';
$sruUrl = '';
$cql = '';
$parentRecordId = '';
$parentPid = '';

try {
    if ($collectionId !== '') {
        $collection = load_external_collection($pdo, $collectionId);
        if (!$collection) throw new Exception('external_magazine_collections に対象のNDL親IDが見つかりません：' . $collectionId);
        $parentRecordId = extract_parent_record_id_from_collection($collection);
        $parentPid = extract_parent_pid_from_collection($collection);
    }

    $magazines = load_magazines($pdo);
    if ($selectedMagazineId <= 0 && $collection) {
        $selectedMagazineId = guess_magazine_id($magazines, (string)($collection['magazine_name'] ?? ''));
    }

    if ($collection) {
        [$sruUrl, $cql] = build_sru_url((string)($collection['magazine_name'] ?? ''), $year, $startRecord, $maximumRecords, (string)($collection['publisher_name'] ?? ''));
        $result = fetch_url($sruUrl, 120);
        if (!$result['ok']) throw new Exception('SRU取得失敗 HTTP ' . $result['http_code'] . ' / errno ' . $result['errno'] . ' / ' . $result['error']);
        $totalHits = extract_number_of_records($result['body']);
        $records = parse_sru_records($result['body']);
        $issues = filter_target_issues($records, $parentPid, $parentRecordId, $year);
    }

    if ($action === 'register') {
        if (!$collection) throw new Exception('登録対象の親IDがありません。');
        if (empty($issues)) throw new Exception('登録対象の雑誌号がありません。');

        $pdo->beginTransaction();

        $createdMagazineName = '';
        if ($selectedMagazineId <= 0 && $createMagazine) {
            $selectedMagazineId = find_or_create_magazine(
                $pdo,
                $magazines,
                (string)($collection['magazine_name'] ?? ''),
                (string)($collection['publisher_name'] ?? '')
            );
            $createdMagazineName = (string)($collection['magazine_name'] ?? '');
            $magazines = load_magazines($pdo);
        }

        if ($selectedMagazineId <= 0) throw new Exception('内部雑誌IDを選択してください。未登録の場合は「内部雑誌を新規作成してから登録」にチェックしてください。');

        $inserted = 0;
        $exists = 0;
        $registered = [];
        foreach ($issues as $issue) {
            $res = insert_issue($pdo, $selectedMagazineId, $issue, $collection);
            if ($res['status'] === 'inserted') $inserted++;
            if ($res['status'] === 'exists') $exists++;
            $registered[] = $res + ['issue' => $issue];
        }
        $pdo->commit();
        $message = ($createdMagazineName !== '' ? '内部雑誌作成：' . $createdMagazineName . ' / ' : '') . '雑誌号登録完了：新規 ' . $inserted . '件 / 既存スキップ ' . $exists . '件';
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

function page_link(array $extra): string {
    global $collectionId, $year, $startRecord, $maximumRecords, $selectedMagazineId;
    $base = [
        'collection_id' => $collectionId,
        'year' => $year,
        'startRecord' => $startRecord,
        'maximumRecords' => $maximumRecords,
        'magazine_id' => $selectedMagazineId,
    ];
    return '?' . http_build_query(array_merge($base, $extra));
}

$prevStart = max(1, $startRecord - $maximumRecords);
$nextStart = $startRecord + $maximumRecords;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>NDL年別雑誌号取込 - INDEXINDEX</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; background: #f5f5f5; color: #222; }
        .container { max-width: 1320px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        h1 { margin-top: 0; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 12px; color: #0066cc; font-weight: bold; text-decoration: none; }
        .form-grid { display: grid; grid-template-columns: 160px 1fr 160px 1fr; gap: 10px; align-items: center; margin-bottom: 16px; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 9px; font-size: 15px; box-sizing: border-box; }
        button, .button { display: inline-block; padding: 9px 14px; font-size: 15px; border: 1px solid #333; background: #333; color: #fff; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .button.secondary, button.secondary { background: #666; border-color: #666; }
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
        code { background:#eee; padding:2px 4px; border-radius:3px; }
        textarea { width: 100%; height: 240px; font-family: Consolas, Menlo, monospace; font-size: 12px; box-sizing: border-box; }
        @media (max-width: 760px) {
            body { margin: 8px; }
            .container { padding: 12px; }
            .form-grid { display: block; }
            .form-grid label { display:block; margin-top: 8px; font-weight:bold; }
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

    <h1>NDL年別雑誌号取込</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="ndl_collection_extract.php">NDL雑誌候補抽出</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="get" action="ndl_import_year.php">
        <div class="form-grid">
            <label>NDL親ID</label>
            <input type="text" name="collection_id" value="<?= h($collectionId) ?>" placeholder="例：NDL-PARENT-PID-1869365">

            <label>対象年</label>
            <input type="number" name="year" value="<?= h($year) ?>" placeholder="例：1987">

            <label>開始位置</label>
            <input type="number" name="startRecord" value="<?= h($startRecord) ?>" min="1">

            <label>取得件数</label>
            <input type="number" name="maximumRecords" value="<?= h($maximumRecords) ?>" min="1" max="200">

            <label>内部雑誌</label>
            <select name="magazine_id">
                <option value="0">選択してください</option>
                <?php foreach ($magazines as $m): ?>
                    <option value="<?= h($m['id']) ?>" <?= (int)$m['id'] === $selectedMagazineId ? 'selected' : '' ?>>
                        <?= h($m['display_name'] ?? ('ID:' . $m['id'])) ?><?= isset($m['publisher_name']) && $m['publisher_name'] !== '' ? ' / ' . h($m['publisher_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div></div>
            <button type="submit">各号を取得する</button>
        </div>
    </form>

    <div class="box small">
        この画面は <code>external_magazine_collections</code> に登録済みのNDL親IDから、指定年の各号をSRUで取得し、<code>magazine_issues</code> に登録します。<br>
        目次本文はここでは登録しません。次の <code>ndl_import_confirm.php</code> で index/get JSON を貼り付けて登録します。<br>
        内部雑誌に未登録の場合は、登録時に「内部雑誌を新規作成してから登録」にチェックすると <code>magazines</code> に雑誌マスターを作成してから号を登録します。
    </div>

    <?php if ($collection): ?>
        <div class="box">
            <strong>対象NDL親情報</strong><br>
            NDL親ID：<span class="strong"><?= h($collection['external_collection_id'] ?? '') ?></span><br>
            雑誌名：<?= h($collection['magazine_name'] ?? '') ?><br>
            出版社：<?= h($collection['publisher_name'] ?? '') ?><br>
            親pid：<?= h($parentPid) ?><br>
            親record_id：<?= h($parentRecordId !== '' ? $parentRecordId : 'noteから未取得') ?><br>
            URL：<?php if (($collection['external_collection_url'] ?? '') !== ''): ?><a href="<?= h($collection['external_collection_url']) ?>" target="_blank" rel="noopener">開く</a><?php endif; ?>
        </div>

        <div class="box">
            <strong>SRU条件</strong><br>
            CQL：<code><?= h($cql) ?></code><br>
            API URL：<a href="<?= h($sruUrl) ?>" target="_blank" rel="noopener">開く</a><br>
            HTTP：<?= h($result['http_code'] ?? '') ?> / Content-Type：<?= h($result['content_type'] ?? '') ?> / 秒：<?= h($result['total_time'] ?? '') ?><br>
            SRU総件数：<span class="strong"><?= h($totalHits !== '' ? $totalHits : '未取得') ?></span><br>
            解析レコード数：<span class="strong"><?= h(count($records)) ?></span><br>
            登録対象号数：<span class="strong"><?= h(count($issues)) ?></span>
        </div>

        <p>
            <?php if ($startRecord > 1): ?>
                <a class="button secondary" href="<?= h(page_link(['startRecord' => $prevStart])) ?>">前へ</a>
            <?php endif; ?>
            <a class="button secondary" href="<?= h(page_link(['startRecord' => $nextStart])) ?>">次へ</a>
        </p>

        <?php if (!empty($issues)): ?>
            <form method="post" action="ndl_import_year.php">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="collection_id" value="<?= h($collectionId) ?>">
                <input type="hidden" name="year" value="<?= h($year) ?>">
                <input type="hidden" name="startRecord" value="<?= h($startRecord) ?>">
                <input type="hidden" name="maximumRecords" value="<?= h($maximumRecords) ?>">

                <div class="box">
                    <label><strong>登録先の内部雑誌</strong></label><br>
                    <select name="magazine_id" required>
                        <option value="0">選択してください</option>
                        <?php foreach ($magazines as $m): ?>
                            <option value="<?= h($m['id']) ?>" <?= (int)$m['id'] === $selectedMagazineId ? 'selected' : '' ?>>
                                <?= h($m['display_name'] ?? ('ID:' . $m['id'])) ?><?= isset($m['publisher_name']) && $m['publisher_name'] !== '' ? ' / ' . h($m['publisher_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small" style="margin-top:8px;">
                        内部雑誌に無い場合：
                        <label>
                            <input type="checkbox" name="create_magazine" value="1" <?= $selectedMagazineId <= 0 ? 'checked' : '' ?>>
                            内部雑誌を新規作成してから登録する
                        </label><br>
                        作成される雑誌名：<strong><?= h($collection['magazine_name'] ?? '') ?></strong>
                        <?= ($collection['publisher_name'] ?? '') !== '' ? ' / 出版社：' . h($collection['publisher_name']) : '' ?>
                    </div>
                    <br>
                    <button type="submit" onclick="return confirm('表示中の <?= h(count($issues)) ?> 件の雑誌号を magazine_issues に登録しますか？');">
                        この年の雑誌号を登録する
                    </button>
                </div>
            </form>

            <h2>登録対象の雑誌号</h2>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>出版年月</th>
                        <th>発売日登録値</th>
                        <th>巻次</th>
                        <th>各号pid</th>
                        <th>record_id</th>
                        <th>目次件数</th>
                        <th>リンク</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($issues as $i => $issue): ?>
                    <tr>
                        <td class="num"><?= h((string)($i + 1)) ?></td>
                        <td><?= h($issue['issued']) ?></td>
                        <td><?= h($issue['release_date'] ?? '') ?></td>
                        <td class="strong"><?= h($issue['volume']) ?></td>
                        <td><?= h($issue['issue_pid']) ?></td>
                        <td class="id"><?= h($issue['record_id']) ?></td>
                        <td class="num"><?= h((string)$issue['toc_count']) ?></td>
                        <td>
                            <?php if ($issue['ndlsearch_url'] !== ''): ?><a href="<?= h($issue['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL</a><br><?php endif; ?>
                            <?php if ($issue['dl_url'] !== ''): ?><a href="<?= h($issue['dl_url']) ?>" target="_blank" rel="noopener">DL</a><br><?php endif; ?>
                            <?php if ($issue['index_get_url'] !== ''): ?><a href="<?= h($issue['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a><br><?php endif; ?>
                            <a href="ndl_import_confirm.php?record_id=<?= h(rawurlencode($issue['record_id'])) ?>&pid=<?= h(rawurlencode($issue['issue_pid'])) ?>" target="_blank" rel="noopener">目次確認へ</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="ng">登録対象の各号が見つかりませんでした。</p>
            <p class="small">取得件数を増やす、次へを押す、またはNDL親IDのnoteに親record_idが入っているか確認してください。</p>
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
                        <th>リンク</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="num"><?= h((string)($startRecord + $i)) ?></td>
                        <td class="small">
                            <?= $r['is_issue'] ? '<span class="ok">各号</span>' : '<span class="strong">親誌</span>' ?><br>
                            漫画：<?= $r['is_manga'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?><br>
                            雑誌：<?= $r['is_journal'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?>
                        </td>
                        <td class="id"><?= h($r['record_id']) ?></td>
                        <td><?= h($r['title']) ?></td>
                        <td><?= h($r['volume']) ?><br><span class="small"><?= h($r['volume_transcription']) ?></span></td>
                        <td><?= h($r['issued']) ?></td>
                        <td><?= h($r['publisher']) ?></td>
                        <td><?= h($r['issue_pid']) ?></td>
                        <td><?= h($r['parent_pid']) ?></td>
                        <td class="num"><?= h((string)$r['toc_count']) ?></td>
                        <td>
                            <?php if ($r['ndlsearch_url'] !== ''): ?><a href="<?= h($r['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL</a><br><?php endif; ?>
                            <?php if ($r['index_get_url'] !== ''): ?><a href="<?= h($r['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a><?php endif; ?>
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
