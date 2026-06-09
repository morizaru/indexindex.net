<?php
declare(strict_types=1);

/**
 * INDEXINDEX - NDL Search TOC Test
 * admin/ndl_test.php
 *
 * 目的:
 * - NDL Search URL または pid を入力
 * - NDL Search のHTMLページを直接取得
 * - 画面上に表示されている目次を抽出
 * - api/index/get から全件目次候補の取得を試す
 * - HTML取得時のCookieを使って index/get API へアクセスする
 * - 参考としてSRU APIも取得
 *
 * 注意:
 * - このファイルはDB登録しません
 */

mb_internal_encoding('UTF-8');

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function extract_ndl_ids(string $input): array
{
    $input = trim($input);

    $ids = [
        'input' => $input,
        'pid' => '',
        'record_id' => '',
        'book_url' => '',
    ];

    if (preg_match('/-d(\d+)(?:\D|$)/', $input, $m)) {
        $ids['pid'] = $m[1];
    }

    if (preg_match('/(R\d+-I[0-9A-Za-z]+(?:-d\d+)?)/', $input, $m)) {
        $ids['record_id'] = $m[1];
    }

    if ($ids['pid'] === '' && preg_match('/^\d+$/', $input)) {
        $ids['pid'] = $input;
    }

    if ($ids['record_id'] !== '') {
        $ids['book_url'] = 'https://ndlsearch.ndl.go.jp/books/' . $ids['record_id'];
    } elseif ($ids['pid'] !== '') {
        $ids['book_url'] = 'https://ndlsearch.ndl.go.jp/search?cs=bib&from=0&size=20&q-pid=' . rawurlencode($ids['pid']);
    } elseif (preg_match('#^https?://#', $input)) {
        $ids['book_url'] = $input;
    }

    return $ids;
}

