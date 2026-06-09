<?php
// works.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('作品一覧');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_page_url($page, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null, $work_type = null, $initial = null, $title_q = null) {
    if ($publisher_keyword === null) {
        $publisher_keyword = $GLOBALS['publisher_keyword'] ?? '';
    }

    if ($magazine_keyword === null) {
        $magazine_keyword = $GLOBALS['magazine_keyword'] ?? '';
    }

    if ($year_keyword === null) {
        $year_keyword = $GLOBALS['year_keyword'] ?? '';
    }

    if ($work_type === null) {
        $work_type = $GLOBALS['work_type'] ?? 'all';
    }

    if ($initial === null) {
        $initial = $GLOBALS['initial'] ?? 'all';
    }

    if ($title_q === null) {
        $title_q = $GLOBALS['title_q'] ?? '';
    }

    $query = [];

    if ($keyword !== '') {
        $query['keyword'] = $keyword;
    }

    if ($title_q !== '') {
        $query['title_q'] = $title_q;
    }

    if ($work_type !== 'all') {
        $query['work_type'] = $work_type;
    }

    if ($initial !== 'all') {
        $query['initial'] = $initial;
    }

    if ($publisher_keyword !== '') {
        $query['publisher_keyword'] = $publisher_keyword;
    }

    if ($magazine_keyword !== '') {
        $query['magazine_keyword'] = $magazine_keyword;
    }

    if ($year_keyword !== '') {
        $query['year_keyword'] = $year_keyword;
    }

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($year > 0) {
        $query['year'] = $year;
    }

    if ($sort !== 'title') {
        $query['sort'] = $sort;
    }

    if ($dir !== 'asc') {
        $query['dir'] = $dir;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'works.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function build_sort_url($target_sort, $current_sort, $current_dir, $publisher_id, $magazine_id, $year, $keyword, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null, $work_type = null, $initial = null, $title_q = null) {
    $next_dir = 'asc';

    if ($target_sort === $current_sort && $current_dir === 'asc') {
        $next_dir = 'desc';
    }

    return build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $target_sort, $next_dir, $publisher_keyword, $magazine_keyword, $year_keyword, $work_type, $initial, $title_q);
}

function sort_mark($target_sort, $current_sort, $current_dir) {
    if ($target_sort !== $current_sort) {
        return '';
    }

    return $current_dir === 'asc' ? ' ▲' : ' ▼';
}

function work_type_label($work_type) {
    $labels = [
        'all' => 'すべて',
        'serial' => '連載',
        'one_shot' => '読切',
        'unknown' => '不明',
    ];

    return $labels[$work_type] ?? $labels['all'];
}

$keyword      = '';
$title_q      = isset($_GET['title_q']) ? trim((string)$_GET['title_q']) : '';
$publisher_keyword = '';
$magazine_keyword  = '';
$year_keyword      = isset($_GET['year_keyword']) ? trim((string)$_GET['year_keyword']) : '';
$publisher_id = 0;
$magazine_id  = 0;
$year         = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page     = 100;

$allowed_work_types = ['all', 'serial', 'one_shot', 'unknown'];
$work_type = isset($_GET['work_type']) ? trim((string)$_GET['work_type']) : 'all';
if (!in_array($work_type, $allowed_work_types, true)) {
    $work_type = 'all';
}

