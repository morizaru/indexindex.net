<?php
// admin/madb_collection_extract.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sparql_query($sparql) {
    $endpoint = 'https://mediaarts-db.artmuseums.go.jp/sparql';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
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

function split_publisher_name($value) {
    // 例：講談社　∥　コウダンシャ → 講談社
    $parts = preg_split('/∥/u', $value);
    return trim($parts[0] ?? $value);
}

function is_kana_only($text) {
    $text = trim($text);

    if ($text === '') {
        return false;
    }

    // カタカナのみを読み扱い
    return preg_match('/^[ァ-ヶー・＝= 　]+$/u', $text) === 1;
}

function normalize_magazine_name($values) {
    foreach ($values as $value) {
        $value = trim($value);
        if ($value !== '' && !is_kana_only($value)) {
            return $value;
        }
    }

    return trim($values[0] ?? '');
}

function get_collection_id($uri) {
    return basename($uri);
}

$target_year = isset($_GET['year']) ? (int)$_GET['year'] : 1965;

$message = '';
$error = '';
$collections = [];

try {
    $sparql = "
PREFIX schema: <https://schema.org/>
PREFIX prop: <https://mediaarts-db.artmuseums.go.jp/data/property#>

SELECT ?collection ?publisher ?name (COUNT(?issue) AS ?issueCount)
WHERE {
  ?issue schema:isPartOf ?collection .
  ?issue schema:genre \"マンガ雑誌単号\" .
  ?issue prop:yearDisplayed ?year .
  ?issue schema:name ?name .
  ?issue schema:publisher ?publisher .

  FILTER(STR(?year) = \"{$target_year}\")
  FILTER(CONTAINS(STR(?collection), \"/id/C\"))
}
GROUP BY ?collection ?publisher ?name
ORDER BY ?publisher ?name
LIMIT 1000
";

    $bindings = sparql_query($sparql);

    $tmp = [];

    foreach ($bindings as $row) {
        $collection_uri = $row['collection']['value'] ?? '';
        $publisher_raw = $row['publisher']['value'] ?? '';
        $name = $row['name']['value'] ?? '';
        $issue_count = (int)($row['issueCount']['value'] ?? 0);

        if ($collection_uri === '' || $name === '') {
            continue;
        }

        $collection_id = get_collection_id($collection_uri);

        if (!isset($tmp[$collection_id])) {
            $tmp[$collection_id] = [
                'external_collection_id' => $collection_id,
                'external_collection_url' => $collection_uri,
                'publisher_values' => [],
                'name_values' => [],
                'issue_count' => 0,
            ];
        }

        $publisher_name = split_publisher_name($publisher_raw);

        if ($publisher_name !== '') {
            $tmp[$collection_id]['publisher_values'][] = $publisher_name;
        }

        if ($name !== '') {
            $tmp[$collection_id]['name_values'][] = $name;
        }

        $tmp[$collection_id]['issue_count'] += $issue_count;
    }

    foreach ($tmp as $collection_id => $row) {
        $publisher_values = array_values(array_unique($row['publisher_values']));
        $name_values = array_values(array_unique($row['name_values']));

        $publisher_name = $publisher_values[0] ?? '';
        $magazine_name = normalize_magazine_name($name_values);

        if ($magazine_name === '') {
            continue;
        }

        $collections[] = [
            'external_collection_id' => $row['external_collection_id'],
            'external_collection_url' => $row['external_collection_url'],
            'publisher_name' => $publisher_name,
            'magazine_name' => $magazine_name,
            'issue_count' => $row['issue_count'],
        ];
    }

    usort($collections, function ($a, $b) {
        return [$a['publisher_name'], $a['magazine_name']] <=> [$b['publisher_name'], $b['magazine_name']];
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($collections)) {
            throw new Exception('登録対象がありません。');
        }

        $pdo->beginTransaction();

        $inserted = 0;
        $updated = 0;

        foreach ($collections as $row) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM external_magazine_collections
                WHERE source_name = 'メディア芸術データベース'
                  AND external_collection_id = :external_collection_id
                LIMIT 1
            ");
            $stmt->execute([
                ':external_collection_id' => $row['external_collection_id']
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $stmt = $pdo->prepare("
                    UPDATE external_magazine_collections
                    SET
                        external_collection_url = :external_collection_url,
                        publisher_name = :publisher_name,
                        magazine_name = :magazine_name,
                        note = :note,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':external_collection_url' => $row['external_collection_url'],
                    ':publisher_name' => $row['publisher_name'] !== '' ? $row['publisher_name'] : null,
                    ':magazine_name' => $row['magazine_name'],
                    ':note' => 'MADBから抽出 / 抽出年：' . $target_year . ' / 号数件数：' . $row['issue_count'],
                    ':id' => (int)$exists['id'],
                ]);

                $updated++;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO external_magazine_collections (
                        source_name,
                        external_collection_id,
                        external_collection_url,
                        publisher_name,
                        magazine_name,
                        note
                    ) VALUES (
                        'メディア芸術データベース',
                        :external_collection_id,
                        :external_collection_url,
                        :publisher_name,
                        :magazine_name,
                        :note
                    )
                ");
                $stmt->execute([
                    ':external_collection_id' => $row['external_collection_id'],
                    ':external_collection_url' => $row['external_collection_url'],
                    ':publisher_name' => $row['publisher_name'] !== '' ? $row['publisher_name'] : null,
                    ':magazine_name' => $row['magazine_name'],
                    ':note' => 'MADBから抽出 / 抽出年：' . $target_year . ' / 号数件数：' . $row['issue_count'],
                ]);

                $inserted++;
            }
        }

        $pdo->commit();

        $message = '登録完了：新規 ' . $inserted . '件 / 更新 ' . $updated . '件';
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MADB雑誌親ID抽出 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
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

        input[type="number"] {
            width: 160px;
            padding: 10px;
            font-size: 16px;
        }

        button {
            padding: 10px 18px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #111;
        }

        .box {
            background: #f3f3f3;
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
            line-height: 1.8;
        }

        .message {
            padding: 12px;
            background: #e8f5e9;
            border: 1px solid #81c784;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .error {
            padding: 12px;
            background: #ffebee;
            border: 1px solid #e57373;
            color: #b00020;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-top: 16px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            word-break: break-all;
            vertical-align: top;
        }

        th {
            background: #eee;
            text-align: left;
        }

        .id {
            font-weight: bold;
            color: #b00020;
        }

        .small {
            color: #666;
            font-size: 13px;
        }

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .search-box {
                display: block;
            }

            input[type="number"],
            button {
                width: 100%;
                box-sizing: border-box;
                margin-bottom: 8px;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            thead {
                display: none;
            }

            tr {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 8px;
            }

            td {
                border: none;
                padding: 4px 0;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>MADB雑誌親ID抽出</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="get" action="madb_collection_extract.php" class="search-box">
        <input type="number" name="year" value="<?= h($target_year) ?>" placeholder="例：1965">
        <button type="submit">抽出する</button>
    </form>

    <div class="box">
        対象年：<?= h($target_year) ?>年<br>
        抽出件数：<?= h(count($collections)) ?>件
    </div>

    <?php if (!empty($collections) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <form method="post" action="madb_collection_extract.php?year=<?= h($target_year) ?>">
            <button type="submit" onclick="return confirm('この内容を external_magazine_collections に登録しますか？');">
                この内容で登録する
            </button>
        </form>
    <?php endif; ?>

    <?php if (!empty($collections)): ?>
        <table>
            <thead>
                <tr>
                    <th>C ID</th>
                    <th>出版社</th>
                    <th>雑誌名</th>
                    <th>号数件数</th>
                    <th>URL</th>
                    <th>年取込</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($collections as $row): ?>
                <tr>
                    <td class="id"><?= h($row['external_collection_id']) ?></td>
                    <td><?= h($row['publisher_name']) ?></td>
                    <td><?= h($row['magazine_name']) ?></td>
                    <td><?= h($row['issue_count']) ?></td>
                    <td>
                        <a href="<?= h($row['external_collection_url']) ?>" target="_blank">
                            MADBを開く
                        </a>
                    </td>
                    <td>
                        <a href="madb_import_year.php?parent_id=<?= h($row['external_collection_id']) ?>&year=<?= h($target_year) ?>">
                            <?= h($target_year) ?>年を取込確認
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="small">抽出データがありません。</p>
    <?php endif; ?>

</div>
</body>
</html>