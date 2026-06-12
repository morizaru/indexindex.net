<?php
// issues.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('雑誌一覧');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function issue_label_with_special($issue) {
    $label = (string)($issue['issue_label'] ?? '');
    $special_label = trim((string)($issue['issue_label_special'] ?? ''));

    if ($special_label !== '') {
        $label .= '（' . $special_label . '）';
    }

    return $label;
}

function build_issue_page_url($page, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction, $filter_magazine_id = null, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null) {
    if ($filter_magazine_id === null) {
        $filter_magazine_id = $GLOBALS['filter_magazine_id'] ?? 0;
    }

    if ($publisher_keyword === null) {
        $publisher_keyword = $GLOBALS['publisher_keyword'] ?? '';
    }

    if ($magazine_keyword === null) {
        $magazine_keyword = $GLOBALS['magazine_keyword'] ?? '';
    }

    if ($year_keyword === null) {
        $year_keyword = $GLOBALS['year_keyword'] ?? '';
    }

    $query = [];

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($filter_magazine_id > 0) {
        $query['filter_magazine_id'] = $filter_magazine_id;
    }

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($year > 0) {
        $query['year'] = $year;
    }

    if ($keyword !== '') {
        $query['keyword'] = $keyword;
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

    if ($sort !== 'magazine') {
        $query['sort'] = $sort;
    }

    if ($direction !== 'asc') {
        $query['direction'] = $direction;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'issues.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function build_issues_sort_url($sort_key, $current_sort, $current_direction, $publisher_id, $year, $keyword, $filter_magazine_id = null, $publisher_keyword = null, $magazine_keyword = null, $year_keyword = null) {
    if ($filter_magazine_id === null) {
        $filter_magazine_id = $GLOBALS['filter_magazine_id'] ?? 0;
    }

    if ($publisher_keyword === null) {
        $publisher_keyword = $GLOBALS['publisher_keyword'] ?? '';
    }

    if ($magazine_keyword === null) {
        $magazine_keyword = $GLOBALS['magazine_keyword'] ?? '';
    }

    if ($year_keyword === null) {
        $year_keyword = $GLOBALS['year_keyword'] ?? '';
    }

    $query = [];

    if ($filter_magazine_id > 0) {
        $query['filter_magazine_id'] = $filter_magazine_id;
    }

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($year > 0) {
        $query['year'] = $year;
    }

    if ($keyword !== '') {
        $query['keyword'] = $keyword;
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

    $query['sort'] = $sort_key;

    if ($current_sort === $sort_key && $current_direction === 'asc') {
        $query['direction'] = 'desc';
    } else {
        $query['direction'] = 'asc';
    }

    return 'issues.php?' . http_build_query($query);
}

function sort_mark($sort_key, $current_sort, $current_direction) {
    if ($current_sort !== $sort_key) {
        return '';
    }

    return $current_direction === 'desc' ? ' ▼' : ' ▲';
}

$magazine_id = isset($_GET['magazine_id']) ? (int)$_GET['magazine_id'] : 0;

$publisher_id = isset($_GET['publisher_id']) ? (int)$_GET['publisher_id'] : 0;
$filter_magazine_id = isset($_GET['filter_magazine_id']) ? (int)$_GET['filter_magazine_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$keyword = '';
$publisher_keyword = isset($_GET['publisher_keyword']) ? trim((string)$_GET['publisher_keyword']) : '';
$magazine_keyword = isset($_GET['magazine_keyword']) ? trim((string)$_GET['magazine_keyword']) : '';
$year_keyword = isset($_GET['year_keyword']) ? trim((string)$_GET['year_keyword']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;

$sort = $_GET['sort'] ?? 'magazine';
$direction = strtolower($_GET['direction'] ?? 'asc');

$allowed_sorts = ['magazine', 'publisher'];
$allowed_directions = ['asc', 'desc'];

if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'magazine';
}

if (!in_array($direction, $allowed_directions, true)) {
    $direction = 'asc';
}

if ($sort === 'magazine') {
    $magazine_order_sql = "m.name {$direction}, p.name ASC, m.id ASC";
} else {
    $magazine_order_sql = "p.name {$direction}, m.name ASC, m.id ASC";
}

$magazines = [];
$issues = [];
$current_magazine = null;
$total_magazines = 0;
$total_issues = 0;
$total_pages = 1;
$offset = 0;
$from_count = 0;
$to_count = 0;

// 発行年算出SQL
$publication_year_sql = "
    CASE
        WHEN mi.publication_year IS NOT NULL THEN mi.publication_year
        WHEN mi.publication_date IS NOT NULL
             AND mi.publication_date <> ''
             AND mi.publication_date <> '0000-00-00'
        THEN YEAR(mi.publication_date)
        WHEN TRIM(mi.issue_label) REGEXP '^[0-9]{4}年[0-9]{1,2}月[0-9]{1,2}日'
        THEN YEAR(
            STR_TO_DATE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(
                                    TRIM(mi.issue_label),
                                    '号',
                                    1
                                ),
                                '増刊',
                                1
                            ),
                            '年',
                            '-'
                        ),
                        '月',
                        '-'
                    ),
                    '日',
                    ''
                ),
                '%Y-%c-%e'
            )
        )
        ELSE mi.issue_year
    END
