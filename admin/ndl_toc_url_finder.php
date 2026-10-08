<?php
// admin/ndl_toc_url_finder.php
// INDEXINDEX - NDL「漫画/マンガ(001347325)」かつ目次あり資料URL抽出ツール
// DB登録なし。SRUで検索し、目次ありのNDL Search URL / index/get URLへ飛ぶだけの確認用。

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text($value): string {
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~[\r\n\t]+~u', ' ', $value);
    $value = preg_replace('~[ 　]+~u', ' ', $value);
    return trim((string)$value);
}

function cql_quote(string $s): string {
    $s = trim($s);
    $s = str_replace('"', '\\"', $s);
    return '"' . $s . '"';
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

function build_sru_url(array $params): array {
    $year = trim((string)($params['year'] ?? ''));
    $keyword = trim((string)($params['keyword'] ?? ''));
    $title = trim((string)($params['title'] ?? ''));
    $publisher = trim((string)($params['publisher'] ?? ''));
    $startRecord = max(1, (int)($params['startRecord'] ?? 1));
    $maximumRecords = (int)($params['maximumRecords'] ?? 50);
    if ($maximumRecords < 1) $maximumRecords = 50;
    if ($maximumRecords > 200) $maximumRecords = 200;

    $conditions = [];

    // 資料種別：雑誌
    $conditions[] = 'mediatype=' . cql_quote('periodicals');

    // 任意キーワード。空欄でもOK。
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
    $values = xpath_texts($xp, $ctx, $query);
    return $values[0] ?? '';
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

function extract_parent_pid_from_resources(array $resources): string {
    foreach ($resources as $res) {
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
        if ($title === '') {
            $title = xpath_first_text($xp, $material, './*[local-name()="title"]//*[local-name()="value"][1]');
        }

        $publisher = xpath_first_text($xp, $material, './*[local-name()="publisher"]//*[local-name()="name"][1]');
        if ($publisher === '') {
            $publisher = xpath_first_text($xp, $material, './/*[local-name()="publisher"][1]');
        }

        $issued = xpath_first_text($xp, $material, './*[local-name()="issued" and contains(@*[local-name()="datatype"], "W3CDTF")][1]');
        if ($issued === '') $issued = xpath_first_text($xp, $material, './*[local-name()="issued"][1]');
        if ($issued === '') $issued = xpath_first_text($xp, $material, './*[local-name()="date"][1]');

        $volume = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="value"][1]');
        $volumeTranscription = xpath_first_text($xp, $material, './*[local-name()="volume"]//*[local-name()="transcription"][1]');

        $genreValues = xpath_texts($xp, $material, './/*[local-name()="genre"]//*[local-name()="value" or local-name()="transcription"]');
        $genreAbouts = attr_values_by_localname($xp, $material, './/*[local-name()="genre"]//*[local-name()="Description"]', 'about');
        $materialLabels = attr_values_by_localname($xp, $material, './/*[local-name()="materialType"]', 'label');
        $materialResources = attr_values_resource($xp, $material, './/*[local-name()="materialType"]');

        $tocTitles = xpath_texts($xp, $material, './/*[local-name()="tableOfContents"]//*[local-name()="title"]');
        $tocCount = count($tocTitles);

        $identifierTexts = xpath_texts($xp, $material, './/*[local-name()="identifier" or local-name()="sourceIdentifier"]');
        $sourceResources = attr_values_resource($xp, $material, './/*[local-name()="source" or local-name()="isPartOf"]');
        $recordResources = attr_values_resource($xp, $recordNode, './/*[local-name()="record"]');

        $issuePid = '';
        if ($isIssue && preg_match('~-d(\d+)$~u', $recordId, $m)) {
            $issuePid = $m[1];
        }
        if ($issuePid === '') {
            foreach (array_merge($identifierTexts, [$recordXml]) as $v) {
                $issuePid = extract_pid_from_text($v);
                if ($issuePid !== '') break;
            }
        }

        $parentPid = '';
        if ($isIssue) {
            $parentPid = extract_parent_pid_from_resources($sourceResources);
        } else {
            $parentPid = extract_parent_pid_from_resources($recordResources);
        }

        $genreText = implode(' ', $genreValues);
        $genreAboutText = implode(' ', $genreAbouts);
        $materialText = implode(' ', array_merge($materialLabels, $materialResources));

        // 重要: 目次本文や説明文ではなく、dcndl:genre だけで漫画判定する。
        $isMangaGenre = (
            in_array('漫画', $genreValues, true) ||
            in_array('マンガ', $genreValues, true) ||
            str_contains($genreAboutText, '001347325')
        );

        $isJournal = (
            mb_stripos($materialText, '雑誌') !== false ||
            mb_stripos($materialText, 'Journal') !== false
        );

        $hasToc = $tocCount > 0;

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
            'issue_pid' => $isIssue ? $issuePid : '',
            'parent_pid' => $parentPid,
            'genre_values' => $genreValues,
            'genre_abouts' => $genreAbouts,
            'material_labels' => $materialLabels,
            'toc_count' => $tocCount,
            'is_manga_genre' => $isMangaGenre,
            'is_journal' => $isJournal,
            'has_toc' => $hasToc,
            'ndlsearch_url' => $ndlSearchUrl,
            'index_get_url' => $indexGetUrl,
            'dl_url' => $dlUrl,
            'parent_url' => $parentUrl,
        ];
    }

    return $records;
}

