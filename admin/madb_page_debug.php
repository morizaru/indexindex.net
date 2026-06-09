<?php
// admin/madb_page_debug.php

header('Content-Type: text/html; charset=UTF-8');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$madb_id = isset($_GET['id']) ? trim($_GET['id']) : 'M537766';

$url = 'https://mediaarts-db.artmuseums.go.jp/id/' . rawurlencode($madb_id);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 INDEXINDEX/0.1',
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ja,en-US;q=0.9,en;q=0.8',
    ],
]);

$html = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$length = is_string($html) ? strlen($html) : 0;

// 画面確認しやすいように一部だけ表示
$preview = '';
if (is_string($html)) {
    $preview = mb_substr($html, 0, 5000, 'UTF-8');
}

// キーワード存在確認
$keywords = [
    '週刊少年マガジン',
    '講談社',
    '1965',
    '1月17日',
    '3号',
    '__NEXT_DATA__',
    'pp.',
    'マンガ作品',
    '作者名',
];

$keyword_results = [];
foreach ($keywords as $kw) {
    $keyword_results[$kw] = (is_string($html) && mb_strpos($html, $kw) !== false) ? 'あり' : 'なし';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MADBページ取得確認</title>
    <style>
        body { font-family: sans-serif; margin: 24px; background: #f5f5f5; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        .box { background: #f3f3f3; border: 1px solid #ddd; padding: 12px; margin-bottom: 16px; border-radius: 4px; }
        .error { background: #ffebee; color: #b00020; border: 1px solid #e57373; padding: 12px; margin-bottom: 16px; border-radius: 4px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #eee; }
        pre { background: #222; color: #eee; padding: 12px; white-space: pre-wrap; word-break: break-all; max-height: 500px; overflow: auto; }
        a { color: #0066cc; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">

<h1>MADBページ取得確認</h1>

<div class="box">
    MADB ID：<?= h($madb_id) ?><br>
    URL：<a href="<?= h($url) ?>" target="_blank"><?= h($url) ?></a><br>
    HTTPコード：<?= h($http_code) ?><br>
    Content-Type：<?= h($content_type) ?><br>
    取得サイズ：<?= h($length) ?> bytes
</div>

<?php if ($error !== ''): ?>
    <div class="error">cURLエラー：<?= h($error) ?></div>
<?php endif; ?>

<h2>キーワード確認</h2>

<table>
    <tr>
        <th>キーワード</th>
        <th>結果</th>
    </tr>
    <?php foreach ($keyword_results as $kw => $result): ?>
        <tr>
            <td><?= h($kw) ?></td>
            <td><?= h($result) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>HTML先頭5000文字</h2>
<pre><?= h($preview) ?></pre>

</div>
</body>
</html>