";

// 出版社一覧：目次が1件以上ある公開済み雑誌号だけを対象にする
$publisher_where = [
    "mi.status = 'approved'",
];
$publisher_params = [];

if ($publisher_keyword !== '') {
    $publisher_where[] = "p.name LIKE :publisher_keyword";
    $publisher_params[':publisher_keyword'] = '%' . $publisher_keyword . '%';
}

$publisher_where_sql = 'WHERE ' . implode(' AND ', $publisher_where);

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

$magazine_option_where = [
    "mi.status = 'approved'",
];
$magazine_option_params = [];

if ($publisher_id > 0) {
    $magazine_option_where[] = "m.publisher_id = :magazine_option_publisher_id";
    $magazine_option_params[':magazine_option_publisher_id'] = $publisher_id;
}

if ($publisher_keyword !== '') {
    $magazine_option_where[] = "p.name LIKE :magazine_option_publisher_keyword";
    $magazine_option_params[':magazine_option_publisher_keyword'] = '%' . $publisher_keyword . '%';
}

if ($magazine_keyword !== '') {
    $magazine_option_where[] = "m.name LIKE :magazine_keyword";
    $magazine_option_params[':magazine_keyword'] = '%' . $magazine_keyword . '%';
}

$magazine_option_where_sql = 'WHERE ' . implode(' AND ', $magazine_option_where);

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
    {$magazine_option_where_sql}
    ORDER BY p.name ASC, m.name ASC
");
$stmt->execute($magazine_option_params);
$magazine_options = $stmt->fetchAll();

// 年一覧：発行年ベース。目次が1件以上ある公開済み雑誌号だけを対象にする
$year_where = [
    "mi.status = 'approved'",
    "{$publication_year_sql} IS NOT NULL",
];
$year_params = [];

if ($year_keyword !== '') {
    $year_where[] = "CAST({$publication_year_sql} AS CHAR) LIKE :year_keyword";
    $year_params[':year_keyword'] = '%' . $year_keyword . '%';
}

$year_where_sql = 'WHERE ' . implode(' AND ', $year_where);

