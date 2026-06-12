<?php
// creators.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('人物一覧');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function kana_like_values($value) {
    $value = (string)$value;
    $hira = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'c', 'UTF-8') : $value;
    $kata = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'C', 'UTF-8') : $value;

    return [
        'base' => '%' . $value . '%',
        'hira' => '%' . $hira . '%',
        'kata' => '%' . $kata . '%',
    ];
}

function build_page_url($page, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null, $initial_override = null) {
    if ($publisher_keyword === null) {
        $publisher_keyword = $GLOBALS['publisher_keyword'] ?? '';
    }

    if ($magazine_keyword === null) {
        $magazine_keyword = $GLOBALS['magazine_keyword'] ?? '';
    }

    if ($year_keyword === null) {
        $year_keyword = $GLOBALS['year_keyword'] ?? '';
    }

    $q = $GLOBALS['q'] ?? '';
    $role = $GLOBALS['role'] ?? '';
    $initial = $initial_override !== null ? $initial_override : ($GLOBALS['initial'] ?? 'all');

    $query = [];

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($publication_year !== '') {
        $query['year'] = $publication_year;
    }

    if ($keyword !== '') {
        $query['keyword'] = $keyword;
    }

    if ($q !== '') {
        $query['q'] = $q;
    }

    if ($role !== '') {
        $query['role'] = $role;
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

    if ($sort !== 'name') {
        $query['sort'] = $sort;
    }

    if ($dir !== 'asc') {
        $query['dir'] = $dir;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'creators.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function build_sort_url($sort_key, $current_sort, $current_dir, $publisher_id, $magazine_id, $publication_year, $keyword, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null) {
    $next_dir = 'asc';

    if ($current_sort === $sort_key && $current_dir === 'asc') {
        $next_dir = 'desc';
    }

    return build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort_key, $next_dir, $publisher_keyword, $magazine_keyword, $year_keyword);
}

function sort_mark($sort_key, $current_sort, $current_dir) {
    if ($current_sort !== $sort_key) {
        return '';
    }

    return $current_dir === 'asc' ? ' ▲' : ' ▼';
}

$publisher_id = 0;
$magazine_id = 0;
$publication_year = trim((string)($_GET['year'] ?? ''));
$keyword = '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$role_raw = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
$publisher_keyword = '';
$magazine_keyword = '';
$year_keyword = '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 100;

if ($publication_year !== '' && !preg_match('/^[0-9]{4}$/', $publication_year)) {
    $publication_year = '';
}

$filter_year_expr = "COALESCE(
    mi.publication_year,
    YEAR(mi.publication_date),
    YEAR(mi.release_date),
    mi.issue_year
)";

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

$initial_target_sql = "COALESCE(NULLIF(c.name_kana, ''), c.name)";
$initial_patterns = [];
foreach ($initial_links as $initial_key => $initial_item) {
    if ($initial_key !== 'all' && $initial_key !== 'other' && $initial_item['pattern'] !== null) {
        $initial_patterns[] = $initial_item['pattern'];
    }
}

$allowed_sorts = ['name', 'work_count', 'content_count', 'year'];
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'name';
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'name';
}

$dir = isset($_GET['dir']) ? strtolower((string)$_GET['dir']) : 'asc';
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'asc';
}

$dir_sql = strtoupper($dir);

$order_by_map = [
    'name' => "c.name {$dir_sql}, c.id ASC",
    'work_count' => "work_count {$dir_sql}, c.name ASC, c.id ASC",
    'content_count' => "content_count {$dir_sql}, c.name ASC, c.id ASC",
    'year' => "first_year {$dir_sql}, last_year {$dir_sql}, c.name ASC, c.id ASC",
];
$order_by_sql = $order_by_map[$sort];

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
    JOIN content_creators cc ON ic.id = cc.content_id
    {$publisher_where_sql}
    ORDER BY p.name ASC
");
$stmt->execute($publisher_params);
$publishers = $stmt->fetchAll();

$magazine_sql = "
    SELECT DISTINCT
        m.id,
        m.publisher_id,
        m.name,
        p.name AS publisher_name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    JOIN content_creators cc ON ic.id = cc.content_id
    WHERE 1 = 1