$initial_links = [
    'all' => ['label' => 'すべて', 'pattern' => null],
    'a' => ['label' => 'あ', 'pattern' => '^[ぁ-おァ-オ]'],
    'ka' => ['label' => 'か', 'pattern' => '^[か-ごカ-ゴ]'],
    'sa' => ['label' => 'さ', 'pattern' => '^[さ-ぞサ-ゾ]'],
    'ta' => ['label' => 'た', 'pattern' => '^[た-どタ-ド]'],
    'na' => ['label' => 'な', 'pattern' => '^[な-のナ-ノ]'],
    'ha' => ['label' => 'は', 'pattern' => '^[は-ぽハ-ポ]'],
    'ma' => ['label' => 'ま', 'pattern' => '^[ま-もマ-モ]'],
    'ya' => ['label' => 'や', 'pattern' => '^[ゃ-よャ-ヨ]'],
    'ra' => ['label' => 'ら', 'pattern' => '^[ら-ろラ-ロ]'],
    'wa' => ['label' => 'わ', 'pattern' => '^[わ-んワ-ンヮ]'],
    'az' => ['label' => 'A-Z', 'pattern' => '^[A-Za-zＡ-Ｚａ-ｚ]'],
    'num' => ['label' => '0-9', 'pattern' => '^[0-9０-９]'],
    'other' => ['label' => 'その他', 'pattern' => null],
];

$initial = isset($_GET['initial']) ? trim((string)$_GET['initial']) : 'all';
if (!isset($initial_links[$initial])) {
    $initial = 'all';
}

$initial_target_sql = "COALESCE(NULLIF(w.title_kana, ''), w.title)";
$initial_patterns = [];
foreach ($initial_links as $initial_key => $initial_item) {
    if ($initial_key !== 'all' && $initial_key !== 'other' && $initial_item['pattern'] !== null) {
        $initial_patterns[] = $initial_item['pattern'];
    }
}

$allowed_sorts = ['title', 'type', 'count', 'year'];
$sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'title';
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'title';
}

$dir = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : 'asc';
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'asc';
}

$sql_dir = strtoupper($dir);
$order_by_map = [
    'title' => "w.title {$sql_dir}, w.title_sub {$sql_dir}, w.id {$sql_dir}",
    'type'  => "CASE WHEN w.publication_type IS NULL OR w.publication_type = '' THEN 1 ELSE 0 END ASC, w.publication_type {$sql_dir}, w.title ASC, w.id ASC",
    'count' => "content_count {$sql_dir}, w.title ASC, w.id ASC",
    'year'  => "first_year {$sql_dir}, last_year {$sql_dir}, w.title ASC, w.id ASC",
];
$order_by_sql = $order_by_map[$sort];

// 出版社一覧
$publisher_where = [];
$publisher_params = [];

if ($publisher_keyword !== '') {
    $publisher_where[] = "p.name LIKE :publisher_keyword";
    $publisher_params[':publisher_keyword'] = '%' . $publisher_keyword . '%';
}

$publisher_where_sql = '';
if (!empty($publisher_where)) {
    $publisher_where_sql = 'WHERE ' . implode(' AND ', $publisher_where);
}