function fetch_url(string $url, int $timeout = 60, string $referer = '', string $cookieFile = ''): array
{
    $ch = curl_init($url);

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/xml;q=0.8,application/json;q=0.8,*/*;q=0.7',
        'Accept-Language: ja,en-US;q=0.8,en;q=0.6',
        'Connection: close',
    ];

    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,

        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,

        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '',

        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',

        CURLOPT_HTTPHEADER => $headers,

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($cookieFile !== '') {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    $totalTime = (string)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $namelookupTime = (string)curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
    $connectTime = (string)curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    $starttransferTime = (string)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $redirectCount = (string)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    return [
        'ok' => ($errno === 0 && $httpCode >= 200 && $httpCode < 300 && is_string($body)),
        'url' => $url,
        'effective_url' => $effectiveUrl,
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'errno' => $errno,
        'error' => $error,
        'total_time' => $totalTime,
        'namelookup_time' => $namelookupTime,
        'connect_time' => $connectTime,
        'starttransfer_time' => $starttransferTime,
        'primary_ip' => $primaryIp,
        'redirect_count' => $redirectCount,
        'body' => is_string($body) ? $body : '',
    ];
}

function text_clean(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t　]+/u', ' ', $s);
    $s = preg_replace('/\r\n|\r/u', "\n", $s);
    $s = preg_replace('/\n{3,}/u', "\n\n", $s);
    return trim((string)$s);
}

function html_to_text_lines(string $html): array
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#isu', '', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#isu', '', $html);

    $html = preg_replace('#</(li|p|div|section|article|h1|h2|h3|h4|tr|td|th)>#iu', "\n", $html);
    $html = preg_replace('#<br\s*/?>#iu', "\n", $html);

    $text = strip_tags($html);
    $text = text_clean($text);

    $lines = preg_split('/\n/u', $text) ?: [];

    $out = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[ \t　]+/u', ' ', $line));
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

function parse_ndl_html_basic(array $lines): array
{
    $meta = [
        'title' => '',
        'pid' => '',
        'publisher' => '',
        'issued' => '',
        'call_number' => '',
    ];

    foreach ($lines as $i => $line) {
        if ($meta['title'] === '' && preg_match('/^フレッシュジャンプ|^.+\s+\d+\(\d+\)/u', $line)) {
            $meta['title'] = $line;
        }

        if ($meta['pid'] === '' && preg_match('/info:ndljp\/pid\/(\d+)/u', $line, $m)) {
            $meta['pid'] = $m[1];
        }

        if ($line === '出版者' && isset($lines[$i + 1])) {
            $meta['publisher'] = $lines[$i + 1];
        }

        if (($line === '出版年' || $line === '出版年月日等') && isset($lines[$i + 1])) {
            $meta['issued'] = $lines[$i + 1];
        }

        if ($line === '国立国会図書館請求記号' && isset($lines[$i + 1])) {
            $meta['call_number'] = $lines[$i + 1];
        }
    }

    return $meta;
}

function parse_toc_from_ndl_html(string $html): array
{
    $lines = html_to_text_lines($html);

    $start = null;
    $end = null;

    foreach ($lines as $i => $line) {
        if ($line === '目次') {
            $start = $i;
            break;
        }
    }

    if ($start === null) {
        return [];
    }

    for ($i = $start + 1; $i < count($lines); $i++) {
        if (
            $lines[$i] === '書誌情報' ||
            $lines[$i] === '書店で探す' ||
            str_starts_with($lines[$i], '## 書誌情報')
        ) {
            $end = $i;
            break;
        }
    }

    if ($end === null) {
        $end = count($lines);
    }

    $tocLines = array_slice($lines, $start + 1, $end - $start - 1);

    $items = [];
    $pendingTitle = '';

    foreach ($tocLines as $line) {
        if (
            $line === '提供元：国立国会図書館デジタルコレクション' ||
            $line === 'キーワードで目次内を検索' ||
            str_starts_with($line, 'もっと見る') ||
            str_contains($line, 'ヘルプページへのリンク')
        ) {
            continue;
        }

        if (preg_match('/^(.*?)\/{1,2}([0-9]{1,4})$/u', $line, $m)) {
            $creator = trim($m[1]);
            $page = trim($m[2]);

            if ($pendingTitle === '' && str_contains($line, '//')) {
                $titleOnly = trim(preg_replace('/\/{2}[0-9]{1,4}$/u', '', $line));

                $items[] = [
                    'page' => $page,
                    'title' => $titleOnly,
                    'creator' => '',
                    'raw' => $line,
                ];

                continue;
            }

            if ($pendingTitle !== '') {
                $items[] = [
                    'page' => $page,
                    'title' => $pendingTitle,
                    'creator' => $creator,
                    'raw' => $pendingTitle . ' / ' . $line,
                ];
                $pendingTitle = '';
            } else {
                $items[] = [
                    'page' => $page,
                    'title' => '',
                    'creator' => $creator,
                    'raw' => $line,
                ];
            }

            continue;
        }

        if ($pendingTitle === '') {
            $pendingTitle = $line;
        } else {
            $pendingTitle .= ' ' . $line;
        }
    }

    if ($pendingTitle !== '') {
        $items[] = [
            'page' => '',
            'title' => $pendingTitle,
            'creator' => '',
            'raw' => $pendingTitle,
        ];
    }

    return $items;
}

function build_sru_url_by_pid(string $pid): string
{
    return 'https://ndlsearch.ndl.go.jp/api/sru?' . http_build_query([
        'operation' => 'searchRetrieve',
        'recordSchema' => 'dcndl',
        'recordPacking' => 'xml',
        'maximumRecords' => 10,
        'query' => 'identifier="info:ndljp/pid/' . $pid . '"',
    ], '', '&', PHP_QUERY_RFC3986);
}

function build_index_get_url(string $recordId): string
{
    return 'https://ndlsearch.ndl.go.jp/api/index/get/' . rawurlencode($recordId);
}

function normalize_index_text(?string $s): string
{
    $s = (string)$s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t　]+/u', ' ', $s);
    $s = preg_replace('/\r\n|\r/u', "\n", $s);
    return trim((string)$s);
}

function first_scalar_value(array $arr, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $arr) && is_scalar($arr[$key])) {
            $v = normalize_index_text((string)$arr[$key]);
            if ($v !== '') {
                return $v;
            }
        }
    }

    return '';
}