";
$magazine_params = [];

if ($publisher_id > 0) {
    $magazine_sql .= " AND m.publisher_id = :publisher_id";
    $magazine_params[':publisher_id'] = $publisher_id;
}

if ($publisher_keyword !== '') {
    $magazine_sql .= " AND p.name LIKE :magazine_publisher_keyword";
    $magazine_params[':magazine_publisher_keyword'] = '%' . $publisher_keyword . '%';
}

if ($magazine_keyword !== '') {
    $magazine_sql .= " AND m.name LIKE :magazine_keyword";
    $magazine_params[':magazine_keyword'] = '%' . $magazine_keyword . '%';
}

$magazine_sql .= " ORDER BY p.name ASC, m.name ASC";
$stmt = $pdo->prepare($magazine_sql);
$stmt->execute($magazine_params);
$magazines = $stmt->fetchAll();

$year_sql = "
    SELECT
        {$filter_year_expr} AS filter_year,
        GROUP_CONCAT(DISTINCT m.publisher_id ORDER BY m.publisher_id SEPARATOR ',') AS publisher_ids,
        GROUP_CONCAT(DISTINCT m.id ORDER BY m.id SEPARATOR ',') AS magazine_ids
    FROM magazine_issues mi
    JOIN issue_contents ic ON mi.id = ic.issue_id
    JOIN content_creators cc ON ic.id = cc.content_id
    JOIN magazines m ON mi.magazine_id = m.id
    WHERE {$filter_year_expr} IS NOT NULL
";
$year_params = [];

if ($publisher_id > 0) {
    $year_sql .= " AND m.publisher_id = :publisher_id";
    $year_params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $year_sql .= " AND mi.magazine_id = :magazine_id";
    $year_params[':magazine_id'] = $magazine_id;
}

if ($year_keyword !== '') {
    $year_sql .= " AND CAST({$filter_year_expr} AS CHAR) LIKE :year_keyword";
    $year_params[':year_keyword'] = '%' . $year_keyword . '%';
}

$year_sql .= " GROUP BY filter_year ORDER BY filter_year DESC";
$stmt = $pdo->prepare($year_sql);
$stmt->execute($year_params);
$publication_years = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT DISTINCT
        cc.role
    FROM content_creators cc
    WHERE cc.is_visible = 1
      AND cc.role IS NOT NULL
      AND cc.role <> ''
    ORDER BY cc.role ASC
");
$stmt->execute();
$role_options = $stmt->fetchAll(PDO::FETCH_COLUMN);
$role = in_array($role_raw, $role_options, true) ? $role_raw : '';

if ($publication_year !== '') {
    $year_exists = false;

    foreach ($publication_years as $row) {
        if ((string)($row['filter_year'] ?? '') === $publication_year) {
            $year_exists = true;
            break;
        }
    }

    if (!$year_exists) {
        $publication_year = '';
    }
}

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

$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = "(
        c.name LIKE :keyword_name
        OR c.name_kana LIKE :keyword_name_kana
        OR c.name_kana LIKE :keyword_name_kana_hira
        OR c.name_kana LIKE :keyword_name_kana_kata
        OR c.name_search LIKE :keyword_name_search
        OR w.title LIKE :keyword_work_title
        OR w.title_sub LIKE :keyword_work_title_sub
        OR ic.title_main LIKE :keyword_content_title
        OR m.name LIKE :keyword_magazine
    )";
    $keyword_like = '%' . $keyword . '%';
    $keyword_kana_likes = kana_like_values($keyword);
    $params[':keyword_name'] = $keyword_like;
    $params[':keyword_name_kana'] = $keyword_kana_likes['base'];
    $params[':keyword_name_kana_hira'] = $keyword_kana_likes['hira'];
    $params[':keyword_name_kana_kata'] = $keyword_kana_likes['kata'];
    $params[':keyword_name_search'] = $keyword_like;
    $params[':keyword_work_title'] = $keyword_like;
    $params[':keyword_work_title_sub'] = $keyword_like;
    $params[':keyword_content_title'] = $keyword_like;
    $params[':keyword_magazine'] = $keyword_like;
}

