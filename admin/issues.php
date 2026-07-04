<?php
declare(strict_types=1);

/**
 * admin/issues.php
 * 雑誌号一覧（管理画面）
 * - 雑誌検索
 * - 出版社検索
 * - 発行年検索
 * - 表示するボタン
 * - クリアボタン
 * - 目次件数表示
 *
 * 前提DB構成：
 * magazines.publisher_id → publishers.id
 * publishers.name = 出版社名
 * magazine_issues.issue_no = 号数
 * issue_contents.issue_id = magazine_issues.id
 */

/* =========================================================
   config.php 読み込み
   indexindex/
   ├─ app/config.php
   └─ admin/issues.php
   の構成を前提
   ========================================================= */

require_once __DIR__ . '/../app/config.php';

/* =========================================================
   PDO 接続の準備
   ========================================================= */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (
        defined('DB_HOST') &&
        defined('DB_NAME') &&
        defined('DB_USER') &&
        defined('DB_PASS')
    ) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        exit('PDO接続情報が見つかりません。$pdo または DB_HOST / DB_NAME / DB_USER / DB_PASS を app/config.php に用意してください。');
    }
}

/* =========================================================
   共通関数
   ========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_param(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function build_issue_page_url(int $page, string $q, int $publisher_id, int $magazine_id, string $publication_year, bool $content_count_zero, string $sort = 'default', string $dir = 'asc'): string
{
    $query = [];

    if ($q !== '') {
        $query['q'] = $q;
    }

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($publication_year !== '') {
        $query['publication_year'] = $publication_year;
    }

    if ($content_count_zero) {
        $query['content_count_zero'] = 1;
    }

    if ($sort !== 'default') {
        $query['sort'] = $sort;
    }

    if ($dir !== 'asc') {
        $query['dir'] = $dir;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'issues.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function build_issue_sort_url(string $sort_key, string $current_sort, string $current_dir, string $q, int $publisher_id, int $magazine_id, string $publication_year, bool $content_count_zero): string
{
    $next_dir = 'asc';

    if ($current_sort === $sort_key && $current_dir === 'asc') {
        $next_dir = 'desc';
    }

    return build_issue_page_url(1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort_key, $next_dir);
}

function sort_mark(string $sort_key, string $current_sort, string $current_dir): string
{
    if ($current_sort !== $sort_key) {
        return '';
    }

    return $current_dir === 'asc' ? ' ▲' : ' ▼';
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    if (!isset($cache[$table])) {
        $allowed_tables = ['magazine_issues'];
        if (!in_array($table, $allowed_tables, true)) {
            return false;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cache[$table] = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }

    return isset($cache[$table][$column]);
}

/* =========================================================
   検索条件
   ========================================================= */

$q = get_param('q');
if ($q === '') {
    $q = get_param('keyword');
}

$publisher_id = isset($_GET['publisher_id'])
    ? (int)$_GET['publisher_id']
    : (int)get_param('publisher');

$magazine_id = isset($_GET['magazine_id'])
    ? (int)$_GET['magazine_id']
    : 0;

$publication_year = get_param('publication_year');
if ($publication_year === '') {
    $publication_year = get_param('year');
}

if ($publication_year !== '' && !preg_match('/^[0-9]{4}$/', $publication_year)) {
    $publication_year = '';
}

$content_count_zero = (($_GET['content_count_zero'] ?? '') === '1');

$allowed_sorts = [
    'default',
    'id',
    'magazine',
    'publisher',
    'issue_label',
    'issue_label_special',
    'publication_year',
    'issue_vol',
    'issue_no',
    'issue_full_vol',
    'publication_date',
    'release_date',
    'status',
    'content_count',
];
$sort = get_param('sort');
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'default';
}

$dir = strtolower(get_param('dir'));
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'asc';
}
if ($sort === 'default') {
    $dir = 'asc';
}
$dir_sql = strtoupper($dir);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 100;

$filter_year_expr = "COALESCE(
    mi.publication_year,
    YEAR(mi.publication_date),
    YEAR(mi.release_date),
    mi.issue_year
)";

/* =========================================================
   出版社一覧
   ========================================================= */

$publisher_sql = "
    SELECT
        id,
        name
    FROM publishers
    ORDER BY name ASC
";

$publisher_stmt = $pdo->query($publisher_sql);
$publishers = $publisher_stmt->fetchAll();

/* =========================================================
   雑誌一覧
   ========================================================= */

$magazine_sql = "
    SELECT
        id,
        name
    FROM magazines
    WHERE 1 = 1
