<?php
// admin/madb_collection_search.php

header('Content-Type: text/html; charset=UTF-8');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sparql_query($sparql) {
    $endpoint = 'https://mediaarts-db.artmuseums.go.jp/sparql';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
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
    curl_close($ch);

    if ($error !== '') {
        throw new Exception('SPARQL通信エラー：' . $error);
    }

    if ($http_code !== 200) {
        throw new Exception('SPARQL HTTPエラー：' . $http_code);
    }

    $data = json_decode($response, true);

    if (!$data) {
        throw new Exception('SPARQLレスポンスをJSONとして読めませんでした。');
    }

    return $data['results']['bindings'] ?? [];
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '週刊少年サンデー';

$results = [];
$error = '';

if ($q !== '') {
    try {
        $sparql = "
        PREFIX schema: <https://schema.org/>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        
        SELECT DISTINCT ?collection ?label ?name ?genre
        WHERE {
          ?collection schema:name ?name .
        
          OPTIONAL { ?collection rdfs:label ?label . }
          OPTIONAL { ?collection schema:genre ?genre . }
        
          FILTER(CONTAINS(STR(?name), \"{$q}\"))
          FILTER(CONTAINS(STR(?collection), \"/id/C\"))
        }
        LIMIT 50
        ";

        $results = sparql_query($sparql);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MADB雑誌ID検索 - INDEXINDEX</title>
    <style>
        body {
            font-family: sans-serif;
            margin: 24px;
            background: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        .nav {
            margin-bottom: 20px;
        }

        .nav a {
            margin-right: 12px;
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        .search-box {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        input[type="text"] {
            flex: 1;
            padding: 10px;
            font-size: 16px;
        }

        button {
            padding: 10px 18px;
            font-size: 16px;
        }

        .error {
            background: #ffebee;
            color: #b00020;
            border: 1px solid #e57373;
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .box {
            background: #f3f3f3;
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            word-break: break-all;
            vertical-align: top;
        }

        th {
            background: #eee;
        }

        .id {
            font-weight: bold;
            color: #b00020;
        }

        a {
            color: #0066cc;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>MADB雑誌ID検索</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
    </div>

    <form method="get" action="madb_collection_search.php" class="search-box">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="例：週刊少年サンデー">
        <button type="submit">検索</button>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="box">
        検索語：<?= h($q) ?><br>
        取得件数：<?= h(count($results)) ?>件
    </div>

    <?php if (!empty($results)): ?>
        <table>
            <thead>
                <tr>
                    <th>ID候補</th>
                    <th>label</th>
                    <th>name</th>
                    <th>genre</th>
                    <th>取込URL</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $row): ?>
                <?php
                    $collection = $row['collection']['value'] ?? '';
                    $id = basename($collection);
                    $label = $row['label']['value'] ?? '';
                    $name = $row['name']['value'] ?? '';
                    $genre = $row['genre']['value'] ?? '';
                ?>
                <tr>
                    <td class="id"><?= h($id) ?></td>
                    <td><?= h($label) ?></td>
                    <td><?= h($name) ?></td>
                    <td><?= h($genre) ?></td>
                    <td>
                        <a href="madb_import_year.php?parent_id=<?= h($id) ?>&year=2020">
                            2020年を取込確認
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>
</body>
</html>