function extract_number_of_records(string $xml): string {
    if (preg_match('~<[^>]*numberOfRecords[^>]*>(\d+)</[^>]*numberOfRecords>~u', $xml, $m)) {
        return $m[1];
    }
    return '';
}

$year = isset($_GET['year']) ? trim((string)$_GET['year']) : '1987';
$keyword = isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '漫画';
$title = isset($_GET['title']) ? trim((string)$_GET['title']) : '';
$publisher = isset($_GET['publisher']) ? trim((string)$_GET['publisher']) : '';
$startRecord = isset($_GET['startRecord']) ? (int)$_GET['startRecord'] : 1;
$maximumRecords = isset($_GET['maximumRecords']) ? (int)$_GET['maximumRecords'] : 50;
$run = isset($_GET['run']);

$error = '';
$result = null;
$records = [];
$matched = [];
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

        foreach ($records as $r) {
            if ($r['is_manga_genre'] && $r['is_journal'] && $r['has_toc']) {
                $matched[] = $r;
            }
        }
    } catch (Exception $e) {
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
    <title>NDL漫画ジャンル目次URL抽出 - INDEXINDEX</title>
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

    <h1>NDL漫画ジャンル目次URL抽出</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="ndl_collection_extract.php">NDL雑誌候補抽出</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="get" action="ndl_toc_url_finder.php">
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
        条件：<code>資料種別=雑誌</code> かつ <code>dcndl:genre=漫画/マンガ/001347325</code> かつ <code>dcterms:tableOfContentsあり</code> のレコードだけ表示します。<br>
        DB登録はしません。NDL Search / index/get / デジコレURLへ飛ぶための確認用です。
    </div>

    <?php if ($run): ?>
        <div class="message">
            抽出完了：SRU総件数 <?= h($totalHits !== '' ? $totalHits : '未取得') ?>件 / 解析レコード <?= h(count($records)) ?>件 / 条件一致 <?= h(count($matched)) ?>件
        </div>

        <div class="box">
            <strong>SRU条件</strong><br>
            CQL：<code><?= h($cql) ?></code><br>
            API URL：<a href="<?= h($sruUrl) ?>" target="_blank" rel="noopener">開く</a><br>
            HTTP：<?= h($result['http_code'] ?? '') ?> / Content-Type：<?= h($result['content_type'] ?? '') ?> / 秒：<?= h($result['total_time'] ?? '') ?><br>
            SRU総件数：<span class="strong"><?= h($totalHits !== '' ? $totalHits : '未取得') ?></span><br>
            条件一致：<span class="strong"><?= h(count($matched)) ?></span>
        </div>

        <p>
            <?php if ($startRecord > 1): ?>
                <a class="button secondary" href="<?= h(query_link(['startRecord' => $prevStart])) ?>">前へ</a>
            <?php endif; ?>
            <a class="button secondary" href="<?= h(query_link(['startRecord' => $nextStart])) ?>">次へ</a>
        </p>

        <?php if (!empty($matched)): ?>
            <h2>目次あり漫画雑誌URL</h2>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>出版社</th>
                        <th>雑誌名</th>
                        <th>巻次</th>
                        <th>出版年月</th>
                        <th>各号pid</th>
                        <th>親pid</th>
                        <th>目次件数</th>
                        <th>リンク</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matched as $i => $r): ?>
                    <tr>
                        <td class="num"><?= h($startRecord + $i) ?></td>
                        <td><?= h($r['publisher']) ?></td>
                        <td><?= h($r['title']) ?></td>
                        <td>
                            <?= h($r['volume']) ?>
                            <?php if ($r['volume_transcription'] !== ''): ?>
                                <br><span class="small"><?= h($r['volume_transcription']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['issued']) ?></td>
                        <td class="id"><?= h($r['issue_pid']) ?></td>
                        <td><?= h($r['parent_pid']) ?></td>
                        <td class="num"><span class="strong"><?= h((string)$r['toc_count']) ?></span></td>
                        <td>
                            <?php if ($r['ndlsearch_url'] !== ''): ?>
                                <a href="<?= h($r['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL Search</a><br>
                            <?php endif; ?>
                            <?php if ($r['index_get_url'] !== ''): ?>
                                <a href="<?= h($r['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a><br>
                            <?php endif; ?>
                            <?php if ($r['dl_url'] !== ''): ?>
                                <a href="<?= h($r['dl_url']) ?>" target="_blank" rel="noopener">デジコレ</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="ng">条件に一致する目次あり漫画雑誌は見つかりませんでした。</p>
        <?php endif; ?>

        <?php if (!empty($records)): ?>
            <details>
                <summary>デバッグ：解析レコード一覧</summary>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>一致</th>
                            <th>record_id</th>
                            <th>タイトル</th>
                            <th>巻次</th>
                            <th>出版年月</th>
                            <th>出版社</th>
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
                                漫画ジャンル：<?= $r['is_manga_genre'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?><br>
                                雑誌：<?= $r['is_journal'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?><br>
                                目次：<?= $r['has_toc'] ? '<span class="ok">YES</span>' : '<span class="ng">NO</span>' ?>
                            </td>
                            <td class="id"><?= h($r['record_id']) ?></td>
                            <td><?= h($r['title']) ?></td>
                            <td><?= h($r['volume']) ?></td>
                            <td><?= h($r['issued']) ?></td>
                            <td><?= h($r['publisher']) ?></td>
                            <td class="num"><?= h((string)$r['toc_count']) ?></td>
                            <td class="small">
                                ジャンル：<?= h(implode(' / ', $r['genre_values'])) ?><br>
                                ID：<?= h(implode(' / ', $r['genre_abouts'])) ?><br>
                                種別：<?= h(implode(' / ', $r['material_labels'])) ?>
                            </td>
                            <td>
                                <?php if ($r['ndlsearch_url'] !== ''): ?>
                                    <a href="<?= h($r['ndlsearch_url']) ?>" target="_blank" rel="noopener">NDL</a><br>
                                <?php endif; ?>
                                <?php if ($r['index_get_url'] !== ''): ?>
                                    <a href="<?= h($r['index_get_url']) ?>" target="_blank" rel="noopener">index/get</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <details>
            <summary>SRUレスポンス本文を確認</summary>
            <textarea readonly><?= h($result['body'] ?? '') ?></textarea>
        </details>
    <?php endif; ?>

</div>
</body>
</html>