function find_value_recursive($data, array $keys): string
{
    if (!is_array($data)) {
        return '';
    }

    $direct = first_scalar_value($data, $keys);
    if ($direct !== '') {
        return $direct;
    }

    foreach ($data as $v) {
        if (is_array($v)) {
            $found = find_value_recursive($v, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function parse_line_toc_item(string $line): ?array
{
    $line = normalize_index_text($line);

    if ($line === '') {
        return null;
    }

    if (preg_match('/^(.*?)\s*\/\s*(.*?)\s*\/\s*([0-9]{1,4})$/u', $line, $m)) {
        $title = normalize_index_text($m[1]);
        $creator = normalize_index_text($m[2]);
        $page = normalize_index_text($m[3]);

        return [
            'page' => $page,
            'title' => $title,
            'creator' => $creator,
            'raw' => $line,
        ];
    }

    if (preg_match('/^(.*?)\/{1,2}([0-9]{1,4})$/u', $line, $m)) {
        $creator = normalize_index_text($m[1]);
        $page = normalize_index_text($m[2]);

        if (str_contains($line, '//')) {
            $title = normalize_index_text(preg_replace('/\/{2}[0-9]{1,4}$/u', '', $line));

            return [
                'page' => $page,
                'title' => $title,
                'creator' => '',
                'raw' => $line,
            ];
        }

        return [
            'page' => $page,
            'title' => '',
            'creator' => $creator,
            'raw' => $line,
        ];
    }

    return null;
}

function looks_like_toc_item(array $arr): bool
{
    $title = find_value_recursive($arr, [
        'title',
        'partTitle',
        'indexTitle',
        'heading',
        'label',
        'name',
        'content',
        'value',
        'text',
    ]);

    $creator = find_value_recursive($arr, [
        'creator',
        'author',
        'partCreator',
        'responsibility',
        'responsibilityStatement',
        'writer',
    ]);

    $page = find_value_recursive($arr, [
        'page',
        'pages',
        'pageRange',
        'page_range',
        'startPage',
        'start_page',
        'position',
    ]);

    if ($title !== '' && $page !== '') {
        return true;
    }

    if ($title !== '' && $creator !== '') {
        return true;
    }

    $joined = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($joined) && preg_match('/\/{1,2}[0-9]{1,4}/u', $joined)) {
        return true;
    }

    return false;
}

function parse_index_api_items(string $body): array
{
    $items = [];

    $body = trim($body);
    if ($body === '') {
        return $items;
    }

    $json = json_decode($body, true);

    if (!is_array($json)) {
        $lines = preg_split('/\r\n|\r|\n/u', strip_tags($body)) ?: [];

        foreach ($lines as $line) {
            $item = parse_line_toc_item($line);
            if ($item) {
                $items[] = $item;
            }
        }

        return dedupe_toc_items($items);
    }

    $stack = [$json];

    while ($stack) {
        $current = array_pop($stack);

        if (!is_array($current)) {
            continue;
        }

        if (looks_like_toc_item($current)) {
            $title = find_value_recursive($current, [
                'title',
                'partTitle',
                'indexTitle',
                'heading',
                'label',
                'name',
                'content',
                'value',
                'text',
            ]);

            $creator = find_value_recursive($current, [
                'creator',
                'author',
                'partCreator',
                'responsibility',
                'responsibilityStatement',
                'writer',
            ]);

            $page = find_value_recursive($current, [
                'page',
                'pages',
                'pageRange',
                'page_range',
                'startPage',
                'start_page',
                'position',
            ]);

            $rawJson = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $raw = is_string($rawJson) ? $rawJson : '';

            $rawText = preg_replace('/[{}\[\]":,]+/u', ' ', $raw);
            $rawText = normalize_index_text((string)$rawText);

            $lineItem = parse_line_toc_item($rawText);
            if ($lineItem) {
                if ($title === '' && $lineItem['title'] !== '') {
                    $title = $lineItem['title'];
                }
                if ($creator === '' && $lineItem['creator'] !== '') {
                    $creator = $lineItem['creator'];
                }
                if ($page === '' && $lineItem['page'] !== '') {
                    $page = $lineItem['page'];
                }
            }

            if ($title !== '' || $creator !== '' || $page !== '') {
                $items[] = [
                    'page' => $page,
                    'title' => $title,
                    'creator' => $creator,
                    'raw' => $raw,
                ];
            }
        }

        foreach ($current as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            }
        }
    }

    return dedupe_toc_items($items);
}

function dedupe_toc_items(array $items): array
{
    $seen = [];
    $out = [];

    foreach ($items as $item) {
        $page = normalize_index_text($item['page'] ?? '');
        $title = normalize_index_text($item['title'] ?? '');
        $creator = normalize_index_text($item['creator'] ?? '');
        $raw = normalize_index_text($item['raw'] ?? '');

        if ($title === '' && $creator === '' && $page === '') {
            continue;
        }

        $key = md5($page . '|' . $title . '|' . $creator);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        $out[] = [
            'page' => $page,
            'title' => $title,
            'creator' => $creator,
            'raw' => $raw,
        ];
    }

    usort($out, function (array $a, array $b): int {
        $pa = (int)($a['page'] ?? 0);
        $pb = (int)($b['page'] ?? 0);

        if ($pa === 0 && $pb === 0) {
            return 0;
        }
        if ($pa === 0) {
            return 1;
        }
        if ($pb === 0) {
            return -1;
        }

        return $pa <=> $pb;
    });

    return $out;
}

$input = $_POST['ndl_url'] ?? $_GET['url'] ?? '';
$ids = extract_ndl_ids((string)$input);

$htmlResult = null;
$htmlToc = [];
$htmlLines = [];
$htmlMeta = [];

$indexResult = null;
$indexToc = [];

$sruResult = null;

$cookieFile = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['url'])) {
    $cookieFile = sys_get_temp_dir() . '/ndl_cookie_' . md5(uniqid('', true)) . '.txt';

    if ($ids['book_url'] !== '') {
        $htmlResult = fetch_url($ids['book_url'], 60, '', $cookieFile);

        if ($htmlResult['ok']) {
            $htmlToc = parse_toc_from_ndl_html($htmlResult['body']);
            $htmlLines = html_to_text_lines($htmlResult['body']);
            $htmlMeta = parse_ndl_html_basic($htmlLines);
        }
    }

    if ($ids['record_id'] !== '') {
        $indexUrl = build_index_get_url($ids['record_id']);

        $referer = $ids['book_url'] !== '' ? $ids['book_url'] : 'https://ndlsearch.ndl.go.jp/';

        $indexResult = fetch_url($indexUrl, 60, $referer, $cookieFile);

        if ($indexResult['ok']) {
            $indexToc = parse_index_api_items($indexResult['body']);
        }
    }

    if ($ids['pid'] !== '') {
        $sruUrl = build_sru_url_by_pid($ids['pid']);
        $sruResult = fetch_url($sruUrl, 60, '', $cookieFile);
    }

    if ($cookieFile !== '' && is_file($cookieFile)) {
        @unlink($cookieFile);
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>NDL目次取得テスト | INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.7;
            margin: 24px;
            color: #222;
            background: #f7f7f7;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 24px;
        }
        h2 {
            margin-top: 28px;
            font-size: 20px;
        }
        h3 {
            margin-top: 22px;
            font-size: 17px;
        }
        .box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 18px;
        }
        input[type="text"] {
            width: 100%;
            max-width: 980px;
            padding: 10px;
            font-size: 15px;
            box-sizing: border-box;
        }
        button {
            padding: 9px 18px;
            cursor: pointer;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            vertical-align: top;
        }
        th {
            background: #eee;
            text-align: left;
            white-space: nowrap;
        }
        .ok {
            color: #067d17;
            font-weight: bold;
        }
        .ng {
            color: #b00020;
            font-weight: bold;
        }
        .small {
            color: #666;
            font-size: 13px;
        }
        textarea {
            width: 100%;
            height: 300px;
            font-family: Consolas, Menlo, monospace;
            font-size: 12px;
            box-sizing: border-box;
        }
        code {
            background: #f0f0f0;
            padding: 2px 4px;
            border-radius: 4px;
        }
        .raw {
            color: #666;
            font-size: 12px;
            max-width: 520px;
            word-break: break-all;
        }
    </style>
