<?php
// work_graph.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('作品連載順グラフ');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ii_get_string_param(string $key): string {
    if (!isset($_GET[$key]) || is_array($_GET[$key])) {
        return '';
    }

    return trim((string)$_GET[$key]);
}

function ii_get_positive_int_param(string $key): int {
    if (!isset($_GET[$key]) || is_array($_GET[$key])) {
        return 0;
    }

    $value = trim((string)$_GET[$key]);
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        return 0;
    }

    return (int)$value;
}

function ii_get_page_param(): array {
    if (!array_key_exists('page', $_GET)) {
        return [1, false, false];
    }

    if (is_array($_GET['page'])) {
        return [1, true, true];
    }

    $value = trim((string)$_GET['page']);
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        return [1, true, true];
    }

    $page = (int)$value;
    return [$page, true, $page === 1];
}

function build_work_graph_url($page, $publisher_id, $magazine_id, $year, $work_id, $work_q = '', $creator_q = '', $sort = 'title', $order = 'asc', $year_from = 0, $year_to = 0) {
    $params = [];

    if ($publisher_id > 0) {
        $params['publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $params['magazine_id'] = $magazine_id;
    }

    $work_q = trim((string)$work_q);
    if ($work_q !== '') {
        $params['work_q'] = $work_q;
    }

    $creator_q = trim((string)$creator_q);
    if ($creator_q !== '') {
        $params['creator_q'] = $creator_q;
    }

    if ($year > 0) {
        $params['year'] = $year;
    }

    if ($year_from > 0) {
        $params['year_from'] = $year_from;
    }

    if ($year_to > 0) {
        $params['year_to'] = $year_to;
    }

    if ($work_id > 0) {
        $params['work_id'] = $work_id;
    }

    $allowed_sorts = ['title', 'content_count', 'year'];
    if (!in_array($sort, $allowed_sorts, true)) {
        $sort = 'title';
    }

    $order = strtolower((string)$order) === 'desc' ? 'desc' : 'asc';

    if ($sort !== 'title') {
        $params['sort'] = $sort;
    }

    if ($order !== 'asc') {
        $params['order'] = $order;
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    $query_string = http_build_query($params);
    return 'work_graph.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function build_absolute_work_graph_url($page, $publisher_id, $magazine_id, $year, $work_id, $work_q = '', $creator_q = '', $sort = 'title', $order = 'asc', $year_from = 0, $year_to = 0) {
    return 'https://morizaru.net/indexindex/' . build_work_graph_url($page, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to);
}

function redirect_work_graph_url($url): void {
    header('Location: ' . $url, true, 301);
    exit;
}

function current_work_graph_relative_url(): string {
    $url = 'work_graph.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $url .= '?' . $_SERVER['QUERY_STRING'];
    }

    return $url;
}

function make_graph_url($publisher_id, $magazine_id, $year, $work_id, $work_q = '', $creator_q = '', $page = 1, $sort = 'title', $order = 'asc', $year_from = 0, $year_to = 0) {
    return build_work_graph_url($page, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to);
}

function render_pagination($page, $total_pages, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from = 0, $year_to = 0) {
    if ($total_pages <= 1) {
        return;
    }

    $start = max(1, $page - 3);
    $end = min($total_pages, $page + 3);
    ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_work_graph_url(1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>">最初</a>
                <a href="<?= h(build_work_graph_url($page - 1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php if ($start > 1): ?>
                <a href="<?= h(build_work_graph_url(1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>">1</a>
                <?php if ($start > 2): ?>
                    <span class="ellipsis">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_work_graph_url($i, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?>
                    <span class="ellipsis">…</span>
                <?php endif; ?>
                <a href="<?= h(build_work_graph_url($total_pages, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_work_graph_url($page + 1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>">次へ</a>
                <a href="<?= h(build_work_graph_url($total_pages, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="work_graph.php" class="page-jump">
                <?php if ($publisher_id > 0): ?>
                    <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                <?php endif; ?>
                <?php if ($magazine_id > 0): ?>
                    <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
                <?php endif; ?>
                <?php if ($work_q !== ''): ?>
                    <input type="hidden" name="work_q" value="<?= h($work_q) ?>">
                <?php endif; ?>
                <?php if ($creator_q !== ''): ?>
                    <input type="hidden" name="creator_q" value="<?= h($creator_q) ?>">
                <?php endif; ?>
                <?php if ($year > 0): ?>
                    <input type="hidden" name="year" value="<?= h($year) ?>">
                <?php endif; ?>
                <?php if ($year_from > 0): ?>
                    <input type="hidden" name="year_from" value="<?= h($year_from) ?>">
                <?php endif; ?>
                <?php if ($year_to > 0): ?>
                    <input type="hidden" name="year_to" value="<?= h($year_to) ?>">
                <?php endif; ?>
                <?php if ($work_id > 0): ?>
                    <input type="hidden" name="work_id" value="<?= h($work_id) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'title'): ?>
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <?php endif; ?>
                <?php if ($order !== 'asc'): ?>
                    <input type="hidden" name="order" value="<?= h($order) ?>">
                <?php endif; ?>
                <input type="number" name="page" min="1" max="<?= h($total_pages) ?>" value="<?= h($page) ?>" aria-label="ページ番号">
                <button type="submit">移動</button>
            </form>
        </div>
    </div>
    <?php
}


function build_work_graph_sort_url($sort_key, $current_sort, $current_order, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $year_from = 0, $year_to = 0) {
    $next_order = 'asc';

    if ($current_sort === $sort_key && $current_order === 'asc') {
        $next_order = 'desc';
    }

    return build_work_graph_url(1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort_key, $next_order, $year_from, $year_to);
}

function sort_mark($sort_key, $current_sort, $current_order) {
    if ($sort_key !== $current_sort) {
        return '';
    }

    return $current_order === 'desc' ? ' ▼' : ' ▲';
}

$publisher_id = ii_get_positive_int_param('publisher_id');
$magazine_id  = ii_get_positive_int_param('magazine_id');
$work_q   = ii_get_string_param('work_q');
$creator_q = ii_get_string_param('creator_q');
$year         = ii_get_positive_int_param('year');
$year_from    = ii_get_positive_int_param('year_from');
$year_to      = ii_get_positive_int_param('year_to');
$work_id      = ii_get_positive_int_param('work_id');
[$page, $has_page_param, $page_needs_redirect] = ii_get_page_param();
$per_page     = 100;
$has_graph = ($work_id > 0);

$allowed_sorts = ['title', 'content_count', 'year'];
$sort = ii_get_string_param('sort');
if ($sort === '') {
    $sort = 'title';
}
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'title';
}

$order = strtolower(ii_get_string_param('order'));
$order = $order === 'desc' ? 'desc' : 'asc';
$order_sql = strtoupper($order);

$order_by_map = [
    'title' => "w.title {$order_sql}, w.id ASC",
    'content_count' => "content_count {$order_sql}, w.title ASC, w.id ASC",
    'year' => "first_year {$order_sql}, last_year {$order_sql}, w.title ASC, w.id ASC",
];
$order_by_sql = $order_by_map[$sort];

// 出版社一覧
$stmt = $pdo->query("
    SELECT DISTINCT
        p.id,
        p.name
    FROM publishers p
    JOIN magazines m ON p.id = m.publisher_id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    ORDER BY p.name ASC
");
$publishers = $stmt->fetchAll();

// 雑誌一覧
$stmt = $pdo->query("
    SELECT DISTINCT
        m.id,
        m.publisher_id,
        m.name,
        p.name AS publisher_name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    JOIN magazine_issues mi ON m.id = mi.magazine_id
    JOIN issue_contents ic ON mi.id = ic.issue_id
    ORDER BY p.name ASC, m.name ASC
");
$magazines = $stmt->fetchAll();

// 年一覧
$stmt = $pdo->query("
    SELECT DISTINCT
        mi.issue_year
    FROM magazine_issues mi
    JOIN issue_contents ic ON mi.id = ic.issue_id
    WHERE mi.issue_year IS NOT NULL
    ORDER BY mi.issue_year DESC
");
$years = $stmt->fetchAll();

$selected_publisher_name = '';
$selected_magazine_name = '';
$selected_work_title = '';

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
        SELECT name
        FROM magazines
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $magazine_id]);
    $selected_magazine_name = $stmt->fetch()['name'] ?? '';
}

if ($work_id > 0) {
    $stmt = $pdo->prepare("
        SELECT title
        FROM works
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $work_id]);
    $selected_work_title = $stmt->fetch()['title'] ?? '';
}

$work_input_value = $work_q;
if ($work_input_value === '' && $work_id > 0 && $selected_work_title !== '') {
    $work_input_value = $selected_work_title;
}

// 作品一覧用条件
$list_where = [];
$list_params = [];

$list_where[] = "w.publication_type = '連載'";

if ($publisher_id > 0) {
    $list_where[] = "p.id = :publisher_id";
    $list_params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $list_where[] = "m.id = :magazine_id";
    $list_params[':magazine_id'] = $magazine_id;
}

if ($work_q !== '') {
    $list_where[] = "w.title LIKE :work_q";
    $list_params[':work_q'] = '%' . $work_q . '%';
}

if ($creator_q !== '') {
    $list_where[] = "EXISTS (
        SELECT 1
        FROM content_creators cc_search
        JOIN creators c_search ON cc_search.creator_id = c_search.id
        WHERE cc_search.content_id = ic.id
          AND c_search.name LIKE :creator_q
    )";
    $list_params[':creator_q'] = '%' . $creator_q . '%';
}

