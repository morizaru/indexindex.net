<?php
// admin/madb_debug.php

header('Content-Type: text/html; charset=UTF-8');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$endpoint = 'https://mediaarts-db.artmuseums.go.jp/sparql';

$madb_issue_uri = 'https://mediaarts-db.artmuseums.go.jp/id/M537766';

$sparql = "
PREFIX schema: <https://schema.org/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

SELECT ?part ?p ?o
WHERE {
  <{$madb_issue_uri}> schema:hasPart ?part .
  ?part ?p ?o .
}
ORDER BY ?part ?p
LIMIT 2000
";

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'query' => $sparql,
    ]),
    CURLOPT_HTTPHEADER => [
        'Accept: application/sparql-results+json',
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_USERAGENT => 'INDEXINDEX/0.1',
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$data = json_decode($response, true);
$bindings = $data['results']['bindings'] ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MADB hasPart確認</title>
    <style>
        body { font-family: sans-serif; margin: 24px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        .box { background: #f3f3f3; border: 1px solid #ddd; padding: 12px; margin-bottom: 16px; border-radius: 4px; }
        .error { background: #ffebee; color: #b00020; border: 1px solid #e57373; padding: 12px; margin-bottom: 16px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #ccc; padding: 8px; word-break: break-all; font-size: 13px; vertical-align: top; }
        th { background: #eee; }
        pre { background: #222; color: #eee; padding: 12px; white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body>
<div class="container">

<h1>MADB hasPart確認</h1>

<div class="box">
    Issue URI：<?= h($madb_issue_uri) ?><br>
    HTTPコード：<?= h($http_code) ?><br>
    Content-Type：<?= h($content_type) ?><br>
    取得件数：<?= h(count($bindings)) ?>件
</div>

<?php if ($error !== ''): ?>
    <div class="error">cURLエラー：<?= h($error) ?></div>
<?php endif; ?>

<?php if (!$data): ?>

    <div class="error">JSONとして読めませんでした。</div>
    <h2>生レスポンス</h2>
    <pre><?= h($response) ?></pre>

<?php elseif (empty($bindings)): ?>

    <div class="error">hasPartの詳細が取得できませんでした。</div>
    <h2>実行クエリ</h2>
    <pre><?= h($sparql) ?></pre>

<?php else: ?>

    <table>
        <thead>
            <tr>
                <th>part</th>
                <th>p</th>
                <th>o</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bindings as $row): ?>
            <tr>
                <td><?= h($row['part']['value'] ?? '') ?></td>
                <td><?= h($row['p']['value'] ?? '') ?></td>
                <td><?= h($row['o']['value'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

</div>
</body>
</html>