$stmt = $pdo->prepare("
    SELECT
        {$publication_year_sql} AS display_year,
        GROUP_CONCAT(DISTINCT p.id ORDER BY p.id SEPARATOR ',') AS publisher_ids,
        GROUP_CONCAT(DISTINCT m.id ORDER BY m.id SEPARATOR ',') AS magazine_ids
    FROM magazine_issues mi
    JOIN issue_contents ic ON mi.id = ic.issue_id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    {$year_where_sql}
    GROUP BY display_year
    ORDER BY display_year DESC
");
$stmt->execute($year_params);
$years = $stmt->fetchAll();

// 雑誌一覧用の絞り込み条件
$where = [];
$params = [];

$where[] = "mi.status = 'approved'";

if ($publisher_id > 0) {
    $where[] = "p.id = :publisher_id";
    $params[':publisher_id'] = $publisher_id;
} elseif ($publisher_keyword !== '') {
    $where[] = "p.name LIKE :keyword_publisher_word";
    $params[':keyword_publisher_word'] = '%' . $publisher_keyword . '%';
}

if ($filter_magazine_id > 0) {
    $where[] = "m.id = :filter_magazine_id";
    $params[':filter_magazine_id'] = $filter_magazine_id;
} elseif ($magazine_keyword !== '') {
    $where[] = "m.name LIKE :keyword_magazine_word";
    $params[':keyword_magazine_word'] = '%' . $magazine_keyword . '%';
}

if ($year > 0) {
    $where[] = "{$publication_year_sql} = :display_year";
    $params[':display_year'] = $year;
} elseif ($year_keyword !== '') {
    $where[] = "CAST({$publication_year_sql} AS CHAR) LIKE :keyword_year_word";
    $params[':keyword_year_word'] = '%' . $year_keyword . '%';
}

if ($keyword !== '') {
    $where[] = "(
        m.name LIKE :keyword_magazine
        OR mi.issue_label LIKE :keyword_issue_label
        OR mi.issue_label_special LIKE :keyword_issue_label_special
        OR w.title LIKE :keyword_work_title
        OR w.title_sub LIKE :keyword_work_title_sub
        OR ic.title_main LIKE :keyword_content_title
        OR c.name LIKE :keyword_creator
    )";
    $keyword_like = '%' . $keyword . '%';
    $params[':keyword_magazine'] = $keyword_like;
    $params[':keyword_issue_label'] = $keyword_like;
    $params[':keyword_issue_label_special'] = $keyword_like;
    $params[':keyword_work_title'] = $keyword_like;
    $params[':keyword_work_title_sub'] = $keyword_like;
    $params[':keyword_content_title'] = $keyword_like;
    $params[':keyword_creator'] = $keyword_like;
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$count_magazines_sql = "
    SELECT COUNT(DISTINCT m.id) AS total_count
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id
    {$where_sql}
";
$stmt = $pdo->prepare($count_magazines_sql);
$stmt->execute($params);
$total_magazines = (int)($stmt->fetch()['total_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_magazines / $perPage));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $perPage;
$from_count = $total_magazines > 0 ? $offset + 1 : 0;
$to_count = min($offset + $perPage, $total_magazines);

// 雑誌一覧
$sql_magazines = "
    SELECT
        m.id,
        m.name AS magazine_name,
        p.name AS publisher_name,
        COUNT(DISTINCT mi.id) AS issue_count,
        COUNT(DISTINCT ic.id) AS content_count,
        MIN({$publication_year_sql}) AS first_year,
        MAX({$publication_year_sql}) AS last_year
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id
    {$where_sql}
    GROUP BY m.id, m.name, p.name
    HAVING content_count > 0
    ORDER BY {$magazine_order_sql}
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql_magazines);
foreach ($params as $param_name => $param_value) {
    if (strpos($param_name, ':keyword_') === 0) {
        $stmt->bindValue($param_name, $param_value, PDO::PARAM_STR);
    } else {
        $stmt->bindValue($param_name, $param_value, PDO::PARAM_INT);
    }
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$magazines = $stmt->fetchAll();

$selected_publisher_name = '';
$selected_filter_magazine_name = '';
$selected_filter_magazine = null;

if ($publisher_id > 0) {
    $stmt = $pdo->prepare("
        SELECT name
        FROM publishers
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $publisher_id
    ]);
    $selected_publisher_name = $stmt->fetch()['name'] ?? '';
}

if ($filter_magazine_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.publisher_id,
            m.name,
            p.name AS publisher_name
        FROM magazines m
        JOIN publishers p ON m.publisher_id = p.id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $filter_magazine_id
    ]);
    $selected_filter_magazine = $stmt->fetch();
    $selected_filter_magazine_name = $selected_filter_magazine['name'] ?? '';
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

if ($filter_magazine_id > 0 && !empty($selected_filter_magazine)) {
    $has_selected_magazine = false;
    foreach ($magazine_options as $magazine) {
        if ((int)$magazine['id'] === $filter_magazine_id) {
            $has_selected_magazine = true;
            break;
        }
    }

    if (!$has_selected_magazine) {
        $magazine_options[] = [
            'id' => $filter_magazine_id,
            'publisher_id' => $selected_filter_magazine['publisher_id'],
            'name' => $selected_filter_magazine['name'],
            'publisher_name' => $selected_filter_magazine['publisher_name'],
        ];
    }
}

if ($magazine_id > 0) {
    // 選択中の雑誌情報
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.name AS magazine_name,
            p.name AS publisher_name
        FROM magazines m
        JOIN publishers p ON m.publisher_id = p.id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $magazine_id
    ]);
    $current_magazine = $stmt->fetch();

    $issue_keyword_where_sql = '';
    $issue_keyword_params = [];
    if ($keyword !== '') {
        $issue_keyword_where_sql = "
          AND (
              m.name LIKE :issue_keyword_magazine
              OR mi.issue_label LIKE :issue_keyword_label
              OR mi.issue_label_special LIKE :issue_keyword_label_special
              OR EXISTS (
                  SELECT 1
                  FROM issue_contents ic_kw
                  LEFT JOIN works w_kw ON ic_kw.work_id = w_kw.id
                  LEFT JOIN content_creators cc_kw ON ic_kw.id = cc_kw.content_id AND cc_kw.is_visible = 1
                  LEFT JOIN creators c_kw ON cc_kw.creator_id = c_kw.id
                  WHERE ic_kw.issue_id = mi.id
                    AND (
                        w_kw.title LIKE :issue_keyword_work_title
                        OR w_kw.title_sub LIKE :issue_keyword_work_title_sub
                        OR ic_kw.title_main LIKE :issue_keyword_content_title
                        OR c_kw.name LIKE :issue_keyword_creator
                    )
              )
          )
        ";
        $issue_keyword_like = '%' . $keyword . '%';
        $issue_keyword_params = [
            ':issue_keyword_magazine' => $issue_keyword_like,
            ':issue_keyword_label' => $issue_keyword_like,
            ':issue_keyword_label_special' => $issue_keyword_like,
            ':issue_keyword_work_title' => $issue_keyword_like,
            ':issue_keyword_work_title_sub' => $issue_keyword_like,
            ':issue_keyword_content_title' => $issue_keyword_like,
            ':issue_keyword_creator' => $issue_keyword_like,
        ];
    }

    if ($year > 0) {
        $issue_keyword_where_sql .= "
          AND {$publication_year_sql} = :issue_display_year
        ";
        $issue_keyword_params[':issue_display_year'] = $year;
    }

    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT mi.id) AS total_count
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN issue_contents ic ON mi.id = ic.issue_id
        WHERE mi.magazine_id = :magazine_id
          AND mi.status = 'approved'
          {$issue_keyword_where_sql}
    ");
    $count_stmt->execute(array_merge([
        ':magazine_id' => $magazine_id
    ], $issue_keyword_params));
    $total_issues = (int)($count_stmt->fetch()['total_count'] ?? 0);
    $total_pages = max(1, (int)ceil($total_issues / $perPage));

    if ($page > $total_pages) {
        $page = $total_pages;
    }

    $offset = ($page - 1) * $perPage;
    $from_count = $total_issues > 0 ? $offset + 1 : 0;
    $to_count = min($offset + $perPage, $total_issues);

    // その雑誌の号一覧
    $stmt = $pdo->prepare("
        SELECT
            mi.id,
            mi.issue_label,
            mi.issue_label_special,
            mi.issue_year,
            mi.issue_no,
            mi.publication_date,
            mi.publication_year,
            mi.publication_month,
            mi.publication_day,
            mi.release_date,
            {$publication_year_sql} AS display_year,
            COUNT(ic.id) AS content_count
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN issue_contents ic ON mi.id = ic.issue_id
        WHERE mi.magazine_id = :magazine_id
          AND mi.status = 'approved'
          {$issue_keyword_where_sql}
        GROUP BY mi.id
        ORDER BY
            CASE
                WHEN mi.publication_date IS NOT NULL
                     AND mi.publication_date <> ''
                     AND mi.publication_date <> '0000-00-00'
                THEN mi.publication_date
                WHEN TRIM(mi.issue_label) REGEXP '^[0-9]{4}年[0-9]{1,2}月[0-9]{1,2}日'
                THEN STR_TO_DATE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                SUBSTRING_INDEX(
                                    SUBSTRING_INDEX(
                                        TRIM(mi.issue_label),
                                        '号',
                                        1
                                    ),
                                    '増刊',
                                    1
                                ),
                                '年',
                                '-'
                            ),
                            '月',
                            '-'
                        ),
                        '日',
                        ''
                    ),
                    '%Y-%c-%e'
                )
                WHEN mi.release_date IS NOT NULL
                     AND mi.release_date <> ''
                     AND mi.release_date <> '0000-00-00'
                THEN mi.release_date
                ELSE '9999-12-31'
            END DESC,
            mi.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':magazine_id', $magazine_id, PDO::PARAM_INT);
    foreach ($issue_keyword_params as $param_name => $param_value) {
        $stmt->bindValue($param_name, $param_value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $issues = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌一覧 - INDEXINDEX</title>
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

        h2 {
            margin-top: 28px;
            margin-bottom: 12px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
            line-height: 1.35;
        }

        .nav {
            margin-bottom: 20px;
            line-height: 1.9;
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

        .magazine-search {
            margin-bottom: 16px;
        }

        .magazine-search label,
        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .magazine-search input,
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

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(260px, 1.25fr) 140px auto;
            gap: 16px;
            align-items: end;
        }

        .form-row {
            min-width: 0;
        }

        .select-keyword {
            margin-bottom: 8px;
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
            margin: 16px 0;
            color: #666;
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
            margin-top: 12px;
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
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }

        th a.sort-link:hover {
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
            width: 100px;
            text-align: right;
            white-space: nowrap;
        }

        .year {
            width: 140px;
            text-align: center;
            white-space: nowrap;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.6;
        }

        .issue-meta {
            color: #666;
            font-size: 13px;
            line-height: 1.7;
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
                width: 100px;
                color: #555;
            }

            td.magazine::before { content: "雑誌"; }
            td.publisher::before { content: "出版社"; }
            td.issue::before { content: "号"; }
            td.year::before { content: "年"; }
            td.num::before { content: "件数"; }

            .num,
            .year {
                width: auto;
                text-align: left;
                white-space: normal;
            }

            .footer {
                margin-top: 20px;
                line-height: 1.8;
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
$current_page = 'issues';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1>雑誌一覧</h1>

    <div class="description-box">
        目次が登録されている漫画雑誌と雑誌号を一覧できます。出版社、発行年、雑誌名で絞り込み、各号の掲載作品数や目次ページへ進めます。
    </div>

    <?php if ($magazine_id <= 0): ?>

        <form method="get" action="issues.php" class="filter-form">
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
            <input type="hidden" name="direction" value="<?= h($direction) ?>">

            <div class="filter-grid">

                <div class="form-row">
                    <label>出版社</label>
                    <input type="text" name="publisher_keyword" id="publisher_keyword" class="select-keyword" value="<?= h($publisher_keyword) ?>" placeholder="出版社ワード">
                    <select name="publisher_id" id="publisher_id">
                        <option value="">指定なし</option>
                        <?php foreach ($publishers as $publisher): ?>
                            <option value="<?= h($publisher['id']) ?>" <?= ($publisher_id === (int)$publisher['id']) ? 'selected' : '' ?>>
                                <?= h($publisher['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label>雑誌</label>
                    <input type="text" name="magazine_keyword" id="magazine_keyword" class="select-keyword" value="<?= h($magazine_keyword) ?>" placeholder="雑誌ワード">
                    <select name="filter_magazine_id" id="filter_magazine_id">
                        <option value="">指定なし</option>
                        <?php foreach ($magazine_options as $magazine): ?>
                            <option
                                value="<?= h($magazine['id']) ?>"
                                data-publisher-id="<?= h($magazine['publisher_id']) ?>"
                                <?= ($filter_magazine_id === (int)$magazine['id']) ? 'selected' : '' ?>
                            >
                                <?= h($magazine['publisher_name']) ?> / <?= h($magazine['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label>発行年</label>
                    <input type="text" name="year_keyword" id="year_keyword" class="select-keyword" value="<?= h($year_keyword) ?>" placeholder="発行年ワード">
                    <select name="year" id="year">
                        <option value="">指定なし</option>
                        <?php foreach ($years as $row): ?>
                            <option
                                value="<?= h($row['display_year']) ?>"
                                data-publisher-ids=",<?= h($row['publisher_ids'] ?? '') ?>,"
                                data-magazine-ids=",<?= h($row['magazine_ids'] ?? '') ?>,"
                                <?= ($year === (int)$row['display_year']) ? 'selected' : '' ?>
                            >
                                <?= h($row['display_year']) ?>年
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row button-row">
                    <button type="submit">表示する</button>
                    <a href="issues.php" class="clear-link">クリア</a>
                </div>

            </div>
        </form>

        <div class="condition-box">
            条件：
            出版社：<?= h($selected_publisher_name !== '' ? $selected_publisher_name : '指定なし') ?> /
            雑誌：<?= h($selected_filter_magazine_name !== '' ? $selected_filter_magazine_name : '指定なし') ?> /
            発行年：<?= h($year > 0 ? $year . '年' : '指定なし') ?>
        </div>

        <div class="count">
            全<?= h($total_magazines) ?>誌中 <?= h($from_count) ?>〜<?= h($to_count) ?>件を表示<br>
            <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($perPage) ?>件
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination" aria-label="ページ移動">
                <?php if ($page > 1): ?>
                    <a href="<?= h(build_issue_page_url(1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最初</a>
                    <a href="<?= h(build_issue_page_url($page - 1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">前へ</a>
                <?php else: ?>
                    <span class="disabled">最初</span>
                    <span class="disabled">前へ</span>
                <?php endif; ?>

                <?php
                    $start_page = max(1, $page - 3);
                    $end_page = min($total_pages, $page + 3);
                ?>

                <?php if ($start_page > 1): ?>
                    <a href="<?= h(build_issue_page_url(1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">1</a>
                    <?php if ($start_page > 2): ?>
                        <span class="dots">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= h($i) ?></span>
                    <?php else: ?>
                        <a href="<?= h(build_issue_page_url($i, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($i) ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <span class="dots">…</span>
                    <?php endif; ?>
                    <a href="<?= h(build_issue_page_url($total_pages, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($total_pages) ?></a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= h(build_issue_page_url($page + 1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">次へ</a>
                    <a href="<?= h(build_issue_page_url($total_pages, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最後</a>
                <?php else: ?>
                    <span class="disabled">次へ</span>
                    <span class="disabled">最後</span>
                <?php endif; ?>

                <form method="get" action="issues.php" class="page-jump">
                    <?php if ($filter_magazine_id > 0): ?>
                        <input type="hidden" name="filter_magazine_id" value="<?= h($filter_magazine_id) ?>">
                    <?php endif; ?>
                    <?php if ($publisher_id > 0): ?>
                        <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                    <?php endif; ?>
                    <?php if ($year > 0): ?>
                        <input type="hidden" name="year" value="<?= h($year) ?>">
                    <?php endif; ?>
                    <?php if ($publisher_keyword !== ''): ?>
                        <input type="hidden" name="publisher_keyword" value="<?= h($publisher_keyword) ?>">
                    <?php endif; ?>
                    <?php if ($magazine_keyword !== ''): ?>
                        <input type="hidden" name="magazine_keyword" value="<?= h($magazine_keyword) ?>">
                    <?php endif; ?>
                    <?php if ($year_keyword !== ''): ?>
                        <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                    <?php endif; ?>
                    <?php if ($sort !== 'magazine'): ?>
                        <input type="hidden" name="sort" value="<?= h($sort) ?>">
                    <?php endif; ?>
                    <?php if ($direction !== 'asc'): ?>
                        <input type="hidden" name="direction" value="<?= h($direction) ?>">
                    <?php endif; ?>
                    <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                    <button type="submit">移動</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (empty($magazines)): ?>

            <p class="empty">現在、条件に合う雑誌データはありません。出版社、発行年、雑誌名の条件を広げると、登録済みの漫画雑誌号を探しやすくなります。</p>

        <?php else: ?>

            <table id="magazinesTable">
                <thead>
                    <tr>
                        <th>
                            <a class="sort-link" href="<?= h(build_issues_sort_url('magazine', $sort, $direction, $publisher_id, $year, $keyword)) ?>">
                                雑誌名<?= h(sort_mark('magazine', $sort, $direction)) ?>
                            </a>
                        </th>
                        <th>
                            <a class="sort-link" href="<?= h(build_issues_sort_url('publisher', $sort, $direction, $publisher_id, $year, $keyword)) ?>">
                                出版社<?= h(sort_mark('publisher', $sort, $direction)) ?>
                            </a>
                        </th>
                        <th class="num">登録号数</th>
                        <th class="year">収録年</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($magazines as $row): ?>
                    <tr>
                        <td class="magazine">
                            <a href="<?= h(build_issue_page_url(1, (int)$row['id'], $publisher_id, $year, $keyword, $sort, $direction)) ?>">
                                <?= h($row['magazine_name']) ?>
                            </a>
                            <div class="small">ID：<?= h($row['id']) ?></div>
                        </td>

                        <td class="publisher"><?= h($row['publisher_name']) ?></td>

                        <td class="num"><?= h($row['issue_count']) ?></td>

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

            <?php if ($total_pages > 1): ?>
                <div class="pagination" aria-label="ページ移動">
                    <?php if ($page > 1): ?>
                        <a href="<?= h(build_issue_page_url(1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最初</a>
                        <a href="<?= h(build_issue_page_url($page - 1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">前へ</a>
                    <?php else: ?>
                        <span class="disabled">最初</span>
                        <span class="disabled">前へ</span>
                    <?php endif; ?>

                    <?php
                        $start_page = max(1, $page - 3);
                        $end_page = min($total_pages, $page + 3);
                    ?>

                    <?php if ($start_page > 1): ?>
                        <a href="<?= h(build_issue_page_url(1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="dots">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= h($i) ?></span>
                        <?php else: ?>
                            <a href="<?= h(build_issue_page_url($i, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($i) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="dots">…</span>
                        <?php endif; ?>
                        <a href="<?= h(build_issue_page_url($total_pages, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($total_pages) ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?= h(build_issue_page_url($page + 1, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">次へ</a>
                        <a href="<?= h(build_issue_page_url($total_pages, 0, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最後</a>
                    <?php else: ?>
                        <span class="disabled">次へ</span>
                        <span class="disabled">最後</span>
                    <?php endif; ?>

                    <form method="get" action="issues.php" class="page-jump">
                        <?php if ($filter_magazine_id > 0): ?>
                            <input type="hidden" name="filter_magazine_id" value="<?= h($filter_magazine_id) ?>">
                        <?php endif; ?>
                        <?php if ($publisher_id > 0): ?>
                            <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                        <?php endif; ?>
                        <?php if ($year > 0): ?>
                            <input type="hidden" name="year" value="<?= h($year) ?>">
                        <?php endif; ?>
                        <?php if ($publisher_keyword !== ''): ?>
                            <input type="hidden" name="publisher_keyword" value="<?= h($publisher_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($magazine_keyword !== ''): ?>
                            <input type="hidden" name="magazine_keyword" value="<?= h($magazine_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($year_keyword !== ''): ?>
                            <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($sort !== 'magazine'): ?>
                            <input type="hidden" name="sort" value="<?= h($sort) ?>">
                        <?php endif; ?>
                        <?php if ($direction !== 'asc'): ?>
                            <input type="hidden" name="direction" value="<?= h($direction) ?>">
                        <?php endif; ?>
                        <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                        <button type="submit">移動</button>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    <?php else: ?>

        <?php if (!$current_magazine): ?>

            <p class="empty">指定された雑誌が見つかりません。</p>

        <?php else: ?>

            <h2>
                <?= h($current_magazine['magazine_name']) ?>
            </h2>

            <div class="small">
                出版社：<?= h($current_magazine['publisher_name']) ?>
            </div>

            <div class="nav" style="margin-top: 16px;">
                <a href="issues.php">雑誌一覧へ戻る</a>
            </div>

            <div class="count">
                全<?= h($total_issues) ?>号中 <?= h($from_count) ?>～<?= h($to_count) ?>件を表示<br>
                <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($perPage) ?>件
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination" aria-label="ページ移動">
                    <?php if ($page > 1): ?>
                        <a href="<?= h(build_issue_page_url(1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最初</a>
                        <a href="<?= h(build_issue_page_url($page - 1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">前へ</a>
                    <?php else: ?>
                        <span class="disabled">最初</span>
                        <span class="disabled">前へ</span>
                    <?php endif; ?>

                    <?php
                        $start_page = max(1, $page - 3);
                        $end_page = min($total_pages, $page + 3);
                    ?>

                    <?php if ($start_page > 1): ?>
                        <a href="<?= h(build_issue_page_url(1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="dots">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= h($i) ?></span>
                        <?php else: ?>
                            <a href="<?= h(build_issue_page_url($i, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($i) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="dots">…</span>
                        <?php endif; ?>
                        <a href="<?= h(build_issue_page_url($total_pages, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($total_pages) ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?= h(build_issue_page_url($page + 1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">次へ</a>
                        <a href="<?= h(build_issue_page_url($total_pages, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最後</a>
                    <?php else: ?>
                        <span class="disabled">次へ</span>
                        <span class="disabled">最後</span>
                    <?php endif; ?>

                    <form method="get" action="issues.php" class="page-jump">
                        <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
                        <?php if ($filter_magazine_id > 0): ?>
                            <input type="hidden" name="filter_magazine_id" value="<?= h($filter_magazine_id) ?>">
                        <?php endif; ?>
                        <?php if ($publisher_id > 0): ?>
                            <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                        <?php endif; ?>
                        <?php if ($year > 0): ?>
                            <input type="hidden" name="year" value="<?= h($year) ?>">
                        <?php endif; ?>
                        <?php if ($publisher_keyword !== ''): ?>
                            <input type="hidden" name="publisher_keyword" value="<?= h($publisher_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($magazine_keyword !== ''): ?>
                            <input type="hidden" name="magazine_keyword" value="<?= h($magazine_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($year_keyword !== ''): ?>
                            <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                        <?php endif; ?>
                        <?php if ($sort !== 'magazine'): ?>
                            <input type="hidden" name="sort" value="<?= h($sort) ?>">
                        <?php endif; ?>
                        <?php if ($direction !== 'asc'): ?>
                            <input type="hidden" name="direction" value="<?= h($direction) ?>">
                        <?php endif; ?>
                        <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                        <button type="submit">移動</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (empty($issues)): ?>

                <p class="empty">この雑誌には、条件に合う公開済みの雑誌号データがありません。号データが追加されると、発売日や目次件数、雑誌号詳細ページへのリンクが表示されます。</p>

            <?php else: ?>

                <table id="issuesTable">
                    <thead>
                        <tr>
                            <th>号</th>
                            <th class="year">発行年</th>
                            <th class="num">目次件数</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($issues as $row): ?>
                        <tr>
                            <td class="issue">
                                <a href="issue.php?id=<?= h($row['id']) ?>">
                                    <?= h(issue_label_with_special($row)) ?>
                                </a>

                                <div class="issue-meta">
                                    <?php if (!empty($row['issue_no'])): ?>
                                        <?= h($row['issue_year'] ?? '') ?>年<?= h($row['issue_no']) ?>号
                                    <?php endif; ?>

                                    <?php if (!empty($row['publication_date'])): ?>
                                        <?php if (!empty($row['issue_no'])): ?> ／ <?php endif; ?>
                                        発行日：<?= h($row['publication_date']) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($row['release_date'])): ?>
                                        <?php if (!empty($row['issue_no']) || !empty($row['publication_date'])): ?> ／ <?php endif; ?>
                                        発売日：<?= h($row['release_date']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="year"><?= h($row['display_year'] ?? '') ?></td>

                            <td class="num"><?= h($row['content_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination" aria-label="ページ移動">
                        <?php if ($page > 1): ?>
                            <a href="<?= h(build_issue_page_url(1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最初</a>
                            <a href="<?= h(build_issue_page_url($page - 1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">前へ</a>
                        <?php else: ?>
                            <span class="disabled">最初</span>
                            <span class="disabled">前へ</span>
                        <?php endif; ?>

                        <?php
                            $start_page = max(1, $page - 3);
                            $end_page = min($total_pages, $page + 3);
                        ?>

                        <?php if ($start_page > 1): ?>
                            <a href="<?= h(build_issue_page_url(1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="dots">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="current"><?= h($i) ?></span>
                            <?php else: ?>
                                <a href="<?= h(build_issue_page_url($i, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($i) ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="dots">…</span>
                            <?php endif; ?>
                            <a href="<?= h(build_issue_page_url($total_pages, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>"><?= h($total_pages) ?></a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?= h(build_issue_page_url($page + 1, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">次へ</a>
                            <a href="<?= h(build_issue_page_url($total_pages, $magazine_id, $publisher_id, $year, $keyword, $sort, $direction)) ?>">最後</a>
                        <?php else: ?>
                            <span class="disabled">次へ</span>
                            <span class="disabled">最後</span>
                        <?php endif; ?>

                        <form method="get" action="issues.php" class="page-jump">
                            <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
                            <?php if ($filter_magazine_id > 0): ?>
                                <input type="hidden" name="filter_magazine_id" value="<?= h($filter_magazine_id) ?>">
                            <?php endif; ?>
                            <?php if ($publisher_id > 0): ?>
                                <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                            <?php endif; ?>
                            <?php if ($year > 0): ?>
                                <input type="hidden" name="year" value="<?= h($year) ?>">
                            <?php endif; ?>
                            <?php if ($publisher_keyword !== ''): ?>
                                <input type="hidden" name="publisher_keyword" value="<?= h($publisher_keyword) ?>">
                            <?php endif; ?>
                            <?php if ($magazine_keyword !== ''): ?>
                                <input type="hidden" name="magazine_keyword" value="<?= h($magazine_keyword) ?>">
                            <?php endif; ?>
                            <?php if ($year_keyword !== ''): ?>
                                <input type="hidden" name="year_keyword" value="<?= h($year_keyword) ?>">
                            <?php endif; ?>
                            <?php if ($sort !== 'magazine'): ?>
                                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                            <?php endif; ?>
                            <?php if ($direction !== 'asc'): ?>
                                <input type="hidden" name="direction" value="<?= h($direction) ?>">
                            <?php endif; ?>
                            <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                            <button type="submit">移動</button>
                        </form>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<a href="#top" class="top-button" id="topButton" aria-label="TOPへ戻る">TOP</a>

<script>
const topButton = document.getElementById('topButton');
const publisherSelect = document.getElementById('publisher_id');
const filterMagazineSelect = document.getElementById('filter_magazine_id');
const yearSelect = document.getElementById('year');
const publisherKeywordInput = document.getElementById('publisher_keyword');
const magazineKeywordInput = document.getElementById('magazine_keyword');
const yearKeywordInput = document.getElementById('year_keyword');

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
    const publisherKeyword = normalizeSelectKeyword(publisherKeywordInput ? publisherKeywordInput.value : '');

    filterSelectOptions(publisherSelect, option => {
        return publisherKeyword === '' || option.textContent.toLowerCase().includes(publisherKeyword);
    });
}

function filterMagazinesByPublisher() {
    const publisherId = publisherSelect.value;
    const publisherKeyword = normalizeSelectKeyword(publisherKeywordInput ? publisherKeywordInput.value : '');
    const magazineKeyword = normalizeSelectKeyword(magazineKeywordInput ? magazineKeywordInput.value : '');

    filterSelectOptions(filterMagazineSelect, option => {
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

function filterYearsBySelection() {
    if (!yearSelect) {
        return;
    }

    const noResultOption = ensureNoResultOption(yearSelect);
    noResultOption.hidden = true;
    noResultOption.disabled = true;

    const publisherId = publisherSelect ? publisherSelect.value : '';
    const magazineId = filterMagazineSelect ? filterMagazineSelect.value : '';
    const yearKeyword = yearKeywordInput ? yearKeywordInput.value.trim().toLowerCase() : '';
    let visibleCount = 0;

    Array.from(yearSelect.options).forEach(option => {
        if (option.dataset.noResultOption === '1') {
            return;
        }

        if (option.value === '') {
            option.hidden = false;
            return;
        }

        let isVisible = true;

        if (yearKeyword !== '' && !option.textContent.toLowerCase().includes(yearKeyword)) {
            isVisible = false;
        }

        if (publisherId && !(option.dataset.publisherIds || '').includes(`,${publisherId},`)) {
            isVisible = false;
        }

        if (magazineId && !(option.dataset.magazineIds || '').includes(`,${magazineId},`)) {
            isVisible = false;
        }

        option.hidden = !isVisible;

        if (isVisible) {
            visibleCount++;
        }
    });

    const selectedOption = yearSelect.options[yearSelect.selectedIndex];

    if (selectedOption && selectedOption.hidden) {
        yearSelect.value = '';
    }

    if (visibleCount === 0) {
        Array.from(yearSelect.options).forEach(option => {
            if (option.dataset.noResultOption !== '1') {
                option.hidden = true;
            }
        });

        noResultOption.hidden = false;
        noResultOption.disabled = false;
        noResultOption.selected = true;
    } else if (yearSelect.value === '__no_result__') {
        yearSelect.value = '';
    }
}

function filterSearchSelects() {
    filterPublishersByKeyword();
    filterMagazinesByPublisher();
    filterYearsBySelection();
}

if (publisherSelect && filterMagazineSelect) {
    publisherSelect.addEventListener('change', filterSearchSelects);
    filterMagazineSelect.addEventListener('change', filterYearsBySelection);
    filterSearchSelects();
}

if (yearKeywordInput) {
    yearKeywordInput.addEventListener('input', filterYearsBySelection);
}

if (publisherKeywordInput) {
    publisherKeywordInput.addEventListener('input', filterSearchSelects);
}

if (magazineKeywordInput) {
    magazineKeywordInput.addEventListener('input', filterSearchSelects);
}

</script>
</body>
</html>