if ($year > 0) {
    $list_where[] = "mi.issue_year = :issue_year";
    $list_params[':issue_year'] = $year;
}

$list_where_sql = '';
if (!empty($list_where)) {
    $list_where_sql = 'WHERE ' . implode(' AND ', $list_where);
}

// 条件に合う作品数
$total_count = 0;
$total_pages = 1;
$offset = 0;
$from_count = 0;
$to_count = 0;
$works = [];

// 条件に合う作品一覧：1ページ分だけ取得
$rows = [];
$chart_labels = [];
$chart_values = [];
$graph_years = [];
$graph_year_options = [];
$graph_year_min = 0;
$graph_year_max = 0;

// 選択中作品で表示可能な年一覧
if ($work_id > 0) {
    $year_where = ["w.id = :work_id"];
    $year_params = [
        ':work_id' => $work_id
    ];

    if ($publisher_id > 0) {
        $year_where[] = "p.id = :publisher_id";
        $year_params[':publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $year_where[] = "m.id = :magazine_id";
        $year_params[':magazine_id'] = $magazine_id;
    }

    if ($work_q !== '') {
        $year_where[] = "w.title LIKE :work_q";
        $year_params[':work_q'] = '%' . $work_q . '%';
    }

    if ($creator_q !== '') {
        $year_where[] = "EXISTS (
            SELECT 1
            FROM content_creators cc_search
            JOIN creators c_search ON cc_search.creator_id = c_search.id
            WHERE cc_search.content_id = ic.id
              AND c_search.name LIKE :creator_q
        )";
        $year_params[':creator_q'] = '%' . $creator_q . '%';
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            mi.issue_year
        FROM works w
        JOIN issue_contents ic ON w.id = ic.work_id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE " . implode(' AND ', $year_where) . "
          AND mi.issue_year IS NOT NULL
        ORDER BY mi.issue_year DESC
    ");
    $stmt->execute($year_params);
    $graph_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($graph_years)) {
        $graph_year_min = (int)min($graph_years);
        $graph_year_max = (int)max($graph_years);

        if ($year > 0 && $year_from <= 0 && $year_to <= 0) {
            $year_from = $year;
            $year_to = $year;
        }

        if ($year_from <= 0) {
            $year_from = $graph_year_min;
        }

        if ($year_to <= 0) {
            $year_to = $graph_year_max;
        }

        if ($year_from > $year_to) {
            $tmp_year = $year_from;
            $year_from = $year_to;
            $year_to = $tmp_year;
        }
    }
}