$stmt = $pdo->prepare("
    SELECT DISTINCT
        p.id,
        p.name
    FROM publishers p
    JOIN magazines m ON p.id = m.publisher_id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    {$publisher_where_sql}
    ORDER BY p.name ASC
");
$stmt->execute($publisher_params);
$publishers = $stmt->fetchAll();

// 雑誌一覧
$magazine_where = [];
$magazine_params = [];

if ($publisher_id > 0) {
    $magazine_where[] = "m.publisher_id = :magazine_publisher_id";
    $magazine_params[':magazine_publisher_id'] = $publisher_id;
}

if ($publisher_keyword !== '') {
    $magazine_where[] = "p.name LIKE :magazine_publisher_keyword";
    $magazine_params[':magazine_publisher_keyword'] = '%' . $publisher_keyword . '%';
}

if ($magazine_keyword !== '') {
    $magazine_where[] = "m.name LIKE :magazine_keyword";
    $magazine_params[':magazine_keyword'] = '%' . $magazine_keyword . '%';
}

$magazine_where_sql = '';
if (!empty($magazine_where)) {
    $magazine_where_sql = 'WHERE ' . implode(' AND ', $magazine_where);
}

$stmt = $pdo->prepare("
    SELECT DISTINCT
        m.id,
        m.publisher_id,
        m.name,
        p.name AS publisher_name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    {$magazine_where_sql}
    ORDER BY p.name ASC, m.name ASC
");
$stmt->execute($magazine_params);
$magazines = $stmt->fetchAll();

// 年一覧
$year_where = [
    "mi.issue_year IS NOT NULL",
];
$year_params = [];

if ($year_keyword !== '') {
    $year_where[] = "CAST(mi.issue_year AS CHAR) LIKE :year_keyword";
    $year_params[':year_keyword'] = '%' . $year_keyword . '%';
}

$year_where_sql = 'WHERE ' . implode(' AND ', $year_where);

$stmt = $pdo->prepare("
    SELECT
        mi.issue_year,
        GROUP_CONCAT(DISTINCT p.id ORDER BY p.id SEPARATOR ',') AS publisher_ids,
        GROUP_CONCAT(DISTINCT m.id ORDER BY m.id SEPARATOR ',') AS magazine_ids
    FROM magazine_issues mi
    JOIN issue_contents ic ON mi.id = ic.issue_id
    JOIN works w ON ic.work_id = w.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    {$year_where_sql}
    GROUP BY mi.issue_year
    ORDER BY mi.issue_year DESC
");
$stmt->execute($year_params);
$years = $stmt->fetchAll();

// 絞り込み条件
$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = "(
        w.title LIKE :keyword_work_title
        OR w.title_sub LIKE :keyword_work_title_sub
        OR ic.title_main LIKE :keyword_content_title
        OR m.name LIKE :keyword_magazine
        OR c.name LIKE :keyword_creator
    )";
    $keyword_like = '%' . $keyword . '%';
    $params[':keyword_work_title'] = $keyword_like;
    $params[':keyword_work_title_sub'] = $keyword_like;
    $params[':keyword_content_title'] = $keyword_like;
    $params[':keyword_magazine'] = $keyword_like;
    $params[':keyword_creator'] = $keyword_like;
}

if ($title_q !== '') {
    $where[] = "w.title LIKE :title_q";
    $params[':title_q'] = '%' . $title_q . '%';
}

if ($work_type === 'serial') {
    $where[] = "w.publication_type = :work_type_serial";
    $params[':work_type_serial'] = '連載';
} elseif ($work_type === 'one_shot') {
    $where[] = "w.publication_type = :work_type_one_shot";
    $params[':work_type_one_shot'] = '読切';
} elseif ($work_type === 'unknown') {
    $where[] = "(w.publication_type IS NULL OR w.publication_type = '')";
}

if ($initial !== 'all') {
    if ($initial === 'other') {
        foreach ($initial_patterns as $index => $pattern) {
            $param_name = ':initial_other_' . $index;
            $where[] = "{$initial_target_sql} NOT REGEXP {$param_name}";
            $params[$param_name] = $pattern;
        }
    } else {
        $where[] = "{$initial_target_sql} REGEXP :initial_pattern";
        $params[':initial_pattern'] = $initial_links[$initial]['pattern'];
    }
}

if ($publisher_id > 0) {
    $where[] = "p.id = :publisher_id";
    $params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $where[] = "m.id = :magazine_id";
    $params[':magazine_id'] = $magazine_id;
}

if ($year > 0) {
    $where[] = "mi.issue_year = :issue_year";
    $params[':issue_year'] = $year;
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// 総作品数
$count_sql = "
    SELECT COUNT(DISTINCT w.id) AS total_count
    FROM works w
    JOIN issue_contents ic ON w.id = ic.work_id
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id
    {$where_sql}
";

$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = (int)($stmt->fetch()['total_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_count / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

// 作品一覧：1ページ分だけ取得
$sql = "
    SELECT
        w.id,
        w.title,
        w.title_sub,
        w.title_kana,
        w.publication_type,
        COUNT(DISTINCT ic.id) AS content_count,
        MIN(mi.issue_year) AS first_year,
        MAX(mi.issue_year) AS last_year,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ' / ') AS publisher_names,
        GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ' / ') AS magazine_names
    FROM works w
    JOIN issue_contents ic ON w.id = ic.work_id
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id
    {$where_sql}
    GROUP BY w.id, w.title, w.title_sub, w.title_kana, w.publication_type
    ORDER BY {$order_by_sql}
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$works = $stmt->fetchAll();

$selected_publisher_name = '';
$selected_magazine_name = '';

if ($publisher_id > 0) {
    $stmt = $pdo->prepare("
        SELECT name
        FROM publishers
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $publisher_id]);
    $selected_publisher_name = $stmt->fetch()['name'] ?? '';
}

if ($magazine_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            m.name,
            m.publisher_id,
            p.name AS publisher_name
        FROM magazines m
        JOIN publishers p ON m.publisher_id = p.id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $magazine_id]);
    $selected_magazine = $stmt->fetch();
    $selected_magazine_name = $selected_magazine['name'] ?? '';
}