</head>
<body>

<h1>NDL目次取得テスト</h1>

<div class="box">
    <form method="post">
        <p>NDL Search URL または pid を入力してください。</p>

        <input
            type="text"
            name="ndl_url"
            value="<?= h((string)$input) ?>"
            placeholder="例：https://ndlsearch.ndl.go.jp/books/R100000002-I000000039263-d1831410"
        >

        <p>
            <button type="submit">取得テスト</button>
        </p>

        <p class="small">
            ※この画面ではDB登録しません。NDLサーチ画面HTML・index/get API・SRU APIを確認します。
        </p>
    </form>
</div>

<?php if ($input !== ''): ?>

<div class="box">
    <h2>抽出ID</h2>

    <table>
        <tr>
            <th>入力値</th>
            <td><?= h($ids['input']) ?></td>
        </tr>
        <tr>
            <th>pid</th>
            <td><?= h($ids['pid']) ?></td>
        </tr>
        <tr>
            <th>record_id</th>
            <td><?= h($ids['record_id']) ?></td>
        </tr>
        <tr>
            <th>HTML取得URL</th>
            <td>
                <?php if ($ids['book_url'] !== ''): ?>
                    <a href="<?= h($ids['book_url']) ?>" target="_blank" rel="noopener"><?= h($ids['book_url']) ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>index/get API URL</th>
            <td>
                <?php if ($ids['record_id'] !== ''): ?>
                    <a href="<?= h(build_index_get_url($ids['record_id'])) ?>" target="_blank" rel="noopener"><?= h(build_index_get_url($ids['record_id'])) ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Cookie使用</th>
            <td>HTML取得時のCookieを index/get API に引き継ぎます。</td>
        </tr>
    </table>