// グラフ表示は作品が選ばれている時だけ
$graph_year_options = array_map('intval', $graph_years);
if ($year_from > 0) {
    $graph_year_options[] = $year_from;
}
if ($year_to > 0) {
    $graph_year_options[] = $year_to;
}
$graph_year_options = array_values(array_unique(array_filter($graph_year_options, static function ($value) {
    return $value > 0;
})));
sort($graph_year_options, SORT_NUMERIC);

if ($has_graph) {
    $where = [];
    $params = [];

    if ($publisher_id > 0) {
        $where[] = "p.id = :publisher_id";
        $params[':publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $where[] = "m.id = :magazine_id";
        $params[':magazine_id'] = $magazine_id;
    }

    if ($work_q !== '') {
        $where[] = "w.title LIKE :work_q";
        $params[':work_q'] = '%' . $work_q . '%';
    }

    if ($creator_q !== '') {
        $where[] = "EXISTS (
            SELECT 1
            FROM content_creators cc_search
            JOIN creators c_search ON cc_search.creator_id = c_search.id
            WHERE cc_search.content_id = ic.id
              AND c_search.name LIKE :creator_q
        )";
        $params[':creator_q'] = '%' . $creator_q . '%';
    }

    if ($year_from > 0) {
        $where[] = "mi.issue_year >= :year_from";
        $params[':year_from'] = $year_from;
    }

    if ($year_to > 0) {
        $where[] = "mi.issue_year <= :year_to";
        $params[':year_to'] = $year_to;
    }

    $where[] = "w.publication_type = '連載'";
    $where[] = "w.id = :work_id";
    $params[':work_id'] = $work_id;

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            p.id AS publisher_id,
            p.name AS publisher_name,
            m.id AS magazine_id,
            m.name AS magazine_name,
            mi.id AS issue_id,
            mi.issue_label,
            mi.issue_year,
            mi.issue_no,
            w.id AS work_id,
            w.title AS work_title,
            ic.id AS content_id,
            ic.sort_order,
            ic.page_start,
            ic.page_end,
            ic.episode_no,
            ic.episode_title,
            ic.note,
            GROUP_CONCAT(
                DISTINCT
                CASE
                    WHEN r.name IS NOT NULL AND r.name <> '' THEN CONCAT(r.name, '：', c.name)
                    ELSE c.name
                END
                ORDER BY cc.display_order
                SEPARATOR ' / '
            ) AS creators
        FROM issue_contents ic
        JOIN works w ON ic.work_id = w.id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        LEFT JOIN content_creators cc ON ic.id = cc.content_id
        LEFT JOIN creators c ON cc.creator_id = c.id
        LEFT JOIN roles r ON cc.role_id = r.id
        {$where_sql}
        GROUP BY ic.id
        ORDER BY
            mi.issue_year ASC,
            CASE
                WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
                ELSE 999999
            END ASC,
            mi.id ASC,
            ic.sort_order ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $issue_no_label = $row['issue_no'] !== null && $row['issue_no'] !== ''
            ? $row['issue_no'] . '号'
            : $row['issue_label'];

        $label_parts = [];

        if ($magazine_id <= 0) {
            $label_parts[] = $row['magazine_name'];
        }

        $label_parts[] = $issue_no_label;

        $chart_labels[] = implode(' ', $label_parts);
        $chart_values[] = (int)$row['sort_order'];
    }
}

