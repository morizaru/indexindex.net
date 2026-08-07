<?php
// admin/madb_import.php

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

function short_prop($uri) {
    if (strpos($uri, '#') !== false) {
        return substr($uri, strrpos($uri, '#') + 1);
    }
    return substr($uri, strrpos($uri, '/') + 1);
}

function is_kana_only($text) {
    $text = trim($text);

    if ($text === '') {
        return false;
    }

    // カタカナだけを読み仮名扱いにする
    // 「ちばてつや」のような平仮名名義は作者名として残す
    return preg_match('/^[ァ-ヶー・＝= 　]+$/u', $text) === 1;
}

function clean_decimal($value) {
    if ($value === null || $value === '') {
        return null;
    }

    return (int)floor((float)$value);
}

function date_to_japanese_issue_label($date) {
    if (!$date) {
        return '';
    }

    $ts = strtotime($date);
    if (!$ts) {
        return $date . '号';
    }

    return date('Y', $ts) . '年' . (int)date('m', $ts) . '月' . (int)date('d', $ts) . '日号';
}

function split_publisher_name($value) {
    // 例：講談社　∥　コウダンシャ → 講談社
    $parts = preg_split('/∥/u', $value);
    return trim($parts[0] ?? $value);
}

function add_value(&$array, $key, $value) {
    if (!isset($array[$key])) {
        $array[$key] = [];
    }
    $array[$key][] = $value;
}

$madb_id = isset($_GET['id']) ? trim($_GET['id']) : 'M537766';

// 今回確認できた親コレクション
$parent_uri = 'https://mediaarts-db.artmuseums.go.jp/id/C119033';
$issue_uri = 'https://mediaarts-db.artmuseums.go.jp/id/' . $madb_id;

$message = '';
$error = '';
$preview = null;