</div>

<div class="box">
    <h2>HTML取得結果</h2>

    <?php if ($htmlResult): ?>
        <table>
            <tr>
                <th>結果</th>
                <td>
                    <?php if ($htmlResult['ok']): ?>
                        <span class="ok">OK</span>
                    <?php else: ?>
                        <span class="ng">NG</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>HTTP</th>
                <td><?= h((string)$htmlResult['http_code']) ?></td>
            </tr>
            <tr>
                <th>errno</th>
                <td><?= h((string)$htmlResult['errno']) ?></td>
            </tr>
            <tr>
                <th>Content-Type</th>
                <td><?= h($htmlResult['content_type']) ?></td>
            </tr>
            <tr>
                <th>primary_ip</th>
                <td><?= h($htmlResult['primary_ip'] ?? '') ?></td>
            </tr>
            <tr>
                <th>namelookup_time</th>
                <td><?= h($htmlResult['namelookup_time'] ?? '') ?></td>
            </tr>
            <tr>
                <th>connect_time</th>
                <td><?= h($htmlResult['connect_time'] ?? '') ?></td>
            </tr>
            <tr>
                <th>starttransfer_time</th>
                <td><?= h($htmlResult['starttransfer_time'] ?? '') ?></td>
            </tr>
            <tr>
                <th>total_time</th>
                <td><?= h($htmlResult['total_time'] ?? '') ?></td>
            </tr>
            <tr>
                <th>redirect_count</th>
                <td><?= h($htmlResult['redirect_count'] ?? '') ?></td>
            </tr>
            <tr>
                <th>effective_url</th>
                <td><?= h($htmlResult['effective_url'] ?? '') ?></td>
            </tr>
            <?php if (!$htmlResult['ok']): ?>
                <tr>
                    <th>エラー</th>
                    <td><?= h($htmlResult['error']) ?></td>
                </tr>
            <?php endif; ?>
        </table>
    <?php else: ?>
        <p class="ng">HTML取得URLを作れませんでした。</p>
    <?php endif; ?>
</div>

<?php if ($htmlResult && $htmlResult['ok']): ?>

<div class="box">
    <h2>基本情報</h2>

    <table>
        <tr>
            <th>タイトル</th>
            <td><?= h($htmlMeta['title'] ?? '') ?></td>
        </tr>
        <tr>
            <th>pid</th>
            <td><?= h($htmlMeta['pid'] ?? '') ?></td>
        </tr>
        <tr>
            <th>出版社</th>
            <td><?= h($htmlMeta['publisher'] ?? '') ?></td>
        </tr>
        <tr>
            <th>出版年</th>
            <td><?= h($htmlMeta['issued'] ?? '') ?></td>
        </tr>
        <tr>
            <th>請求記号</th>
            <td><?= h($htmlMeta['call_number'] ?? '') ?></td>
        </tr>
    </table>
</div>