";
$magazine_params = [];

if ($publisher_id > 0) {
    $magazine_sql .= " AND publisher_id = :publisher_id";
    $magazine_params[':publisher_id'] = $publisher_id;
}

$magazine_sql .= " ORDER BY name ASC";

$magazine_stmt = $pdo->prepare($magazine_sql);
$magazine_stmt->execute($magazine_params);
$magazines = $magazine_stmt->fetchAll();

/* =========================================================
   発行年一覧
   ========================================================= */

$year_sql = "
    SELECT DISTINCT
        {$filter_year_expr} AS y
    FROM magazine_issues mi
    INNER JOIN magazines m
        ON m.id = mi.magazine_id
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

$year_sql .= " ORDER BY y DESC";

$year_stmt = $pdo->prepare($year_sql);
$year_stmt->execute($year_params);
$years = $year_stmt->fetchAll();

if ($publication_year !== '') {
    $year_exists = false;

    foreach ($years as $row) {
        if ((string)($row['y'] ?? '') === $publication_year) {
            $year_exists = true;
            break;
        }
    }

    if (!$year_exists) {
        $publication_year = '';
    }
}

/* =========================================================
   雑誌号一覧取得
   ========================================================= */

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "m.name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

if ($publisher_id > 0) {
    $where[] = "m.publisher_id = :publisher_id";
    $params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $where[] = "mi.magazine_id = :magazine_id";
    $params[':magazine_id'] = $magazine_id;
}

if ($publication_year !== '') {
    $where[] = "{$filter_year_expr} = :publication_year";
    $params[':publication_year'] = (int)$publication_year;
}

$content_count_join = "
    LEFT JOIN (
        SELECT
            issue_id,
            COUNT(*) AS content_count
        FROM issue_contents
        GROUP BY issue_id
    ) ic
        ON ic.issue_id = mi.id
";