$suppress_work_list = ($has_graph && empty($rows));

if ($suppress_work_list) {
    $page = 1;
    $canonical_relative_url = build_work_graph_url(1, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to);
    if ($page_needs_redirect || $has_page_param || current_work_graph_relative_url() !== $canonical_relative_url) {
        redirect_work_graph_url($canonical_relative_url);
    }
} else {
    $count_sql = "
        SELECT COUNT(DISTINCT w.id) AS total_count
        FROM works w
        JOIN issue_contents ic ON w.id = ic.work_id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        {$list_where_sql}
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($list_params);
    $total_count = (int)($stmt->fetch()['total_count'] ?? 0);
    $total_pages = max(1, (int)ceil($total_count / $per_page));

    $canonical_page = $page;
    if ($total_count <= 0) {
        $canonical_page = 1;
    } elseif ($canonical_page > $total_pages) {
        $canonical_page = $total_pages;
    }

    $canonical_relative_url = build_work_graph_url($canonical_page, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to);
    if ($page_needs_redirect || $page !== $canonical_page || current_work_graph_relative_url() !== $canonical_relative_url) {
        redirect_work_graph_url($canonical_relative_url);
    }

    $page = $canonical_page;
    $offset = ($page - 1) * $per_page;
    $from_count = $total_count > 0 ? $offset + 1 : 0;
    $to_count = min($offset + $per_page, $total_count);

    if ($total_count > 0) {
        $sql = "
            SELECT
                w.id,
                w.title,
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
            {$list_where_sql}
            GROUP BY w.id, w.title, w.publication_type
            ORDER BY {$order_by_sql}
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($list_params as $key => $value) {
            $param_type = in_array($key, [':work_q', ':creator_q'], true) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $stmt->bindValue($key, $value, $param_type);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $works = $stmt->fetchAll();
    }
}

$is_zero_result = ($total_count === 0) || $suppress_work_list;
$canonical_url = build_absolute_work_graph_url($page, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <?php if ($is_zero_result): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <link rel="canonical" href="<?= h($canonical_url) ?>">
    <?php if ($suppress_work_list): ?>
        <style>.container > h2 { display: none; }</style>
    <?php endif; ?>
    <title>作品連載順グラフ - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
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
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
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
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .work-search-row {
            margin-bottom: 14px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 160px auto;
            gap: 16px;
            align-items: end;
        }

        .form-row {
            margin-bottom: 0;
        }

        .form-row label,
        .work-search-row label,
        .graph-year-box label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        select,
        .work-search-input,
        .table-filter input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button,
        .clear-link,
        .button-link {
            display: inline-block;
            box-sizing: border-box;
            padding: 10px 16px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            height: 42px;
            text-decoration: none;
            text-align: center;
        }

        button:hover,
        .clear-link:hover,
        .button-link:hover {
            background: #111;
            text-decoration: none;
        }

        .clear-link {
            background: #777;
            border-color: #777;
            margin-left: 6px;
        }

        .button-link.graph {
            background: #00897b;
            border-color: #00897b;
            font-size: 14px;
            height: auto;
            padding: 6px 10px;
        }

        .info-box {
            margin: 20px 0;
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 4px;
            line-height: 1.8;
        }

        .page-description,
        .description-box {
            margin: 0 0 20px;
            padding: 14px 16px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.8;
            font-size: 14px;
        }

        .page-description strong {
            color: #222;
        }

        .description-box p {
            margin: 0 0 8px;
        }

        .description-box p:last-child {
            margin-bottom: 0;
        }

        .help-box {
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 14px 16px;
            margin: 14px 0;
            color: #444;
            line-height: 1.8;
            font-size: 14px;
        }

        .example-list {
            margin: 8px 0 0;
            padding-left: 1.4em;
        }

        .chart-wrap {
            margin-top: 24px;
        }

        .graph-year-box {
            max-width: 460px;
            margin: 20px 0 8px;
        }

        .graph-year-range {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto;
            gap: 8px;
            align-items: end;
        }

        .graph-year-separator {
            padding: 10px 0;
            color: #555;
            font-weight: bold;
        }

        .table-filter {
            margin-bottom: 16px;
        }

        .count {
            margin: 16px 0;
            color: #666;
            line-height: 1.7;
        }

        .pagination-wrap {
            margin: 16px 0;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 11px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
            line-height: 1;
        }

        .pagination a:hover {
            background: #eef5ff;
            text-decoration: none;
        }

        .pagination .current {
            background: #333;
            border-color: #333;
            color: #fff;
        }

        .pagination .disabled,
        .pagination .ellipsis {
            color: #999;
            background: #f5f5f5;
            font-weight: normal;
        }

        .page-jump {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            margin-left: 8px;
            padding-left: 8px;
        }

        .page-jump input[type="number"] {
            width: 90px;
            box-sizing: border-box;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 15px;
        }

        .page-jump button {
            height: 36px;
            padding: 7px 12px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            background: #fff;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            vertical-align: top;
        }

        th {
            background: #eee;
            text-align: left;
        }

        th a.sort-link {
            color: #222;
            text-decoration: none;
            display: inline-block;
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

        .order {
            width: 70px;
            text-align: center;
        }

        .page {
            width: 100px;
            text-align: center;
        }

        .num {
            width: 90px;
            text-align: right;
        }

        .type {
            width: 90px;
        }

        .year {
            width: 140px;
        }

        .actions {
            width: 100px;
            text-align: center;
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

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .empty {
            margin-top: 20px;
            color: #666;
        }

        .top-button {
            position: fixed;
            right: 22px;
            bottom: 22px;
            width: 54px;
            height: 54px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #222, #555);
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

        @media (max-width: 1000px) {
            body {
                margin: 8px;
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

            button,
            .clear-link {
                width: 100%;
                margin: 4px 0;
            }

            .graph-year-box {
                max-width: none;
            }

            .graph-year-range {
                grid-template-columns: 1fr;
            }

            .graph-year-separator {
                display: none;
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
            }

            .page-jump input[type="number"] {
                flex: 1;
                width: auto;
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

            td::before {
                font-weight: bold;
                display: inline-block;
                width: 110px;
                color: #555;
            }

            td.title::before { content: "作品"; }
            td.type::before { content: "区分"; }
            td.num::before { content: "件数"; }
            td.year::before { content: "収録年"; }
            td.actions::before { content: "操作"; }

            td.issue::before { content: "雑誌・号"; }
            td.work::before { content: "作品"; }
            td.order::before { content: "掲載順"; }
            td.page::before { content: "ページ"; }
            td.episode::before { content: "話数"; }
            td.episode-title::before { content: "話タイトル"; }
            td.creators::before { content: "人物"; }
            td.note-cell::before { content: "備考"; }

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
$current_page = 'graph';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1>作品連載順グラフ</h1>

    <div class="page-description">
        <p><strong>作品掲載順グラフについて</strong></p>
        <p>作品ごとの掲載順の推移を、雑誌号ごとの目次データをもとにグラフで確認できます。連載中の掲載位置の変化を見たいときに、作品名や人物名、雑誌、年で絞り込んで使えます。</p>
        <p>作品によっては掲載号数が少ない、掲載順が未登録、条件に合う目次が少ないなどの理由で、グラフが表示されない場合があります。</p>
    </div>

    <form method="get" action="work_graph.php" class="filter-form">
        <?php if ($sort !== 'title'): ?>
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <?php endif; ?>
        <?php if ($order !== 'asc'): ?>
            <input type="hidden" name="order" value="<?= h($order) ?>">
        <?php endif; ?>
        <?php if ($work_id > 0): ?>
            <input type="hidden" name="work_id" id="work_id" value="<?= h($work_id) ?>">
            <input type="hidden" id="selected_work_title" value="<?= h($selected_work_title) ?>">
        <?php endif; ?>

        <div class="work-search-row">
            <label>作品検索</label>
            <input
                type="text"
                name="work_q"
                id="work_q"
                class="work-search-input"
                value="<?= h($work_input_value) ?>"
                placeholder="作品名で検索"
                autocomplete="off"
            >
        </div>

        <div class="work-search-row">
            <label>人物検索</label>
            <input
                type="text"
                name="creator_q"
                id="creator_q"
                class="work-search-input"
                value="<?= h($creator_q) ?>"
                placeholder="人物名で検索"
                autocomplete="off"
            >
        </div>

        <?php if ($work_id > 0 && !empty($graph_year_options)): ?>
            <div class="graph-year-box">
                <label>表示年</label>
                <div class="graph-year-range">
                    <select name="year_from" id="filter_year_from">
                        <?php foreach ($graph_year_options as $graph_year): ?>
                            <option value="<?= h($graph_year) ?>" <?= ((string)$year_from === (string)$graph_year) ? 'selected' : '' ?>>
                                <?= h($graph_year) ?>年
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="graph-year-separator">〜</span>

                    <select name="year_to" id="filter_year_to">
                        <?php foreach ($graph_year_options as $graph_year): ?>
                            <option value="<?= h($graph_year) ?>" <?= ((string)$year_to === (string)$graph_year) ? 'selected' : '' ?>>
                                <?= h($graph_year) ?>年
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <div class="filter-grid">

            <div class="form-row">
                <label>出版社</label>
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
                <select name="magazine_id" id="magazine_id">
                    <option value="">指定なし</option>
                    <?php foreach ($magazines as $magazine): ?>
                        <option
                            value="<?= h($magazine['id']) ?>"
                            data-publisher-id="<?= h($magazine['publisher_id']) ?>"
                            <?= ($magazine_id === (int)$magazine['id']) ? 'selected' : '' ?>
                        >
                            <?= h($magazine['publisher_name']) ?> / <?= h($magazine['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label>年</label>
                <select name="year" id="year">
                    <option value="">指定なし</option>
                    <?php foreach ($years as $row): ?>
                        <option value="<?= h($row['issue_year']) ?>" <?= ($year === (int)$row['issue_year']) ? 'selected' : '' ?>>
                            <?= h($row['issue_year']) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <button type="submit">表示する</button>
                <a class="clear-link" href="work_graph.php">クリア</a>
            </div>

        </div>
    </form>

    <div class="info-box">
        条件：
        出版社：<?= h($selected_publisher_name !== '' ? $selected_publisher_name : '指定なし') ?> /
        作品検索：<?= h($work_q !== '' ? $work_q : '指定なし') ?> /
        人物検索：<?= h($creator_q !== '' ? $creator_q : '指定なし') ?> /
        雑誌：<?= h($selected_magazine_name !== '' ? $selected_magazine_name : '指定なし') ?> /
        年：<?= h($year > 0 ? $year . '年' : '指定なし') ?> /
        <?php if ($work_id > 0): ?>
            表示年：<?= h(($year_from > 0 && $year_to > 0) ? $year_from . '年〜' . $year_to . '年' : '指定なし') ?> /
        <?php endif; ?>
        <?php if ($work_id > 0): ?>
            作品：<?= h($selected_work_title !== '' ? $selected_work_title : '指定なし') ?> /
            データ件数：<?= h(count($rows)) ?>件
        <?php else: ?>
            作品：未選択
        <?php endif; ?>
    </div>

    <?php if ($has_graph): ?>

        <?php if (empty($rows)): ?>

            <div class="help-box">
                <strong>この作品は、掲載順グラフを表示できるだけのデータがまだ登録されていません。</strong><br>
                目次データは順次追加・整理しています。掲載号数が少ない、掲載順が未登録、開始ページ情報が不足している、または出版社・雑誌・年の条件が狭い場合は、グラフが表示されないことがあります。
            </div>

        <?php else: ?>

            <div class="help-box">
                このグラフは、登録済みの雑誌号ごとの掲載順をもとに表示しています。掲載順の数字が小さいほど、目次上では前の方に掲載されています。
            </div>

            <?php if (!empty($graph_year_options)): ?>
                <form method="get" action="work_graph.php" class="graph-year-box">
                    <?php if ($publisher_id > 0): ?>
                        <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
                    <?php endif; ?>
                    <?php if ($magazine_id > 0): ?>
                        <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
                    <?php endif; ?>
                    <?php if ($work_q !== ''): ?>
                        <input type="hidden" name="work_q" value="<?= h($work_q) ?>">
                    <?php endif; ?>
                    <?php if ($creator_q !== ''): ?>
                        <input type="hidden" name="creator_q" value="<?= h($creator_q) ?>">
                    <?php endif; ?>
                    <?php if ($year > 0): ?>
                        <input type="hidden" name="year" value="<?= h($year) ?>">
                    <?php endif; ?>
                    <?php if ($work_id > 0): ?>
                        <input type="hidden" name="work_id" value="<?= h($work_id) ?>">
                    <?php endif; ?>
                    <?php if ($sort !== 'title'): ?>
                        <input type="hidden" name="sort" value="<?= h($sort) ?>">
                    <?php endif; ?>
                    <?php if ($order !== 'asc'): ?>
                        <input type="hidden" name="order" value="<?= h($order) ?>">
                    <?php endif; ?>

                    <label>表示年</label>
                    <div class="graph-year-range">
                        <select name="year_from" id="graph_year_from">
                            <?php foreach ($graph_year_options as $graph_year): ?>
                                <option value="<?= h($graph_year) ?>" <?= ((string)$year_from === (string)$graph_year) ? 'selected' : '' ?>>
                                    <?= h($graph_year) ?>年
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <span class="graph-year-separator">〜</span>

                        <select name="year_to" id="graph_year_to">
                            <?php foreach ($graph_year_options as $graph_year): ?>
                                <option value="<?= h($graph_year) ?>" <?= ((string)$year_to === (string)$graph_year) ? 'selected' : '' ?>>
                                    <?= h($graph_year) ?>年
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">変更</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="chart-wrap">
                <canvas id="rankingChart"></canvas>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                const ctx = document.getElementById('rankingChart').getContext('2d');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>,
                        datasets: [{
                            label: '掲載順',
                            data: <?= json_encode($chart_values, JSON_UNESCAPED_UNICODE) ?>,
                            borderWidth: 2,
                            tension: 0,
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            title: {
                                display: true,
                                text: <?= json_encode(($selected_work_title !== '' ? $selected_work_title : '作品') . ' 掲載順（' . (($year_from > 0 && $year_to > 0) ? $year_from . '年〜' . $year_to . '年' : '年指定なし') . '）', JSON_UNESCAPED_UNICODE) ?>
                            }
                        },
                        scales: {
                            y: {
                                reverse: true,
                                suggestedMin: 0.5,
                                beginAtZero: false,
                                ticks: {
                                    stepSize: 1,
                                    callback: function(value) {
                                        return Number.isInteger(value) && value >= 1 ? value : '';
                                    }
                                },
                                title: {
                                    display: true,
                                    text: '掲載順'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: '号'
                                }
                            }
                        }
                    }
                });
            </script>

            <h2>掲載データ一覧</h2>

            <div class="table-filter">
                <input type="text" id="resultFilter" placeholder="掲載データ内を絞り込み">
            </div>

            <table id="resultTable">
                <thead>
                    <tr>
                        <th>雑誌・号</th>
                        <th>作品</th>
                        <th class="order">掲載順</th>
                        <th class="page">ページ</th>
                        <th>話数</th>
                        <th>話タイトル</th>
                        <th>人物</th>
                        <th>備考</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="issue">
                            <a href="issue.php?id=<?= h($row['issue_id']) ?>">
                                <?= h($row['magazine_name']) ?><br>
                                <?= h($row['issue_label']) ?>
                            </a>
                            <div class="small">
                                <?= h($row['publisher_name']) ?><br>
                                <?php if (!empty($row['issue_year'])): ?>
                                    <?= h($row['issue_year']) ?>年
                                <?php endif; ?>
                                <?php if (!empty($row['issue_no'])): ?>
                                    / <?= h($row['issue_no']) ?>号
                                <?php endif; ?>
                            </div>
                        </td>

                        <td class="work">
                            <a href="work.php?work_id=<?= h($row['work_id']) ?>">
                                <?= h($row['work_title']) ?>
                            </a>
                        </td>

                        <td class="order"><?= h($row['sort_order']) ?></td>

                        <td class="page">
                            <?php if ($row['page_start'] && $row['page_end']): ?>
                                <?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?>
                            <?php elseif ($row['page_start']): ?>
                                <?= h($row['page_start']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td class="episode"><?= h($row['episode_no'] ?? '') ?></td>

                        <td class="episode-title"><?= h($row['episode_title'] ?? '') ?></td>

                        <td class="creators"><?= h($row['creators'] ?? '') ?></td>

                        <td class="note-cell">
                            <?php if (!empty($row['note'])): ?>
                                <span class="note"><?= h($row['note']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    <?php else: ?>

        <div class="help-box">
            <strong>掲載順の推移を見るには、作品を選択してください。</strong><br>
            作品名または人物名で絞り込み、作品一覧の「グラフ」から対象作品を選ぶと、登録済みの雑誌号ごとの掲載順を確認できます。
            <ul class="example-list">
                <li>作品名で検索して、対象作品のグラフを表示する</li>
                <li>人物名で絞り込み、関連する連載作品を探す</li>
                <li>出版社・雑誌・年を指定して、掲載範囲を絞る</li>
            </ul>
        </div>

    <?php endif; ?>

    <h2>作品一覧</h2>

    <?php if (!$suppress_work_list): ?>

    <div class="count">
        全<?= h($total_count) ?>作品中 <?= h($from_count) ?>〜<?= h($to_count) ?>件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($per_page) ?>件
    </div>

    <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to); ?>

    <div class="table-filter">
        <input type="text" id="workFilter" placeholder="このページ内を作品名・出版社・雑誌名で絞り込み">
    </div>

    <?php if (empty($works)): ?>

        <p class="empty">この条件に合う作品はありません。作品名や人物名を短くする、出版社や年の条件を外すなどして再検索してください。</p>

    <?php else: ?>

        <table id="worksTable">
            <thead>
                <tr>
                    <th><a class="sort-link" href="<?= h(build_work_graph_sort_url('title', $sort, $order, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $year_from, $year_to)) ?>">作品名<?= h(sort_mark('title', $sort, $order)) ?></a></th>
                    <th class="type">区分</th>
                    <th class="num"><a class="sort-link" href="<?= h(build_work_graph_sort_url('content_count', $sort, $order, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $year_from, $year_to)) ?>">登録件数<?= h(sort_mark('content_count', $sort, $order)) ?></a></th>
                    <th class="year"><a class="sort-link" href="<?= h(build_work_graph_sort_url('year', $sort, $order, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $year_from, $year_to)) ?>">収録年<?= h(sort_mark('year', $sort, $order)) ?></a></th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($works as $row): ?>
                <tr>
                    <td class="title">
                        <a href="work.php?work_id=<?= h($row['id']) ?>">
                            <span class="title-main"><?= h($row['title']) ?></span>
                        </a>

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
                        <?= h($row['publication_type'] ?? '') ?>
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

                    <td class="actions">
                        <a class="button-link graph" href="<?= h(make_graph_url($publisher_id, $magazine_id, $year, (int)$row['id'], $work_q, $creator_q, $page, $sort, $order, $year_from, $year_to)) ?>">
                            グラフ
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $year, $work_id, $work_q, $creator_q, $sort, $order, $year_from, $year_to); ?>

    <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<a href="#top" class="top-button" id="topButton" aria-label="ページ上部へ戻る">TOP</a>

<script>
const publisherSelect = document.getElementById('publisher_id');
const magazineSelect = document.getElementById('magazine_id');

function filterMagazinesByPublisher() {
    if (!publisherSelect || !magazineSelect) {
        return;
    }

    const publisherId = publisherSelect.value;

    Array.from(magazineSelect.options).forEach(option => {
        if (option.value === '') {
            option.hidden = false;
            return;
        }

        const optionPublisherId = option.dataset.publisherId || '';
        const publisherMatched = !publisherId || String(optionPublisherId) === String(publisherId);

        option.hidden = !publisherMatched;
    });

    const selectedOption = magazineSelect.options[magazineSelect.selectedIndex];

    if (selectedOption && selectedOption.hidden) {
        magazineSelect.value = '';
    }
}

if (publisherSelect && magazineSelect) {
    publisherSelect.addEventListener('change', filterMagazinesByPublisher);


    filterMagazinesByPublisher();
}

const workSearchInput = document.getElementById('work_q');
const workIdInput = document.getElementById('work_id');
const selectedWorkTitleInput = document.getElementById('selected_work_title');

if (workSearchInput && workIdInput && selectedWorkTitleInput) {
    const originalSelectedWorkTitle = selectedWorkTitleInput.value;
    const originalWorkId = workIdInput.value;

    workSearchInput.addEventListener('input', () => {
        if (workSearchInput.value !== originalSelectedWorkTitle) {
            workIdInput.value = '';
            workIdInput.removeAttribute('name');
        } else {
            workIdInput.value = originalWorkId;
            workIdInput.name = 'work_id';
        }
    });
}

const resultFilter = document.getElementById('resultFilter');
const resultTable = document.getElementById('resultTable');

if (resultFilter && resultTable) {
    resultFilter.addEventListener('input', () => {
        const keyword = resultFilter.value.trim().toLowerCase();
        const rows = resultTable.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = (!keyword || text.includes(keyword)) ? '' : 'none';
        });
    });
}

const workFilter = document.getElementById('workFilter');
const worksTable = document.getElementById('worksTable');

if (workFilter && worksTable) {
    workFilter.addEventListener('input', () => {
        const keyword = workFilter.value.trim().toLowerCase();
        const rows = worksTable.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = (!keyword || text.includes(keyword)) ? '' : 'none';
        });
    });
}

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

window.addEventListener('scroll', toggleTopButton);
toggleTopButton();
</script>

<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-4680056123241728"
     crossorigin="anonymous"></script>

    </body>
</html>