if ($q !== '') {
    $where[] = "(
        c.name LIKE :q_name
        OR c.name_search LIKE :q_name_search
        OR c.name_kana LIKE :q_name_kana
        OR c.name_kana LIKE :q_name_kana_hira
        OR c.name_kana LIKE :q_name_kana_kata
    )";
    $q_kana_likes = kana_like_values($q);
    $params[':q_name'] = '%' . $q . '%';
    $params[':q_name_search'] = '%' . $q . '%';
    $params[':q_name_kana'] = $q_kana_likes['base'];
    $params[':q_name_kana_hira'] = $q_kana_likes['hira'];
    $params[':q_name_kana_kata'] = $q_kana_likes['kata'];
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

if ($publisher_id > 0 || $magazine_id > 0 || $publication_year !== '' || $role !== '') {
    $exists_where = [];

    if ($publisher_id > 0) {
        $exists_where[] = "m2.publisher_id = :publisher_id";
        $params[':publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $exists_where[] = "mi2.magazine_id = :magazine_id";
        $params[':magazine_id'] = $magazine_id;
    }

    if ($publication_year !== '') {
        $exists_year_expr = str_replace('mi.', 'mi2.', $filter_year_expr);
        $exists_where[] = "{$exists_year_expr} = :publication_year";
        $params[':publication_year'] = (int)$publication_year;
    }

    if ($role !== '') {
        $exists_where[] = "cc2.role = :role";
        $params[':role'] = $role;
    }

    $where[] = "EXISTS (
        SELECT 1
        FROM content_creators cc2
        JOIN issue_contents ic2 ON cc2.content_id = ic2.id
        JOIN magazine_issues mi2 ON ic2.issue_id = mi2.id
        JOIN magazines m2 ON mi2.magazine_id = m2.id
        WHERE cc2.creator_id = c.id
          AND " . implode("\n          AND ", $exists_where) . "
    )";
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// 総人物数
$count_sql = "
    SELECT COUNT(DISTINCT c.id) AS total_count
    FROM creators c
    LEFT JOIN content_creators cc ON c.id = cc.creator_id
    LEFT JOIN issue_contents ic ON cc.content_id = ic.id
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN magazine_issues mi ON ic.issue_id = mi.id
    LEFT JOIN magazines m ON mi.magazine_id = m.id
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

$sql = "
    SELECT
        c.id,
        c.name,
        COUNT(DISTINCT ic.id) AS content_count,
        COUNT(DISTINCT w.id) AS work_count,
        MIN(mi.issue_year) AS first_year,
        MAX(mi.issue_year) AS last_year,
        GROUP_CONCAT(DISTINCT w.title ORDER BY w.title SEPARATOR ' / ') AS work_names
    FROM creators c
    LEFT JOIN content_creators cc ON c.id = cc.creator_id
    LEFT JOIN issue_contents ic ON cc.content_id = ic.id
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN magazine_issues mi ON ic.issue_id = mi.id
    LEFT JOIN magazines m ON mi.magazine_id = m.id
    {$where_sql}
    GROUP BY c.id, c.name
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
$creators = $stmt->fetchAll();

$from_count = $total_count > 0 ? $offset + 1 : 0;
$to_count = min($offset + $per_page, $total_count);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>人物一覧 - INDEXINDEX</title>
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
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 180px 130px auto;
            gap: 16px;
            align-items: end;
        }

        .search-row {
            grid-column: auto;
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
            margin-bottom: 6px;
        }

        .form-row input[type="text"],
        .form-row select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
        }

        .select-keyword {
            margin-bottom: 8px;
        }

        .select-magazine-wide,
        .search-input-wide {
            min-width: 0;
        }

        .search-input-wide {
            max-width: none;
        }

        .button-row {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
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

        .pagination .disabled,
        .pagination .dots {
            color: #999;
            font-weight: normal;
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

        th a.sort-link {
            color: #222;
            display: inline-block;
            text-decoration: none;
        }

        th a.sort-link:hover {
            color: #0066cc;
            text-decoration: underline;
        }

        .sort-mark {
            color: #0066cc;
            font-size: 12px;
            margin-left: 2px;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .creator-name {
            font-weight: bold;
        }

        .num {
            width: 90px;
            text-align: right;
            white-space: nowrap;
        }

        .year {
            width: 140px;
            white-space: nowrap;
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

        .top-button {
            position: fixed;
            right: 22px;
            bottom: 22px;
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #333, #111);
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 0.04em;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
            z-index: 1000;
        }

        .top-button.is-visible {
            opacity: 0.92;
            transform: translateY(0);
            pointer-events: auto;
        }

        .top-button:hover {
            opacity: 1;
            text-decoration: none;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3);
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            color: #888;
            font-size: 13px;
        }

        .footer a {
            color: #666;
            text-decoration: none;
            margin: 0 6px;
        }

        .footer a:hover {
            text-decoration: underline;
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

            .form-row {
                margin-bottom: 12px;
            }

            .initial-links a,
            .initial-links span {
                flex: 1 0 auto;
            }

            .search-input-wide {
                max-width: none;
            }

            .button-row {
                display: block;
                white-space: normal;
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

            td.name::before { content: "人物"; }
            td.work-count::before { content: "作品数"; }
            td.content-count::before { content: "目次件数"; }
            td.year::before { content: "年"; }

            .num,
            .year {
                width: auto;
                text-align: left;
                white-space: normal;
            }

            .top-button {
                right: 14px;
                bottom: 14px;
                width: 48px;
                height: 48px;
                font-size: 13px;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <link rel="stylesheet" href="assets/css/common.css">

</head>
<body id="top">

<?php
$current_page = 'creators';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1>人物一覧</h1>

    <div class="description-box">
        作者・原作者・構成・作画・監修など、漫画雑誌の目次データに登録された人物を探せます。人物名、役割、発行年、頭文字で絞り込み、関連する作品数や目次件数を確認できます。
    </div>

    <form method="get" action="creators.php" class="filter-form">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <?php if ($initial !== 'all'): ?>
            <input type="hidden" name="initial" value="<?= h($initial) ?>">
        <?php endif; ?>
        <div class="filter-grid">
            <div class="form-row search-row">
                <label for="q">人物名検索</label>
                <input type="text" name="q" id="q" class="search-input-wide" value="<?= h($q) ?>" placeholder="人物名で検索">
            </div>

            <div class="form-row">
                <label for="role">役割</label>
                <select name="role" id="role">
                    <option value="">すべて</option>
                    <?php foreach ($role_options as $role_option): ?>
                        <option value="<?= h($role_option) ?>" <?= ($role === $role_option) ? 'selected' : '' ?>>
                            <?= h($role_option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="year">発行年</label>
                <select name="year" id="year">
                    <option value="">すべて</option>
                    <?php foreach ($publication_years as $row): ?>
                        <?php if ($row['filter_year'] === null || $row['filter_year'] === '') continue; ?>
                        <?php $filter_year = (string)$row['filter_year']; ?>
                        <option value="<?= h($filter_year) ?>" <?= ($publication_year === $filter_year) ? 'selected' : '' ?>>
                            <?= h($filter_year) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row button-row">
                <button type="submit">表示する</button>
                <a href="creators.php" class="clear-link">条件クリア</a>
            </div>
        </div>

        <div class="initial-filter">
            <span class="initial-filter-label">頭文字</span>
            <div class="initial-links">
                <?php foreach ($initial_links as $initial_key => $initial_item): ?>
                    <?php if ($initial === $initial_key): ?>
                        <span class="is-current"><?= h($initial_item['label']) ?></span>
                    <?php else: ?>
                        <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir, null, null, null, $initial_key)) ?>">
                            <?= h($initial_item['label']) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="condition-box">
        条件：
        人物名：<?= h($q !== '' ? $q : '指定なし') ?> /
        役割：<?= h($role !== '' ? $role : 'すべて') ?> /
        頭文字：<?= h($initial_links[$initial]['label']) ?> /
        発行年：<?= h($publication_year !== '' ? $publication_year . '年' : '指定なし') ?><br>
        並び順：<?php
            $sort_labels = [
                'name' => '人物名',
                'work_count' => '作品数',
                'content_count' => '目次件数',
                'year' => '収録年',
            ];
            echo h($sort_labels[$sort] ?? '人物名');
            echo h($dir === 'asc' ? ' 昇順' : ' 降順');
        ?>
    </div>

    <div class="count">
        全<?= h($total_count) ?>人中 <?= h($from_count) ?>〜<?= h($to_count) ?>件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($per_page) ?>件
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination" aria-label="ページ移動">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">...</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="creators.php" class="page-jump">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                <?php endif; ?>
                <?php if ($role !== ''): ?>
                    <input type="hidden" name="role" value="<?= h($role) ?>">
                <?php endif; ?>
                <?php if ($initial !== 'all'): ?>
                    <input type="hidden" name="initial" value="<?= h($initial) ?>">
                <?php endif; ?>
                <?php if ($publication_year !== ''): ?>
                    <input type="hidden" name="year" value="<?= h($publication_year) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'name'): ?>
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <?php endif; ?>
                <?php if ($dir !== 'asc'): ?>
                    <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <?php endif; ?>
                <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                <button type="submit">移動</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (empty($creators)): ?>

        <p class="empty">現在、条件に合う人物データはありません。人物名、役割、発行年、頭文字の条件を変えると、登録済みの作者・関係者を探しやすくなります。</p>

    <?php else: ?>

        <table id="creatorsTable">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" href="<?= h(build_sort_url('name', $sort, $dir, $publisher_id, $magazine_id, $publication_year, $keyword)) ?>">
                            人物名<span class="sort-mark"><?= h(sort_mark('name', $sort, $dir)) ?></span>
                        </a>
                    </th>
                    <th class="num">
                        <a class="sort-link" href="<?= h(build_sort_url('work_count', $sort, $dir, $publisher_id, $magazine_id, $publication_year, $keyword)) ?>">
                            作品数<span class="sort-mark"><?= h(sort_mark('work_count', $sort, $dir)) ?></span>
                        </a>
                    </th>
                    <th class="num">
                        <a class="sort-link" href="<?= h(build_sort_url('content_count', $sort, $dir, $publisher_id, $magazine_id, $publication_year, $keyword)) ?>">
                            目次件数<span class="sort-mark"><?= h(sort_mark('content_count', $sort, $dir)) ?></span>
                        </a>
                    </th>
                    <th class="year">
                        <a class="sort-link" href="<?= h(build_sort_url('year', $sort, $dir, $publisher_id, $magazine_id, $publication_year, $keyword)) ?>">
                            収録年<span class="sort-mark"><?= h(sort_mark('year', $sort, $dir)) ?></span>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($creators as $row): ?>
                <tr>
                    <td class="name">
                        <a href="<?= h($keyword !== '' ? 'creator.php?creator_id=' . (int)$row['id'] . '&keyword=' . urlencode($keyword) : 'creator.php?creator_id=' . (int)$row['id']) ?>">
                            <span class="creator-name"><?= h($row['name']) ?></span>
                        </a>

                        <?php if (!empty($row['work_names'])): ?>
                            <div class="small">
                                主な作品：<?= h($row['work_names']) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="work-count num">
                        <?= h($row['work_count']) ?>
                    </td>

                    <td class="content-count num">
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
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">...</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $keyword, $sort, $dir)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="creators.php" class="page-jump">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                <?php endif; ?>
                <?php if ($role !== ''): ?>
                    <input type="hidden" name="role" value="<?= h($role) ?>">
                <?php endif; ?>
                <?php if ($initial !== 'all'): ?>
                    <input type="hidden" name="initial" value="<?= h($initial) ?>">
                <?php endif; ?>
                <?php if ($publication_year !== ''): ?>
                    <input type="hidden" name="year" value="<?= h($publication_year) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'name'): ?>
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <?php endif; ?>
                <?php if ($dir !== 'asc'): ?>
                    <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <?php endif; ?>
                <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                <button type="submit">移動</button>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<a href="#top" class="top-button" id="topButton" aria-label="TOPへ戻る">TOP</a>

<script>
const topButton = document.getElementById('topButton');

function toggleTopButton() {
    if (!topButton) {
        return;
    }

    if (window.scrollY > 240) {
        topButton.classList.add('is-visible');
    } else {
        topButton.classList.remove('is-visible');
    }
}

window.addEventListener('scroll', toggleTopButton, { passive: true });
toggleTopButton();
</script>

</body>
</html>