if ($content_count_zero) {
    $where[] = "COALESCE(ic.content_count, 0) = 0";
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$issue_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_vol')
    ? 'mi.issue_vol'
    : 'NULL AS issue_vol';
$issue_full_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_full_vol')
    ? 'mi.issue_full_vol'
    : 'NULL AS issue_full_vol';

$default_order_sql = "
    m.name ASC,
    COALESCE(mi.issue_year, mi.publication_year, YEAR(mi.release_date), 9999) ASC,
    COALESCE(mi.release_date, mi.publication_date, '9999-12-31') ASC,
    mi.id ASC
";

$issue_no_order_sql = "
    CASE
        WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
        ELSE 999999
    END {$dir_sql},
    mi.issue_no {$dir_sql}
";

$order_by_map = [
    'default' => $default_order_sql,
    'id' => "mi.id {$dir_sql}",
    'magazine' => "m.name {$dir_sql}, {$default_order_sql}",
    'publisher' => "p.name {$dir_sql}, m.name ASC, {$default_order_sql}",
    'issue_label' => "mi.issue_label {$dir_sql}, {$default_order_sql}",
    'issue_label_special' => "mi.issue_label_special {$dir_sql}, {$default_order_sql}",
    'publication_year' => "{$filter_year_expr} {$dir_sql}, {$default_order_sql}",
    'issue_vol' => "issue_vol {$dir_sql}, {$default_order_sql}",
    'issue_no' => "{$issue_no_order_sql}, {$default_order_sql}",
    'issue_full_vol' => "issue_full_vol {$dir_sql}, {$default_order_sql}",
    'publication_date' => "mi.publication_date {$dir_sql}, {$default_order_sql}",
    'release_date' => "mi.release_date {$dir_sql}, {$default_order_sql}",
    'status' => "mi.status {$dir_sql}, {$default_order_sql}",
    'content_count' => "content_count {$dir_sql}, {$default_order_sql}",
];
$order_by_sql = $order_by_map[$sort];

$count_sql = "
    SELECT COUNT(*) AS total_count
    FROM magazine_issues mi
    INNER JOIN magazines m
        ON m.id = mi.magazine_id
    {$content_count_join}
    {$where_sql}
";

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_count = (int)($count_stmt->fetch()['total_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_count / $perPage));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $perPage;
$from_count = $total_count > 0 ? $offset + 1 : 0;
$to_count = min($offset + $perPage, $total_count);

$sql = "
    SELECT
        mi.id,
        mi.magazine_id,
        mi.issue_label,
        mi.issue_label_special,
        mi.issue_year,
        {$issue_vol_select},
        mi.issue_no,
        {$issue_full_vol_select},
        mi.publication_date,
        mi.publication_year,
        mi.publication_month,
        mi.publication_day,
        mi.release_date,
        mi.status,
        mi.created_at,
        mi.updated_at,
        m.name AS magazine_name,
        p.name AS publisher_name,
        COALESCE(ic.content_count, 0) AS content_count
    FROM magazine_issues mi
    INNER JOIN magazines m
        ON m.id = mi.magazine_id
    INNER JOIN publishers p
        ON p.id = m.publisher_id
    {$content_count_join}
    {$where_sql}
    ORDER BY {$order_by_sql}
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key, $value, $param_type);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$issues = $stmt->fetchAll();

$count = $total_count;

$delete_result = get_param('delete_result');
$message = '';
$error = '';

if ($delete_result === 'deleted') {
    $message = '雑誌号を削除しました。';
} elseif ($delete_result === 'has_contents') {
    $error = 'この雑誌号は目次データに使用されているため削除できません。先に目次データを削除してください。';
} elseif ($delete_result === 'not_found') {
    $error = '削除対象の雑誌号が見つかりません。';
} elseif ($delete_result === 'invalid') {
    $error = '雑誌号IDが不正です。';
} elseif ($delete_result === 'error') {
    $error = '削除エラーが発生しました。';
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌号一覧 - 管理画面</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/admin.css">

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            color: #222;
        }

        h1 {
            margin: 0 0 20px;
            font-size: 24px;
        }

        .nav {
            margin-bottom: 16px;
        }

        .nav a {
            display: inline-block;
            margin-right: 8px;
            padding: 8px 12px;
            background: #333;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-box {
            background: #fff;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }

        .search-grid {
            display: grid;
            grid-template-columns: 220px minmax(320px, 440px) 160px minmax(220px, 1fr) auto auto auto;
            gap: 12px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
            font-size: 14px;
        }

        input[type="text"],
        select {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            background: #fff;
        }

        button,
        .clear-button {
            display: inline-block;
            padding: 9px 14px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            white-space: nowrap;
        }

        button {
            background: #0066cc;
            color: #fff;
        }

        .clear-button {
            background: #777;
            color: #fff;
        }

        .result-info {
            margin-bottom: 10px;
            font-size: 14px;
        }

        .pagination {
            margin: 12px 0;
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
            font-size: 13px;
        }

        .pagination a:hover {
            background: #f0f6ff;
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
            font-size: 13px;
            line-height: 1;
        }

        .page-jump button {
            padding: 7px 10px;
            font-size: 13px;
            line-height: 1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid #ddd;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #eee;
            white-space: nowrap;
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

        tr:nth-child(even) {
            background: #fafafa;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .actions a {
            display: inline-block;
            margin-right: 6px;
            color: #0066cc;
            text-decoration: none;
        }

        .actions a.delete {
            color: #cc0000;
        }

        .status {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            background: #eee;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .page-jump {
                width: 100%;
                margin-left: 0;
                padding-left: 0;
                flex-wrap: wrap;
            }

            .page-jump input[type="number"] {
                flex: 1;
                width: auto;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>

<h1>雑誌号一覧</h1>

<div class="nav">
    <a href="index.php">管理トップ</a>
    <a href="issue_add.php">雑誌号を追加</a>
</div>

<?php if ($message !== ''): ?>
    <div class="message"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
<?php endif; ?>

<div class="search-box">
    <form method="get" action="issues.php" id="issueFilterForm">
        <div class="search-grid">

            <div class="form-group">
                <label for="publisherFilter">出版社</label>
                <select id="publisherFilter" name="publisher_id">
                    <option value="">すべて</option>
                    <?php foreach ($publishers as $row): ?>
                        <option value="<?= h($row['id']) ?>" <?= $publisher_id === (int)$row['id'] ? 'selected' : '' ?>>
                            <?= h($row['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="magazineFilter">雑誌</label>
                <select id="magazineFilter" name="magazine_id" class="select-magazine-wide">
                    <option value="">すべて</option>
                    <?php foreach ($magazines as $row): ?>
                        <option value="<?= h($row['id']) ?>" <?= $magazine_id === (int)$row['id'] ? 'selected' : '' ?>>
                            <?= h($row['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="publicationYearFilter">発行年</label>
                <select id="publicationYearFilter" name="publication_year">
                    <option value="">すべて</option>
                    <?php foreach ($years as $row): ?>
                        <?php if ($row['y'] === null || $row['y'] === '') continue; ?>
                        <?php $y = (string)$row['y']; ?>
                        <option value="<?= h($y) ?>" <?= $publication_year === $y ? 'selected' : '' ?>>
                            <?= h($y) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="q">雑誌検索</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="例：週刊少年サンデー"
                >
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="content_count_zero" value="1" <?= $content_count_zero ? 'checked' : '' ?>>
                    目次件数0のみ表示
                </label>
            </div>

            <?php if ($sort !== 'default'): ?>
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
            <?php endif; ?>
            <?php if ($dir !== 'asc'): ?>
                <input type="hidden" name="dir" value="<?= h($dir) ?>">
            <?php endif; ?>

            <div>
                <button type="submit">表示する</button>
            </div>

            <div>
                <a class="clear-button" href="issues.php">条件をリセット</a>
            </div>

        </div>
    </form>
</div>

<div class="result-info">
    全<?= h($total_count) ?>件中 <?= h($from_count) ?>～<?= h($to_count) ?>件を表示<br>
    <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($perPage) ?>件
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination" aria-label="ページ移動">
        <?php if ($page > 1): ?>
            <a href="<?= h(build_issue_page_url(1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">最初</a>
            <a href="<?= h(build_issue_page_url($page - 1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">前へ</a>
        <?php else: ?>
            <span class="disabled">最初</span>
            <span class="disabled">前へ</span>
        <?php endif; ?>

        <?php
            $start_page = max(1, $page - 3);
            $end_page = min($total_pages, $page + 3);
        ?>

        <?php if ($start_page > 1): ?>
            <a href="<?= h(build_issue_page_url(1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">1</a>
            <?php if ($start_page > 2): ?>
                <span class="dots">…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= h($i) ?></span>
            <?php else: ?>
                <a href="<?= h(build_issue_page_url($i, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>"><?= h($i) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="dots">…</span>
            <?php endif; ?>
            <a href="<?= h(build_issue_page_url($total_pages, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= h(build_issue_page_url($page + 1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">次へ</a>
            <a href="<?= h(build_issue_page_url($total_pages, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">最後</a>
        <?php else: ?>
            <span class="disabled">次へ</span>
            <span class="disabled">最後</span>
        <?php endif; ?>

        <form method="get" action="issues.php" class="page-jump">
            <?php if ($q !== ''): ?>
                <input type="hidden" name="q" value="<?= h($q) ?>">
            <?php endif; ?>
            <?php if ($publisher_id > 0): ?>
                <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
            <?php endif; ?>
            <?php if ($magazine_id > 0): ?>
                <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
            <?php endif; ?>
            <?php if ($publication_year !== ''): ?>
                <input type="hidden" name="publication_year" value="<?= h($publication_year) ?>">
            <?php endif; ?>
            <?php if ($content_count_zero): ?>
                <input type="hidden" name="content_count_zero" value="1">
            <?php endif; ?>
            <?php if ($sort !== 'default'): ?>
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

<table>
    <thead>
        <tr>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('id', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">ID<span class="sort-mark"><?= h(sort_mark('id', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('magazine', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">雑誌名<span class="sort-mark"><?= h(sort_mark('magazine', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('publisher', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">出版社<span class="sort-mark"><?= h(sort_mark('publisher', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('issue_label', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">号数表示<span class="sort-mark"><?= h(sort_mark('issue_label', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('issue_label_special', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">特別号数表示<span class="sort-mark"><?= h(sort_mark('issue_label_special', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('publication_year', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">発行年<span class="sort-mark"><?= h(sort_mark('publication_year', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('issue_vol', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">巻数<span class="sort-mark"><?= h(sort_mark('issue_vol', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('issue_no', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">号数<span class="sort-mark"><?= h(sort_mark('issue_no', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('issue_full_vol', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">通巻<span class="sort-mark"><?= h(sort_mark('issue_full_vol', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('publication_date', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">発行日<span class="sort-mark"><?= h(sort_mark('publication_date', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('release_date', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">発売日<span class="sort-mark"><?= h(sort_mark('release_date', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('status', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">状態<span class="sort-mark"><?= h(sort_mark('status', $sort, $dir)) ?></span></a></th>
            <th><a class="sort-link" href="<?= h(build_issue_sort_url('content_count', $sort, $dir, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero)) ?>">目次件数<span class="sort-mark"><?= h(sort_mark('content_count', $sort, $dir)) ?></span></a></th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($issues)): ?>
            <tr>
                <td colspan="14">該当する雑誌号はありません。</td>
            </tr>
        <?php else: ?>
            <?php foreach ($issues as $issue): ?>
                <tr>
                    <td><?= h($issue['id']) ?></td>
                    <td><?= h($issue['magazine_name']) ?></td>
                    <td><?= h($issue['publisher_name'] ?? '') ?></td>
                    <td><?= h($issue['issue_label'] ?? '') ?></td>
                    <td><?= h($issue['issue_label_special'] ?? '') ?></td>
                    <td><?= h($issue['issue_year'] ?? '') ?></td>
                    <td><?= h($issue['issue_vol'] ?? '') ?></td>
                    <td><?= h($issue['issue_no'] ?? '') ?></td>
                    <td><?= h($issue['issue_full_vol'] ?? '') ?></td>
                    <td><?= h($issue['publication_date'] ?? '') ?></td>
                    <td><?= h($issue['release_date'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($issue['status'])): ?>
                            <span class="status"><?= h($issue['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="number"><?= h($issue['content_count'] ?? 0) ?></td>
                    <td class="actions">
                        <a href="issue.php?id=<?= h($issue['id']) ?>">確認</a>
                        <a href="issue_edit.php?id=<?= h($issue['id']) ?>">編集</a>
                        <a
                            class="delete"
                            href="issue_delete.php?id=<?= h($issue['id']) ?>"
                            onclick="return confirm('この雑誌号を削除しますか？');"
                        >削除</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
    <div class="pagination" aria-label="ページ移動">
        <?php if ($page > 1): ?>
            <a href="<?= h(build_issue_page_url(1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">最初</a>
            <a href="<?= h(build_issue_page_url($page - 1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">前へ</a>
        <?php else: ?>
            <span class="disabled">最初</span>
            <span class="disabled">前へ</span>
        <?php endif; ?>

        <?php
            $start_page = max(1, $page - 3);
            $end_page = min($total_pages, $page + 3);
        ?>

        <?php if ($start_page > 1): ?>
            <a href="<?= h(build_issue_page_url(1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">1</a>
            <?php if ($start_page > 2): ?>
                <span class="dots">…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= h($i) ?></span>
            <?php else: ?>
                <a href="<?= h(build_issue_page_url($i, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>"><?= h($i) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="dots">…</span>
            <?php endif; ?>
            <a href="<?= h(build_issue_page_url($total_pages, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>"><?= h($total_pages) ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= h(build_issue_page_url($page + 1, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">次へ</a>
            <a href="<?= h(build_issue_page_url($total_pages, $q, $publisher_id, $magazine_id, $publication_year, $content_count_zero, $sort, $dir)) ?>">最後</a>
        <?php else: ?>
            <span class="disabled">次へ</span>
            <span class="disabled">最後</span>
        <?php endif; ?>

        <form method="get" action="issues.php" class="page-jump">
            <?php if ($q !== ''): ?>
                <input type="hidden" name="q" value="<?= h($q) ?>">
            <?php endif; ?>
            <?php if ($publisher_id > 0): ?>
                <input type="hidden" name="publisher_id" value="<?= h($publisher_id) ?>">
            <?php endif; ?>
            <?php if ($magazine_id > 0): ?>
                <input type="hidden" name="magazine_id" value="<?= h($magazine_id) ?>">
            <?php endif; ?>
            <?php if ($publication_year !== ''): ?>
                <input type="hidden" name="publication_year" value="<?= h($publication_year) ?>">
            <?php endif; ?>
            <?php if ($content_count_zero): ?>
                <input type="hidden" name="content_count_zero" value="1">
            <?php endif; ?>
            <?php if ($sort !== 'default'): ?>
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

<script>
const issueFilterForm = document.getElementById('issueFilterForm');
const publisherFilter = document.getElementById('publisherFilter');
const magazineFilter = document.getElementById('magazineFilter');
const publicationYearFilter = document.getElementById('publicationYearFilter');

function submitIssueFilterForm() {
    if (issueFilterForm) {
        issueFilterForm.submit();
    }
}

if (publisherFilter) {
    publisherFilter.addEventListener('change', () => {
        if (magazineFilter) {
            magazineFilter.value = '';
        }
        submitIssueFilterForm();
    });
}

if (magazineFilter) {
    magazineFilter.addEventListener('change', submitIssueFilterForm);
}

if (publicationYearFilter) {
    publicationYearFilter.addEventListener('change', submitIssueFilterForm);
}
</script>

</body>
</html>