if ($publisher_id > 0 && $selected_publisher_name !== '') {
    $has_selected_publisher = false;
    foreach ($publishers as $publisher) {
        if ((int)$publisher['id'] === $publisher_id) {
            $has_selected_publisher = true;
            break;
        }
    }

    if (!$has_selected_publisher) {
        $publishers[] = [
            'id' => $publisher_id,
            'name' => $selected_publisher_name,
        ];
    }
}

if ($magazine_id > 0 && !empty($selected_magazine)) {
    $has_selected_magazine = false;
    foreach ($magazines as $magazine) {
        if ((int)$magazine['id'] === $magazine_id) {
            $has_selected_magazine = true;
            break;
        }
    }

    if (!$has_selected_magazine) {
        $magazines[] = [
            'id' => $magazine_id,
            'publisher_id' => $selected_magazine['publisher_id'],
            'name' => $selected_magazine['name'],
            'publisher_name' => $selected_magazine['publisher_name'],
        ];
    }
}

$from_count = $total_count > 0 ? $offset + 1 : 0;
$to_count = min($offset + $per_page, $total_count);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>作品一覧 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 24px;
            background: #f5f5f5;
            color: #222;
            line-height: 1.6;
        }

        .container {
            max-width: 1148px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 20px;
            line-height: 1.3;
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

        .nav a:hover {
            text-decoration: underline;
        }

        .filter-form {
            margin: 20px 0;
            padding: 16px;
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .filter-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
        }

        .form-row.search-row {
            flex: 1 1 320px;
        }

        .filter-grid .form-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-grid .type-row {
            flex: 0 0 150px;
        }

        .filter-grid .year-row {
            flex: 0 0 300px;
            white-space: nowrap;
        }

        .filter-grid .button-row {
            flex: 0 0 auto;
        }

        .initial-filter {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e5e5e5;
        }

        .initial-filter-label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .initial-links {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .initial-links a,
        .initial-links span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
            box-sizing: border-box;
        }

        .initial-links a:hover {
            background: #f0f6ff;
            text-decoration: none;
        }

        .initial-links .is-current {
            background: #333;
            border-color: #333;
            color: #fff;
        }

        .form-row {
            min-width: 0;
        }

        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 0;
            white-space: nowrap;
        }

        .form-row input[type="text"],
        .form-row select {
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
        }

        .form-row input[type="text"] {
            min-width: 0;
            flex: 1 1 auto;
        }

        .form-row select {
            width: auto;
            min-width: 90px;
        }

        .select-keyword {
            width: 120px;
            margin-bottom: 0;
        }

        .button-row button,
        .clear-link {
            display: inline-block;
            box-sizing: border-box;
            padding: 10px 16px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            min-height: 42px;
            line-height: 1.25;
            vertical-align: middle;
        }

        .button-row button:hover,
        .clear-link:hover {
            background: #111;
            text-decoration: none;
        }

        .clear-link {
            background: #777;
            border-color: #777;
            margin-left: 6px;
        }

        .condition-box {
            margin-bottom: 16px;
            padding: 10px 12px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            color: #666;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .description-box {
            margin: 0 0 18px;
            padding: 12px 14px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.8;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
            line-height: 1.7;
            font-weight: bold;
        }

        .pagination {
            margin: 16px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            row-gap: 8px;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
            line-height: 1;
        }

        .pagination a:hover {
            background: #f0f6ff;
            text-decoration: none;
        }

        .pagination .current {
            background: #333;
            border-color: #333;
            color: #fff;
        }

        .page-jump {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 6px;
            padding-left: 10px;
        }

        .page-jump input[type="number"] {
            width: 90px;
            box-sizing: border-box;
            padding: 7px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            line-height: 1;
        }

        .page-jump button {
            padding: 7px 10px;
            border: 1px solid #333;
            border-radius: 4px;
            background: #333;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .page-jump button:hover {
            background: #111;
        }

        .pagination .disabled,
        .pagination .dots {
            color: #999;
            font-weight: normal;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        th {
            background: #eee;
            text-align: left;
        }

        .sort-link {
            color: #222;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sort-link:hover {
            color: #0066cc;
            text-decoration: underline;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .num {
            width: 90px;
            text-align: right;
            white-space: nowrap;
        }

        .type {
            width: 90px;
        }

        .year {
            width: 140px;
            white-space: nowrap;
        }

        .title-main {
            font-weight: bold;
        }

        .title-sub {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
            margin-top: 4px;
        }

        .empty {
            color: #666;
            padding: 16px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
        }

        @media (max-width: 980px) {
            body {
                padding: 8px;
            }

            .container {
                padding: 12px;
                border-radius: 6px;
            }

            .filter-grid {
                display: block;
            }

            .filter-grid .form-row {
                display: block;
            }

            .form-row {
                margin-bottom: 12px;
            }

            .form-row label {
                margin-bottom: 6px;
            }

            .form-row input[type="text"],
            .form-row select {
                width: 100%;
            }

            .select-keyword {
                width: 100%;
                margin-bottom: 8px;
            }

            .initial-links a,
            .initial-links span {
                flex: 1 0 auto;
            }

            .button-row button,
            .clear-link {
                width: 100%;
                margin: 4px 0;
                text-align: center;
            }

            .pagination {
                gap: 4px;
            }

            .pagination a,
            .pagination span {
                padding: 7px 9px;
                font-size: 14px;
            }

            .page-jump {
                width: 100%;
                margin-left: 0;
                padding-left: 0;
                margin-top: 6px;
                flex-wrap: wrap;
            }

            .page-jump input[type="number"] {
                flex: 1;
                width: auto;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            table {
                border: none;
                table-layout: auto;
            }

            thead {
                display: none;
            }

            tr {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 10px;
                border-radius: 6px;
                background: #fff;
            }

            td {
                border: none;
                padding: 5px 0;
                width: auto;
            }

            td::before {
                font-weight: bold;
                display: inline-block;
                width: 90px;
                color: #555;
            }

            td.title::before { content: "作品"; }
            td.type::before { content: "区分"; }
            td.num::before { content: "件数"; }
            td.year::before { content: "年"; }

            .num,
            .type,
            .year {
                width: auto;
                text-align: left;
                white-space: normal;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <link rel="stylesheet" href="assets/css/common.css">
    
</head>
<body>

<?php
$current_page = 'works';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1>作品一覧</h1>

    <div class="description-box">
        登録済みの漫画雑誌目次に登場する掲載作品を、作品名・区分・発行年などから探せます。各作品ページでは、どの雑誌号に掲載されたか、掲載順や収録年の範囲など、目次データに基づく掲載履歴を確認できます。
    </div>

    <form method="get" action="works.php" class="filter-form">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <?php if ($initial !== 'all'): ?>
            <input type="hidden" name="initial" value="<?= h($initial) ?>">
        <?php endif; ?>
        <div class="filter-grid">

            <div class="form-row search-row">
                <label for="title_q">作品名検索</label>
                <input type="text" name="title_q" id="title_q" value="<?= h($title_q) ?>" placeholder="作品名で検索">
            </div>

            <div class="form-row type-row">
                <label for="work_type">区分</label>
                <select name="work_type" id="work_type">
                    <option value="all" <?= ($work_type === 'all') ? 'selected' : '' ?>>すべて</option>
                    <option value="serial" <?= ($work_type === 'serial') ? 'selected' : '' ?>>連載</option>
                    <option value="one_shot" <?= ($work_type === 'one_shot') ? 'selected' : '' ?>>読切</option>
                    <option value="unknown" <?= ($work_type === 'unknown') ? 'selected' : '' ?>>不明</option>
                </select>
            </div>

            <div class="form-row year-row">
                <label>発行年</label>
                <input type="text" name="year_keyword" id="year_keyword" class="select-keyword" value="<?= h($year_keyword) ?>" placeholder="発行年ワード">
                <select name="year" id="year">
                    <option value="">指定なし</option>
                    <?php foreach ($years as $row): ?>
                        <option
                            value="<?= h($row['issue_year']) ?>"
                            data-publisher-ids=",<?= h($row['publisher_ids'] ?? '') ?>,"
                            data-magazine-ids=",<?= h($row['magazine_ids'] ?? '') ?>,"
                            <?= ($year === (int)$row['issue_year']) ? 'selected' : '' ?>
                        >
                            <?= h($row['issue_year']) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row button-row">
                <button type="submit">表示する</button>
                <a href="works.php" class="clear-link">条件クリア</a>
            </div>

        </div>

        <div class="initial-filter">
            <span class="initial-filter-label"></span>
            <div class="initial-links">
                <?php foreach ($initial_links as $initial_key => $initial_item): ?>
                    <?php if ($initial === $initial_key): ?>
                        <span class="is-current"><?= h($initial_item['label']) ?></span>
                    <?php else: ?>
                        <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir, null, null, null, null, $initial_key, null)) ?>">
                            <?= h($initial_item['label']) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="condition-box">
        条件：
        作品名：<?= h($title_q !== '' ? $title_q : '指定なし') ?> /
        区分：<?= h(work_type_label($work_type)) ?> /
        頭文字：<?= h($initial_links[$initial]['label']) ?> /
        発行年：<?= h($year > 0 ? $year . '年' : '指定なし') ?> /
        ソート：<?= h(['title' => '作品名', 'type' => '区分', 'count' => '登録件数', 'year' => '収録年'][$sort]) ?><?= h($dir === 'asc' ? ' 昇順' : ' 降順') ?>
    </div>

    <div class="count">
        全<?= h($total_count) ?>作品中 <?= h($from_count) ?>〜<?= h($to_count) ?>件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($per_page) ?>件
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination" aria-label="ページ移動">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="works.php" class="page-jump">
                <?php if ($title_q !== ''): ?>
                    <input type="hidden" name="title_q" value="<?= h($title_q) ?>">
                <?php endif; ?>
                <?php if ($work_type !== 'all'): ?>
                    <input type="hidden" name="work_type" value="<?= h($work_type) ?>">
                <?php endif; ?>
                <?php if ($initial !== 'all'): ?>
                    <input type="hidden" name="initial" value="<?= h($initial) ?>">
                <?php endif; ?>
                <?php if ($year_keyword !== ''): ?>
                    <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'title'): ?>
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <?php endif; ?>
                <?php if ($dir !== 'asc'): ?>
                    <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <?php endif; ?>
                <?php if ($year > 0): ?>
                    <input type="hidden" name="year" value="<?= h($year) ?>">
                <?php endif; ?>
                <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                <button type="submit">移動</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (empty($works)): ?>

        <p class="empty">現在、条件に合う作品データはありません。作品名、区分、頭文字、年の条件を広げると、登録済みの掲載作品を探しやすくなります。</p>

    <?php else: ?>

        <table id="worksTable">
            <thead>
                <tr>
                    <th><a class="sort-link" href="<?= h(build_sort_url('title', $sort, $dir, $publisher_id, $magazine_id, $year, $keyword)) ?>">作品名<?= h(sort_mark('title', $sort, $dir)) ?></a></th>
                    <th class="type"><a class="sort-link" href="<?= h(build_sort_url('type', $sort, $dir, $publisher_id, $magazine_id, $year, $keyword)) ?>">区分<?= h(sort_mark('type', $sort, $dir)) ?></a></th>
                    <th class="num"><a class="sort-link" href="<?= h(build_sort_url('count', $sort, $dir, $publisher_id, $magazine_id, $year, $keyword)) ?>">登録件数<?= h(sort_mark('count', $sort, $dir)) ?></a></th>
                    <th class="year"><a class="sort-link" href="<?= h(build_sort_url('year', $sort, $dir, $publisher_id, $magazine_id, $year, $keyword)) ?>">収録年<?= h(sort_mark('year', $sort, $dir)) ?></a></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($works as $row): ?>
                <tr>
                    <td class="title">
                        <a href="<?= h($keyword !== '' ? 'work.php?work_id=' . (int)$row['id'] . '&keyword=' . urlencode($keyword) : 'work.php?work_id=' . (int)$row['id']) ?>">
                            <span class="title-main"><?= h($row['title']) ?></span>
                        </a>

                        <?php if (!empty($row['title_sub'])): ?>
                            <div class="title-sub"><?= h($row['title_sub']) ?></div>
                        <?php endif; ?>

                        <div class="small">
                            <?php if (!empty($row['publisher_names'])): ?>
                                出版社：<?= h($row['publisher_names']) ?><br>
                            <?php endif; ?>

                            <?php if (!empty($row['magazine_names'])): ?>
                                雑誌：<?= h($row['magazine_names']) ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="type">
                        <?= h(($row['publication_type'] ?? '') !== '' ? $row['publication_type'] : '不明') ?>
                    </td>

                    <td class="num">
                        <?= h($row['content_count']) ?>
                    </td>

                    <td class="year">
                        <?php if ($row['first_year'] && $row['last_year']): ?>
                            <?php if ($row['first_year'] == $row['last_year']): ?>
                                <?= h($row['first_year']) ?>年
                            <?php else: ?>
                                <?= h($row['first_year']) ?>年〜<?= h($row['last_year']) ?>年
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
        <div class="pagination" aria-label="ページ移動">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $year, $keyword, $sort, $dir)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="works.php" class="page-jump">
                <?php if ($title_q !== ''): ?>
                    <input type="hidden" name="title_q" value="<?= h($title_q) ?>">
                <?php endif; ?>
                <?php if ($work_type !== 'all'): ?>
                    <input type="hidden" name="work_type" value="<?= h($work_type) ?>">
                <?php endif; ?>
                <?php if ($initial !== 'all'): ?>
                    <input type="hidden" name="initial" value="<?= h($initial) ?>">
                <?php endif; ?>
                <?php if ($year_keyword !== ''): ?>
                    <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'title'): ?>
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <?php endif; ?>
                <?php if ($dir !== 'asc'): ?>
                    <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <?php endif; ?>
                <?php if ($year > 0): ?>
                    <input type="hidden" name="year" value="<?= h($year) ?>">
                <?php endif; ?>
                <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                <button type="submit">移動</button>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