<div class="box">
    <h2>HTML目次候補</h2>

    <?php if (count($htmlToc) > 0): ?>

        <p class="ok">
            HTMLから目次候補が <?= count($htmlToc) ?> 件見つかりました。
        </p>

        <table>
            <tr>
                <th>No</th>
                <th>ページ</th>
                <th>タイトル</th>
                <th>作者</th>
                <th>元テキスト</th>
            </tr>

            <?php foreach ($htmlToc as $i => $item): ?>
                <tr>
                    <td><?= (int)($i + 1) ?></td>
                    <td><?= h($item['page']) ?></td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h($item['creator']) ?></td>
                    <td class="raw"><?= h($item['raw']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

    <?php else: ?>

        <p class="ng">HTMLから目次候補を抽出できませんでした。</p>

    <?php endif; ?>
</div>

<div class="box">
    <h2>HTMLテキスト確認</h2>

    <p class="small">
        「目次」周辺がここに出ているか確認してください。
    </p>

    <textarea readonly><?php
        foreach ($htmlLines as $line) {
            echo h($line) . "\n";
        }
    ?></textarea>
</div>

<?php endif; ?>

<?php if ($indexResult): ?>
<div class="box">
    <h2>index/get API取得結果</h2>

    <table>
        <tr>
            <th>結果</th>
            <td>
                <?php if ($indexResult['ok']): ?>
                    <span class="ok">OK</span>
                <?php else: ?>
                    <span class="ng">NG</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>HTTP</th>
            <td><?= h((string)$indexResult['http_code']) ?></td>
        </tr>
        <tr>
            <th>errno</th>
            <td><?= h((string)$indexResult['errno']) ?></td>
        </tr>
        <tr>
            <th>Content-Type</th>
            <td><?= h($indexResult['content_type']) ?></td>
        </tr>
        <tr>
            <th>primary_ip</th>
            <td><?= h($indexResult['primary_ip'] ?? '') ?></td>
        </tr>
        <tr>
            <th>namelookup_time</th>
            <td><?= h($indexResult['namelookup_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>connect_time</th>
            <td><?= h($indexResult['connect_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>starttransfer_time</th>
            <td><?= h($indexResult['starttransfer_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>total_time</th>
            <td><?= h($indexResult['total_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>API URL</th>
            <td>
                <a href="<?= h($indexResult['url']) ?>" target="_blank" rel="noopener">開く</a>
            </td>
        </tr>
        <?php if (!$indexResult['ok']): ?>
            <tr>
                <th>エラー</th>
                <td><?= h($indexResult['error']) ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php if (count($indexToc) > 0): ?>
        <p class="ok">
            index/get APIから候補が <?= count($indexToc) ?> 件見つかりました。
        </p>

        <table>
            <tr>
                <th>No</th>
                <th>ページ</th>
                <th>タイトル</th>
                <th>作者</th>
                <th>元データ</th>
            </tr>

            <?php foreach ($indexToc as $i => $item): ?>
                <tr>
                    <td><?= (int)($i + 1) ?></td>
                    <td><?= h($item['page']) ?></td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= h($item['creator']) ?></td>
                    <td class="raw"><?= h($item['raw']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="ng">
            index/get APIから候補を自動抽出できませんでした。
        </p>
    <?php endif; ?>

    <h3>index/get レスポンス確認</h3>
    <textarea readonly><?= h($indexResult['body']) ?></textarea>
</div>
<?php endif; ?>

<?php if ($sruResult): ?>
<div class="box">
    <h2>参考：SRU API取得結果</h2>

    <table>
        <tr>
            <th>結果</th>
            <td>
                <?php if ($sruResult['ok']): ?>
                    <span class="ok">OK</span>
                <?php else: ?>
                    <span class="ng">NG</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>HTTP</th>
            <td><?= h((string)$sruResult['http_code']) ?></td>
        </tr>
        <tr>
            <th>errno</th>
            <td><?= h((string)$sruResult['errno']) ?></td>
        </tr>
        <tr>
            <th>Content-Type</th>
            <td><?= h($sruResult['content_type']) ?></td>
        </tr>
        <tr>
            <th>primary_ip</th>
            <td><?= h($sruResult['primary_ip'] ?? '') ?></td>
        </tr>
        <tr>
            <th>namelookup_time</th>
            <td><?= h($sruResult['namelookup_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>connect_time</th>
            <td><?= h($sruResult['connect_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>starttransfer_time</th>
            <td><?= h($sruResult['starttransfer_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>total_time</th>
            <td><?= h($sruResult['total_time'] ?? '') ?></td>
        </tr>
        <tr>
            <th>API URL</th>
            <td>
                <a href="<?= h($sruResult['url']) ?>" target="_blank" rel="noopener">開く</a>
            </td>
        </tr>
        <?php if (!$sruResult['ok']): ?>
            <tr>
                <th>エラー</th>
                <td><?= h($sruResult['error']) ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <p class="small">
        今回は <code>recordSchema=dcndl</code>、<code>recordPacking=xml</code>、<code>identifier="info:ndljp/pid/..."</code> で問い合わせています。
    </p>

    <textarea readonly><?= h($sruResult['body']) ?></textarea>
</div>
<?php endif; ?>

<?php endif; ?>

</body>
</html>