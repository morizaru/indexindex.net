<?php
// admin/madb_import_year.php

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

function short_prop($uri) {
    if (strpos($uri, '#') !== false) {
        return substr($uri, strrpos($uri, '#') + 1);
    }
    return substr($uri, strrpos($uri, '/') + 1);
}

function add_value(&$array, $key, $value) {
    if (!isset($array[$key])) {
        $array[$key] = [];
    }
    $array[$key][] = $value;
}

function is_kana_only($text) {
    $text = trim($text);

    if ($text === '') {
        return false;
    }

    // カタカナだけを読み仮名扱い。ひらがな名義は作者名として残す。
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


function normalize_madb_date_for_mysql($date) {
    $date = trim((string)$date);

    if ($date === '') {
        return null;
    }

    // YYYY-MM-DD はそのまま
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    // YYYY-MM は1日扱いにする
    if (preg_match('/^(\d{4})-(\d{2})$/', $date, $m)) {
        return $m[1] . '-' . $m[2] . '-01';
    }

    // YYYY は1月1日扱いにする
    if (preg_match('/^(\d{4})$/', $date, $m)) {
        return $m[1] . '-01-01';
    }

    // MySQL DATE に入らない形式はNULL
    return null;
}

function split_publisher_name($value) {
    $parts = preg_split('/∥/u', $value);
    return trim($parts[0] ?? $value);
}

function get_plain_name($values) {
    foreach ($values as $value) {
        if (!is_kana_only($value)) {
            return $value;
        }
    }

    return $values[0] ?? '';
}

function get_or_create_publisher($pdo, $publisher_name) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM publishers
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $publisher_name
    ]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO publishers (name)
        VALUES (:name)
    ");
    $stmt->execute([
        ':name' => $publisher_name
    ]);

    return (int)$pdo->lastInsertId();
}

function get_or_create_magazine($pdo, $publisher_id, $magazine_name) {
    // 既存の雑誌重複防止のため、雑誌名のみで探す
    $stmt = $pdo->prepare("
        SELECT id
        FROM magazines
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $magazine_name
    ]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO magazines (publisher_id, name)
        VALUES (:publisher_id, :name)
    ");
    $stmt->execute([
        ':publisher_id' => $publisher_id,
        ':name' => $magazine_name
    ]);

    return (int)$pdo->lastInsertId();
}

function get_or_create_work($pdo, $title) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM works
        WHERE title = :title
        LIMIT 1
    ");
    $stmt->execute([
        ':title' => $title
    ]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO works (title)
        VALUES (:title)
    ");
    $stmt->execute([
        ':title' => $title
    ]);

    return (int)$pdo->lastInsertId();
}

function get_or_create_creator($pdo, $name) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM creators
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $name
    ]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO creators (name)
        VALUES (:name)
    ");
    $stmt->execute([
        ':name' => $name
    ]);

    return (int)$pdo->lastInsertId();
}

$parent_id = isset($_GET['parent_id']) ? trim($_GET['parent_id']) : 'C119033';
$target_year = isset($_GET['year']) ? (int)$_GET['year'] : 1965;

$parent_uri = 'https://mediaarts-db.artmuseums.go.jp/id/' . $parent_id;

$message = '';
$error = '';
$issues = [];