try {
    // 雑誌号情報
    $sparql_issue = "
PREFIX schema: <https://schema.org/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

SELECT ?p ?o
WHERE {
  ?s schema:isPartOf <{$parent_uri}> .
  FILTER(?s = <{$issue_uri}>)
  ?s ?p ?o .
}
LIMIT 500
";

    $issue_bindings = sparql_query($sparql_issue);

    $issue_props = [];

    foreach ($issue_bindings as $row) {
        $p = $row['p']['value'] ?? '';
        $o = $row['o']['value'] ?? '';
        $key = short_prop($p);
        add_value($issue_props, $key, $o);
    }

    // 目次情報
    $sparql_parts = "
PREFIX schema: <https://schema.org/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

SELECT ?part ?p ?o
WHERE {
  <{$issue_uri}> schema:hasPart ?part .
  ?part ?p ?o .
}
ORDER BY ?part ?p
LIMIT 3000
";

    $part_bindings = sparql_query($sparql_parts);

    $parts = [];

    foreach ($part_bindings as $row) {
        $part = $row['part']['value'] ?? '';
        $p = $row['p']['value'] ?? '';
        $o = $row['o']['value'] ?? '';

        if ($part === '') {
            continue;
        }

        if (!isset($parts[$part])) {
            $parts[$part] = [];
        }

        $key = short_prop($p);
        add_value($parts[$part], $key, $o);
    }

    // 雑誌号データ整形
    $publisher_raw = $issue_props['publisher'][0] ?? '';
    $publisher_name = split_publisher_name($publisher_raw);

    $magazine_names = $issue_props['name'] ?? [];
    $magazine_name = '';

    foreach ($magazine_names as $name) {
        if (!is_kana_only($name)) {
            $magazine_name = $name;
            break;
        }
    }

    if ($magazine_name === '' && !empty($magazine_names)) {
        $magazine_name = $magazine_names[0];
    }

    $published_date = $issue_props['datePublished'][0] ?? null;
    $issue_label = date_to_japanese_issue_label($published_date);
    $release_date = null;
    $issue_year = $issue_props['yearDisplayed'][0] ?? null;
    $issue_no = $issue_props['issueNumberDisplayed'][0] ?? ($issue_props['issueNumber'][0] ?? null);

    // マンガ作品のみ抽出
    $contents = [];

    foreach ($parts as $part_uri => $props) {
        $genres = $props['genre'] ?? [];

        if (!in_array('マンガ作品', $genres, true)) {
            continue;
        }

        $title = $props['name'][0] ?? '';
        $episode_title = '';
        $story_title = $props['alternativeHeadline'][0] ?? '';

        $page_start = clean_decimal($props['pageStart'][0] ?? null);
        $page_end = clean_decimal($props['pageEnd'][0] ?? null);

        $creators = [];

        foreach (($props['creator'] ?? []) as $creator) {
            $creator = trim($creator);
            if ($creator === '') {
                continue;
            }

            if (is_kana_only($creator)) {
                continue;
            }

            $creators[] = $creator;
        }

        $creators = array_values(array_unique($creators));

        $notes = [];

        if ($story_title !== '') {
            $notes[] = '話タイトル：' . $story_title;
        }

        foreach (($props['note'] ?? []) as $note) {
            if (trim($note) !== '') {
                $notes[] = trim($note);
            }
        }

        $originals = [];

        foreach (($props['originalWorkCreator'] ?? []) as $original) {
            $original = trim($original);
            if ($original === '' || is_kana_only($original)) {
                continue;
            }
            $originals[] = $original;
        }

        $originals = array_values(array_unique($originals));

        if (!empty($originals)) {
            $notes[] = '原作：' . implode(' / ', $originals);
        }

        $identifier = $props['identifier'][0] ?? '';

        if ($identifier !== '') {
            $notes[] = 'MADB：' . $identifier;
        }

        $contents[] = [
            'part_uri' => $part_uri,
            'title_main' => $title,
            'episode_title' => $episode_title,
            'page_start' => $page_start,
            'page_end' => $page_end,
            'creators' => $creators,
            'note' => implode('　／　', $notes),
        ];
    }

    usort($contents, function ($a, $b) {
        return ($a['page_start'] ?? 999999) <=> ($b['page_start'] ?? 999999);
    });

    $sort_order = 1;
    foreach ($contents as &$content) {
        $content['sort_order'] = $sort_order++;
    }
    unset($content);

    $preview = [
        'publisher_name' => $publisher_name,
        'magazine_name' => $magazine_name,
        'issue_label' => $issue_label,
        'issue_year' => $issue_year,
        'issue_no' => $issue_no,
        'release_date' => $release_date,
        'contents' => $contents,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($publisher_name === '' || $magazine_name === '' || $issue_label === '') {
            throw new Exception('雑誌号データが不足しているため登録できません。');
        }

        if (empty($contents)) {
            throw new Exception('登録対象のマンガ作品がありません。');
        }

        $pdo->beginTransaction();

        // 出版社
        $stmt = $pdo->prepare("
            SELECT id
            FROM publishers
            WHERE name = :name
            LIMIT 1
        ");
        $stmt->execute([
            ':name' => $publisher_name
        ]);
        $publisher = $stmt->fetch();

        if ($publisher) {
            $publisher_id = (int)$publisher['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO publishers (name)
                VALUES (:name)
            ");
            $stmt->execute([
                ':name' => $publisher_name
            ]);
            $publisher_id = (int)$pdo->lastInsertId();
        }

        // 雑誌
        $stmt = $pdo->prepare("
    SELECT id
    FROM magazines
    WHERE name = :name
    LIMIT 1
");
$stmt->execute([
    ':name' => $magazine_name
]);
        $magazine = $stmt->fetch();

        if ($magazine) {
            $magazine_id = (int)$magazine['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO magazines (publisher_id, name)
                VALUES (:publisher_id, :name)
            ");
            $stmt->execute([
                ':publisher_id' => $publisher_id,
                ':name' => $magazine_name
            ]);
            $magazine_id = (int)$pdo->lastInsertId();
        }

        // 雑誌号 重複確認
        $stmt = $pdo->prepare("
            SELECT id
            FROM magazine_issues
            WHERE magazine_id = :magazine_id
              AND issue_label = :issue_label
            LIMIT 1
        ");
        $stmt->execute([
            ':magazine_id' => $magazine_id,
            ':issue_label' => $issue_label
        ]);
        $existing_issue = $stmt->fetch();

        if ($existing_issue) {
            $issue_id = (int)$existing_issue['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO magazine_issues (
                    magazine_id,
                    issue_label,
                    issue_year,
                    issue_no,
                    release_date,
                    status
                ) VALUES (
                    :magazine_id,
                    :issue_label,
                    :issue_year,
                    :issue_no,
                    :release_date,
                    'approved'
                )
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id,
                ':issue_label' => $issue_label,
                ':issue_year' => $issue_year !== null ? (int)$issue_year : null,
                ':issue_no' => $issue_no !== null ? (string)$issue_no : null,
                ':release_date' => $release_date,
            ]);

            $issue_id = (int)$pdo->lastInsertId();
        }

        $inserted_count = 0;
        $skipped_count = 0;

        foreach ($contents as $content) {
            // 同じ号・同じ掲載順があればスキップ
            $stmt = $pdo->prepare("
                SELECT id
                FROM issue_contents
                WHERE issue_id = :issue_id
                  AND sort_order = :sort_order
                LIMIT 1
            ");
            $stmt->execute([
                ':issue_id' => $issue_id,
                ':sort_order' => $content['sort_order']
            ]);
            $exists_content = $stmt->fetch();

            if ($exists_content) {
                $skipped_count++;
                continue;
            }

            // 作品
            $stmt = $pdo->prepare("
                SELECT id
                FROM works
                WHERE BINARY title = BINARY :title
                LIMIT 1
            ");
            $stmt->execute([
                ':title' => $content['title_main']
            ]);
            $work = $stmt->fetch();

            if ($work) {
                $work_id = (int)$work['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO works (title)
                    VALUES (:title)
                ");
                $stmt->execute([
                    ':title' => $content['title_main']
                ]);
                $work_id = (int)$pdo->lastInsertId();
            }

            // 目次
            $stmt = $pdo->prepare("
                INSERT INTO issue_contents (
                    issue_id,
                    work_id,
                    sort_order,
                    page_start,
                    page_end,
                    title_main,
                    episode_title,
                    episode_no,
                    note,
                    content_type
                ) VALUES (
                    :issue_id,
                    :work_id,
                    :sort_order,
                    :page_start,
                    :page_end,
                    :title_main,
                    :episode_title,
                    NULL,
                    :note,
                    'manga'
                )
            ");

            $stmt->execute([
                ':issue_id' => $issue_id,
                ':work_id' => $work_id,
                ':sort_order' => $content['sort_order'],
                ':page_start' => $content['page_start'],
                ':page_end' => $content['page_end'],
                ':title_main' => $content['title_main'],
                ':episode_title' => $content['episode_title'] !== '' ? $content['episode_title'] : null,
                ':note' => $content['note'] !== '' ? $content['note'] : null,
            ]);

            $content_id = (int)$pdo->lastInsertId();

            // 作者
            $display_order = 1;

            foreach ($content['creators'] as $creator_name) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM creators
                    WHERE name = :name
                    LIMIT 1
                ");
                $stmt->execute([
                    ':name' => $creator_name
                ]);
                $creator = $stmt->fetch();

                if ($creator) {
                    $creator_id = (int)$creator['id'];
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO creators (name)
                        VALUES (:name)
                    ");
                    $stmt->execute([
                        ':name' => $creator_name
                    ]);
                    $creator_id = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("
                    INSERT INTO content_creators (
                        content_id,
                        creator_id,
                        role,
                        display_order
                    ) VALUES (
                        :content_id,
                        :creator_id,
                        'author',
                        :display_order
                    )
                ");

                $stmt->execute([
                    ':content_id' => $content_id,
                    ':creator_id' => $creator_id,
                    ':display_order' => $display_order
                ]);

                $display_order++;
            }

            $inserted_count++;
        }

        $pdo->commit();

        $message = '登録完了：号ID ' . $issue_id . ' / 目次追加 ' . $inserted_count . '件 / スキップ ' . $skipped_count . '件';
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
    <title>MADB取込 - INDEXINDEX</title>
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
            text-decoration: none;
            font-weight: bold;
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

        .box {
            background: #f3f3f3;
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
            line-height: 1.8;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-top: 16px;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            background: #eee;
            text-align: left;
        }

        button {
            padding: 10px 20px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 16px;
        }

        button:hover {
            background: #111;
        }

        .small {
            color: #666;
            font-size: 13px;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
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

    <h1>MADB取込</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="content_list.php">登録済み目次一覧</a>
        <a href="../issues.php">公開：雑誌号一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="../issues.php">公開：雑誌号一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($preview): ?>

        <div class="box">
            MADB ID：<?= h($madb_id) ?><br>
            出典：メディア芸術データベース<br>
            出版社名：<?= h($preview['publisher_name']) ?><br>
            雑誌名：<?= h($preview['magazine_name']) ?><br>
            号数表示：<?= h($preview['issue_label']) ?><br>
            年：<?= h($preview['issue_year']) ?><br>
            号数：<?= h($preview['issue_no']) ?><br>
            発売日：<?= h($preview['release_date']) ?><br>
            取込対象マンガ作品：<?= h(count($preview['contents'])) ?>件
        </div>

        <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <form method="post" action="madb_import.php?id=<?= h($madb_id) ?>">
                <button type="submit" onclick="return confirm('この内容でINDEXINDEXに登録しますか？');">
                    この内容で登録する
                </button>
            </form>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>順</th>
                    <th>ページ</th>
                    <th>タイトル</th>
                    <th>サブタイトル</th>
                    <th>作者</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview['contents'] as $row): ?>
                <tr>
                    <td><?= h($row['sort_order']) ?></td>
                    <td>
                        <?= h($row['page_start']) ?>
                        〜
                        <?= h($row['page_end']) ?>
                    </td>
                    <td><?= h($row['title_main']) ?></td>
                    <td><?= h($row['episode_title']) ?></td>
                    <td><?= h(implode(' / ', $row['creators'])) ?></td>
                    <td class="note"><?= h($row['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
</body>
</html>