const publisherSelect = document.getElementById('publisher_id');
const magazineSelect = document.getElementById('magazine_id');
const yearSelect = document.getElementById('year');
const publisherKeywordInput = document.getElementById('publisher_keyword');
const magazineKeywordInput = document.getElementById('magazine_keyword');
const yearKeywordInput = document.getElementById('year_keyword');

function normalizeSelectKeyword(value) {
    return String(value || '').trim().toLowerCase();
}

function ensureNoResultOption(select) {
    let option = select.querySelector('option[data-no-result-option="1"]');

    if (!option) {
        option = document.createElement('option');
        option.value = '__no_result__';
        option.textContent = '対象がありません';
        option.dataset.noResultOption = '1';
        option.disabled = true;
        option.hidden = true;
        select.appendChild(option);
    }

    return option;
}

function filterSelectOptions(select, callback) {
    if (!select) {
        return;
    }

    const noResultOption = ensureNoResultOption(select);
    noResultOption.hidden = true;
    noResultOption.disabled = true;

    let visibleCount = 0;

    Array.from(select.options).forEach(option => {
        if (option.dataset.noResultOption === '1') {
            return;
        }

        if (option.value === '') {
            option.hidden = false;
            return;
        }

        const isVisible = callback(option);
        option.hidden = !isVisible;

        if (isVisible) {
            visibleCount++;
        }
    });

    const selectedOption = select.options[select.selectedIndex];

    if (selectedOption && selectedOption.hidden) {
        select.value = '';
    }

    if (visibleCount === 0) {
        Array.from(select.options).forEach(option => {
            if (option.dataset.noResultOption !== '1') {
                option.hidden = true;
            }
        });

        noResultOption.hidden = false;
        noResultOption.disabled = false;
        noResultOption.selected = true;
    } else if (select.value === '__no_result__') {
        select.value = '';
    }
}