try {
    // 1. 対象年の雑誌号一覧を取得
    $sparql_issues = "
    PREFIX schema: <https://schema.org/>
    PREFIX prop: <https://mediaarts-db.artmuseums.go.jp/data/property#>
    
    SELECT ?issue ?p ?o
    WHERE {
      ?issue schema:isPartOf <{$parent_uri}> .
      ?issue prop:yearDisplayed ?year .
      FILTER(STR(?year) = \"{$target_year}\")
      ?issue ?p ?o .
    }
    ORDER BY ?issue ?p
    LIMIT 10000
    ";

    $issue_bindings = sparql_query($sparql_issues);

    $issue_props_all = [];

    foreach ($issue_bindings as $row) {
        $issue_uri = $row['issue']['value'] ?? '';
        $p = $row['p']['value'] ?? '';
        $o = $row['o']['value'] ?? '';

        if ($issue_uri === '') {
            continue;
        }

        if (!isset($issue_props_all[$issue_uri])) {
            $issue_props_all[$issue_uri] = [];
        }

        add_value($issue_props_all[$issue_uri], short_prop($p), $o);
    }

    // 2. 対象年の全hasPartを取得
$sparql_parts = "
PREFIX schema: <https://schema.org/>
PREFIX prop: <https://mediaarts-db.artmuseums.go.jp/data/property#>

SELECT ?issue ?part ?p ?o
WHERE {
  ?issue schema:isPartOf <{$parent_uri}> .
  ?issue prop:yearDisplayed ?year .
  FILTER(STR(?year) = \"{$target_year}\")
  ?issue schema:hasPart ?part .
  ?part ?p ?o .
}
ORDER BY ?issue ?part ?p
LIMIT 100000
";

    $part_bindings = sparql_query($sparql_parts);

    // 2.5 親ID側の情報を取得
    // 年によっては雑誌号側に publisher / name が入っていないことがあるため、
    // 親ID側の出版社名・雑誌名をフォールバックとして使う。
    $sparql_parent = "
    PREFIX schema: <https://schema.org/>
    PREFIX prop: <https://mediaarts-db.artmuseums.go.jp/data/property#>

    SELECT ?p ?o
    WHERE {
      <{$parent_uri}> ?p ?o .
    }
    ORDER BY ?p
    LIMIT 1000
    ";

    $parent_bindings = sparql_query($sparql_parent);
    $parent_props = [];

    foreach ($parent_bindings as $row) {
        $p = $row['p']['value'] ?? '';
        $o = $row['o']['value'] ?? '';

        if ($p === '') {
            continue;
        }

        add_value($parent_props, short_prop($p), $o);
    }

    $parent_publisher_name = split_publisher_name($parent_props['publisher'][0] ?? '');
    $parent_magazine_name = get_plain_name($parent_props['name'] ?? []);

    $parts_by_issue = [];

    foreach ($part_bindings as $row) {
        $issue_uri = $row['issue']['value'] ?? '';
        $part_uri = $row['part']['value'] ?? '';
        $p = $row['p']['value'] ?? '';
        $o = $row['o']['value'] ?? '';

        if ($issue_uri === '' || $part_uri === '') {
            continue;
        }

        if (!isset($parts_by_issue[$issue_uri])) {
            $parts_by_issue[$issue_uri] = [];
        }

        if (!isset($parts_by_issue[$issue_uri][$part_uri])) {
            $parts_by_issue[$issue_uri][$part_uri] = [];
        }

        add_value($parts_by_issue[$issue_uri][$part_uri], short_prop($p), $o);
    }

    // 3. プレビューデータ整形
    foreach ($issue_props_all as $issue_uri => $props) {
        $publisher_name = split_publisher_name($props['publisher'][0] ?? '');
        $magazine_name = get_plain_name($props['name'] ?? []);

        // 雑誌号側に publisher / name が無い場合は親ID側の情報で補完
        if ($publisher_name === '') {
            $publisher_name = $parent_publisher_name;
        }

        if ($magazine_name === '') {
            $magazine_name = $parent_magazine_name;
        }

        $published_date = $props['datePublished'][0] ?? null;
        $release_date = normalize_madb_date_for_mysql($published_date);

        $issue_year = $props['yearDisplayed'][0] ?? null;
        $issue_no = $props['issueNumberDisplayed'][0] ?? ($props['issueNumber'][0] ?? null);

        // 基本は発売日から「YYYY年M月D日号」を作る
        $issue_label = date_to_japanese_issue_label($published_date);

        // datePublished が無い号の救済
        // これが空のままだと登録処理で雑誌号ごと continue され、
        // 「取得できているのに一括登録0件」になるため、年・号数・MADB IDから号名を作る。
        if ($issue_label === '') {
            if ($issue_year !== null && $issue_year !== '' && $issue_no !== null && $issue_no !== '') {
                $issue_label = $issue_year . '年 ' . $issue_no . '号';
            } elseif ($issue_no !== null && $issue_no !== '') {
                $issue_label = $issue_no . '号';
            } elseif ($issue_year !== null && $issue_year !== '') {
                $issue_label = $issue_year . '年号';
            } else {
                $issue_label = basename($issue_uri);
            }
        }

        $contents = [];

        foreach (($parts_by_issue[$issue_uri] ?? []) as $part_uri => $part_props) {
            $genres = $part_props['genre'] ?? [];

            if (!in_array('マンガ作品', $genres, true)) {
                continue;
            }

            $title = $part_props['name'][0] ?? '';
            $story_title = $part_props['alternativeHeadline'][0] ?? '';
            $episode_title = '';

            $page_start = clean_decimal($part_props['pageStart'][0] ?? null);
            $page_end = clean_decimal($part_props['pageEnd'][0] ?? null);

            $creators = [];
            $creator_notes = [];

            foreach (($part_props['creator'] ?? []) as $creator) {
                $creator = trim($creator);

                if ($creator === '' || is_kana_only($creator)) {
                    continue;
                }

                // 例：[ART]上村一夫 / [WRT]上村一夫 / [作画]上村一夫 など
                // 作者にはタグを外した名前だけを入れ、備考には元の表記を残す
                if (preg_match('/^\[[^\]]+\](.+)$/u', $creator, $m)) {
                    $creator_notes[] = '＋' . $creator;
                    $creator = trim($m[1]);
                }

                if ($creator === '' || is_kana_only($creator)) {
                    continue;
                }

                $creators[] = $creator;
            }

            $creators = array_values(array_unique($creators));
            $creator_notes = array_values(array_unique($creator_notes));

            $notes = [];

            foreach ($creator_notes as $creator_note) {
                $notes[] = $creator_note;
            }

            if ($story_title !== '') {
                $notes[] = '話タイトル：' . $story_title;
            }

            foreach (($part_props['note'] ?? []) as $note) {
                $note = trim($note);
                if ($note !== '') {
                    $notes[] = $note;
                }
            }

            $originals = [];

            foreach (($part_props['originalWorkCreator'] ?? []) as $original) {
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

            $identifier = $part_props['identifier'][0] ?? '';

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

        $issues[] = [
            'issue_uri' => $issue_uri,
            'madb_id' => basename($issue_uri),
            'publisher_name' => $publisher_name,
            'magazine_name' => $magazine_name,
            'issue_label' => $issue_label,
            'issue_year' => $issue_year,
            'issue_no' => $issue_no,
            'release_date' => $release_date,
            'contents' => $contents,
        ];
    }

    usort($issues, function ($a, $b) {
        return strcmp((string)$a['release_date'], (string)$b['release_date']);
    });

    // 4. 登録処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($issues)) {
            throw new Exception('登録対象がありません。');
        }

        $pdo->beginTransaction();

        $registered_issue_count = 0;
        $existing_issue_count = 0;
        $inserted_content_count = 0;
        $skipped_content_count = 0;
        $skipped_issue_count = 0;

        foreach ($issues as $issue) {
            if ($issue['publisher_name'] === '' || $issue['magazine_name'] === '' || $issue['issue_label'] === '') {
                $skipped_issue_count++;
                continue;
            }

            $publisher_id = get_or_create_publisher($pdo, $issue['publisher_name']);
            $magazine_id = get_or_create_magazine($pdo, $publisher_id, $issue['magazine_name']);

            // 雑誌号
            $stmt = $pdo->prepare("
                SELECT id
                FROM magazine_issues
                WHERE magazine_id = :magazine_id
                  AND issue_label = :issue_label
                LIMIT 1
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id,
                ':issue_label' => $issue['issue_label']
            ]);
            $existing_issue = $stmt->fetch();

            if ($existing_issue) {
                $issue_id = (int)$existing_issue['id'];
                $existing_issue_count++;
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
                    ':issue_label' => $issue['issue_label'],
                    ':issue_year' => $issue['issue_year'] !== null ? (int)$issue['issue_year'] : null,
                    ':issue_no' => $issue['issue_no'] !== null ? (string)$issue['issue_no'] : null,
                    ':release_date' => normalize_madb_date_for_mysql($issue['release_date']),
                ]);

                $issue_id = (int)$pdo->lastInsertId();
                $registered_issue_count++;
            }

            foreach ($issue['contents'] as $content) {
                // 同じ号・同じ掲載順はスキップ
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
                    $skipped_content_count++;
                    continue;
                }

                $work_id = get_or_create_work($pdo, $content['title_main']);

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

                $display_order = 1;

                foreach ($content['creators'] as $creator_name) {
                    $creator_id = get_or_create_creator($pdo, $creator_name);

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

                $inserted_content_count++;
            }
        }

        $pdo->commit();

        $message =
            '一括登録完了：' .
            '新規雑誌号 ' . $registered_issue_count . '件 / ' .
            '既存雑誌号 ' . $existing_issue_count . '件 / ' .
            '目次追加 ' . $inserted_content_count . '件 / ' .
            '目次スキップ ' . $skipped_content_count . '件 / ' .
            '雑誌号スキップ ' . $skipped_issue_count . '件';
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();
}

$total_contents = 0;
foreach ($issues as $issue) {
    $total_contents += count($issue['contents']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MADB年単位取込 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1300px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
        }

        h2 {
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
            margin-top: 32px;
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

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin: 12px 0 24px;
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

        .note {
            color: #b00020;
            font-size: 13px;
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

    <h1>MADB年単位取込</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
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

    <div class="box">
        親ID：<?= h($parent_id) ?><br>
        対象年：<?= h($target_year) ?><br>
        取得雑誌号：<?= h(count($issues)) ?>件<br>
        取得マンガ作品：<?= h($total_contents) ?>件<br>
        親ID側出版社：<?= h($parent_publisher_name ?? '') ?><br>
        親ID側雑誌名：<?= h($parent_magazine_name ?? '') ?>
    </div>

    <?php if (!empty($issues) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <form method="post" action="madb_import_year.php?parent_id=<?= h($parent_id) ?>&year=<?= h($target_year) ?>">
            <button type="submit" onclick="return confirm('<?= h($target_year) ?>年分を一括登録しますか？');">
                この内容で<?= h($target_year) ?>年分を一括登録する
            </button>
        </form>
    <?php endif; ?>

    <?php foreach ($issues as $issue): ?>

        <h2>
            <?= h($issue['magazine_name']) ?>
            <?= h($issue['issue_label']) ?>
        </h2>

        <div class="small">
            MADB ID：<?= h($issue['madb_id']) ?> /
            出版社：<?= h($issue['publisher_name']) ?> /
            年：<?= h($issue['issue_year']) ?> /
            号数：<?= h($issue['issue_no']) ?> /
            発売日：<?= h($issue['release_date']) ?> /
            マンガ作品：<?= h(count($issue['contents'])) ?>件
        </div>

        <?php if (empty($issue['contents'])): ?>

            <p class="small">マンガ作品がありません。</p>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>順</th>
                        <th>ページ</th>
                        <th>タイトル</th>
                        <th>作者</th>
                        <th>備考</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($issue['contents'] as $row): ?>
                    <tr>
                        <td><?= h($row['sort_order']) ?></td>
                        <td><?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?></td>
                        <td><?= h($row['title_main']) ?></td>
                        <td><?= h(implode(' / ', $row['creators'])) ?></td>
                        <td class="note"><?= h($row['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    <?php endforeach; ?>

</div>
</body>
</html>