function filterPublishersByKeyword() {
    const keyword = normalizeSelectKeyword(publisherKeywordInput ? publisherKeywordInput.value : '');

    filterSelectOptions(publisherSelect, option => {
        return keyword === '' || option.textContent.toLowerCase().includes(keyword);
    });
}

function filterMagazinesByPublisher() {
    if (!publisherSelect || !magazineSelect) {
        return;
    }

    const publisherId = publisherSelect.value;
    const publisherKeyword = normalizeSelectKeyword(publisherKeywordInput ? publisherKeywordInput.value : '');
    const magazineKeyword = normalizeSelectKeyword(magazineKeywordInput ? magazineKeywordInput.value : '');

    filterSelectOptions(magazineSelect, option => {
        const optionPublisherId = option.dataset.publisherId || '';
        const optionText = option.textContent.toLowerCase();

        if (publisherId && String(optionPublisherId) !== String(publisherId)) {
            return false;
        }

        if (publisherKeyword !== '' && !optionText.includes(publisherKeyword)) {
            return false;
        }

        if (magazineKeyword !== '' && !optionText.includes(magazineKeyword)) {
            return false;
        }

        return true;
    });
}

function filterYearsByKeyword() {
    const keyword = normalizeSelectKeyword(yearKeywordInput ? yearKeywordInput.value : '');
    const publisherId = publisherSelect ? publisherSelect.value : '';
    const magazineId = magazineSelect ? magazineSelect.value : '';

    filterSelectOptions(yearSelect, option => {
        if (keyword !== '' && !option.textContent.toLowerCase().includes(keyword)) {
            return false;
        }

        if (publisherId && !(option.dataset.publisherIds || '').includes(`,${publisherId},`)) {
            return false;
        }

        if (magazineId && !(option.dataset.magazineIds || '').includes(`,${magazineId},`)) {
            return false;
        }

        return true;
    });
}

function filterSearchSelects() {
    filterPublishersByKeyword();
    filterMagazinesByPublisher();
    filterYearsByKeyword();
}

if (publisherSelect && magazineSelect) {
    publisherSelect.addEventListener('change', filterSearchSelects);
    magazineSelect.addEventListener('change', filterYearsByKeyword);
    filterSearchSelects();
}

if (publisherKeywordInput) {
    publisherKeywordInput.addEventListener('input', filterSearchSelects);
}

if (magazineKeywordInput) {
    magazineKeywordInput.addEventListener('input', filterSearchSelects);
}

if (yearKeywordInput) {
    yearKeywordInput.addEventListener('input', filterYearsByKeyword);
}

</script>

</body>
</html>
