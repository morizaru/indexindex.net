<?php
// creator.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('人物ページ（個別）');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_page_url($page, $creator_id, $sort, $dir, $keyword, $view = 'issues') {
    $query = [
        'creator_id' => $creator_id,
    ];

    if ($view !== 'issues') {
        $query['view'] = $view;
    }

    if ($keyword !== '') {
        $query['keyword'] = $keyword;
    }

    if ($sort !== 'issue') {
        $query['sort'] = $sort;
    }

    if ($dir !== 'asc') {
        $query['dir'] = $dir;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'creator.php?' . $query_string;
}

function build_sort_url($sort_key, $current_sort, $current_dir, $creator_id, $keyword, $view = 'issues') {
    $next_dir = 'asc';

    if ($current_sort === $sort_key && $current_dir === 'asc') {
        $next_dir = 'desc';
    }

    return build_page_url(1, $creator_id, $sort_key, $next_dir, $keyword, $view);
}

function sort_mark($sort_key, $current_sort, $current_dir) {
    if ($current_sort !== $sort_key) {
        return '';
    }

    return $current_dir === 'asc' ? ' ▲' : ' ▼';
}

$creator_id = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$keyword = isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '';
$view = isset($_GET['view']) ? (string)$_GET['view'] : 'issues';
if (!in_array($view, ['issues', 'works'], true)) {
    $view = 'issues';
}

if ($creator_id <= 0) {
    exit('人物IDが指定されていません。');
}

$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 100;

$allowed_sorts = ['issue', 'title'];
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'issue';
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'issue';
}

$dir = isset($_GET['dir']) ? strtolower((string)$_GET['dir']) : 'asc';
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'asc';
}

$dir_sql = strtoupper($dir);

$issue_order_asc = "
    m.name ASC,
    CASE
        WHEN mi.publication_date IS NOT NULL
             AND mi.publication_date <> ''
             AND mi.publication_date <> '0000-00-00'
        THEN mi.publication_date
        WHEN mi.release_date IS NOT NULL
             AND mi.release_date <> ''
             AND mi.release_date <> '0000-00-00'
        THEN mi.release_date
        ELSE '9999-12-31'
    END ASC,
    COALESCE(mi.issue_year, mi.publication_year, 9999) ASC,
    CASE
        WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
        ELSE 999999
    END ASC,
    mi.id ASC
";

$issue_order_desc = "
    m.name ASC,
    CASE
        WHEN mi.publication_date IS NOT NULL
             AND mi.publication_date <> ''
             AND mi.publication_date <> '0000-00-00'
        THEN mi.publication_date
        WHEN mi.release_date IS NOT NULL
             AND mi.release_date <> ''
             AND mi.release_date <> '0000-00-00'
        THEN mi.release_date
        ELSE '0000-01-01'
    END DESC,
    COALESCE(mi.issue_year, mi.publication_year, 0) DESC,
    CASE
        WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
        ELSE 0
    END DESC,
    mi.id DESC
";

$issue_order = ($dir === 'desc') ? $issue_order_desc : $issue_order_asc;

$order_by_map = [
    'issue' => "
        {$issue_order},
        ic.sort_order ASC,
        ic.id ASC
    ",
    'title' => "
        ic.title_main {$dir_sql},
        {$issue_order_asc},
        ic.sort_order ASC,
        ic.id ASC
    ",
];
$order_by_sql = $order_by_map[$sort];

$work_order_by_map = [
    'issue' => "
        MIN(mi.issue_year) {$dir_sql},
        MIN(mi.id) {$dir_sql},
        w.title ASC,
        w.id ASC
    ",
    'title' => "
        w.title {$dir_sql},
        MIN(mi.issue_year) ASC,
        MIN(mi.id) ASC,
        w.id ASC
    ",
];
$work_order_by_sql = $work_order_by_map[$sort];

// 人物情報
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.name_kana,
        c.debut_work_id,
        w.title AS debut_work_title
    FROM creators c
    LEFT JOIN works w ON c.debut_work_id = w.id
    WHERE c.id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $creator_id
]);
$creator = $stmt->fetch();

if (!$creator) {
    exit('指定された人物が見つかりません。');
}

$keyword_where_sql = '';
$keyword_params = [];
if ($keyword !== '') {
    $keyword_where_sql = "
      AND (
          c.name LIKE :keyword_creator_name
          OR w.title LIKE :keyword_work_title
          OR w.title_sub LIKE :keyword_work_title_sub
          OR ic.title_main LIKE :keyword_content_title
          OR mi.issue_label LIKE :keyword_issue_label
          OR m.name LIKE :keyword_magazine
      )
    ";
    $keyword_like = '%' . $keyword . '%';
    $keyword_params = [
        ':keyword_creator_name' => $keyword_like,
        ':keyword_work_title' => $keyword_like,
        ':keyword_work_title_sub' => $keyword_like,
        ':keyword_content_title' => $keyword_like,
        ':keyword_issue_label' => $keyword_like,
        ':keyword_magazine' => $keyword_like,
    ];
}

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT ic.id) AS total_count
    FROM content_creators cc
    JOIN issue_contents ic ON cc.content_id = ic.id
    WHERE cc.creator_id = :creator_id
");
$stmt->execute([
    ':creator_id' => $creator_id
]);
$creator_total_content_count = (int)($stmt->fetch()['total_count'] ?? 0);
$should_noindex = ($creator_total_content_count === 0);

$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT ic.work_id) AS work_count,
        COUNT(DISTINCT ic.issue_id) AS issue_count,
        GROUP_CONCAT(
            DISTINCT
            CASE
                WHEN cc.role IS NOT NULL
                     AND cc.role <> ''
                THEN cc.role
                ELSE 'author'
            END
            ORDER BY cc.role
            SEPARATOR ' / '
        ) AS roles
    FROM content_creators cc
    JOIN issue_contents ic ON cc.content_id = ic.id
    WHERE cc.creator_id = :creator_id
");
$stmt->execute([
    ':creator_id' => $creator_id
]);
$creator_summary = $stmt->fetch() ?: [];
$creator_work_count = (int)($creator_summary['work_count'] ?? 0);
$creator_issue_count = (int)($creator_summary['issue_count'] ?? 0);
$creator_roles = trim((string)($creator_summary['roles'] ?? ''));

// 総件数
if ($view === 'works') {
    $count_sql = "
        SELECT COUNT(DISTINCT w.id) AS total_count
        FROM content_creators cc
        JOIN issue_contents ic ON cc.content_id = ic.id
        JOIN works w ON ic.work_id = w.id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN creators c ON cc.creator_id = c.id
        WHERE cc.creator_id = :creator_id
          {$keyword_where_sql}
    ";
} else {
    $count_sql = "
        SELECT COUNT(DISTINCT ic.id) AS total_count
        FROM content_creators cc
        JOIN issue_contents ic ON cc.content_id = ic.id
        LEFT JOIN works w ON ic.work_id = w.id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN creators c ON cc.creator_id = c.id
        WHERE cc.creator_id = :creator_id
          {$keyword_where_sql}
    ";
}

$stmt = $pdo->prepare($count_sql);
$stmt->execute(array_merge([
    ':creator_id' => $creator_id
], $keyword_params));
$total_count = (int)($stmt->fetch()['total_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_count / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$contents = [];
$works = [];

if ($view === 'works') {
    // 作品別一覧：1ページ分だけ取得
    $sql = "
        SELECT
            w.id,
            w.title,
            w.title_sub,
            w.publication_type,
            COUNT(DISTINCT ic.id) AS content_count,
            COUNT(DISTINCT ic.issue_id) AS issue_count,
            MIN(mi.issue_year) AS first_year,
            MAX(mi.issue_year) AS last_year,
            GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ' / ') AS magazine_names,
            GROUP_CONCAT(
                DISTINCT
                CASE
                    WHEN cc.role IS NOT NULL
                         AND cc.role <> ''
                         AND cc.role <> 'author'
                    THEN cc.role
                    ELSE NULL
                END
                ORDER BY cc.role
                SEPARATOR ' / '
            ) AS roles
        FROM content_creators cc
        JOIN issue_contents ic ON cc.content_id = ic.id
        JOIN works w ON ic.work_id = w.id
        JOIN magazine_issues mi ON ic.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN creators c ON cc.creator_id = c.id
        WHERE cc.creator_id = :creator_id
          {$keyword_where_sql}
        GROUP BY
            w.id,
            w.title,
            w.title_sub,
            w.publication_type
        ORDER BY {$work_order_by_sql}
        LIMIT :limit OFFSET :offset
    ";
} else {
    // 掲載一覧：1ページ分だけ取得
    $sql = "
    SELECT
        ic.id AS content_id,
        ic.issue_id,
        ic.work_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_no,
        ic.episode_title,
        ic.note,

        w.title AS work_title,

        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        mi.publication_date,
        mi.publication_year,
        mi.release_date,

        m.name AS magazine_name,
        p.name AS publisher_name,

        GROUP_CONCAT(
            DISTINCT
            CASE
                WHEN cc.role IS NOT NULL
                     AND cc.role <> ''
                     AND cc.role <> 'author'
                THEN cc.role
                ELSE NULL
            END
            ORDER BY cc.display_order
            SEPARATOR ' / '
        ) AS roles
    FROM content_creators cc
    JOIN issue_contents ic ON cc.content_id = ic.id
    LEFT JOIN works w ON ic.work_id = w.id
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    JOIN creators c ON cc.creator_id = c.id
    WHERE cc.creator_id = :creator_id
      {$keyword_where_sql}
    GROUP BY
        ic.id,
        ic.issue_id,
        ic.work_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_no,
        ic.episode_title,
        ic.note,
        w.title,
        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        mi.publication_date,
        mi.publication_year,
        mi.release_date,
        m.name,
        p.name
    ORDER BY {$order_by_sql}
    LIMIT :limit OFFSET :offset
";
}

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':creator_id', $creator_id, PDO::PARAM_INT);
foreach ($keyword_params as $param_name => $param_value) {
    $stmt->bindValue($param_name, $param_value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
if ($view === 'works') {
    $works = $stmt->fetchAll();
} else {
    $contents = $stmt->fetchAll();
}

$from_count = $total_count > 0 ? $offset + 1 : 0;
$to_count = min($offset + $per_page, $total_count);

$sort_labels = [
    'issue' => '雑誌・号',
    'title' => 'タイトル',
];
$current_sort_label = ($view === 'works' && $sort === 'issue') ? '収録年' : ($sort_labels[$sort] ?? '雑誌・号');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <?php if (!empty($should_noindex)): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <title><?= h($creator['name']) ?> - 人物詳細 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1100px;
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

        .nav a:hover {
            text-decoration: underline;
        }

        .condition-box {
            margin-bottom: 16px;
            color: #666;
            line-height: 1.7;
        }

        .creator-profile,
        .creator-list-block {
            border: 1px solid #e5e5e5;
            background: #fafafa;
            border-radius: 8px;
            padding: 18px;
        }

        .creator-profile {
            margin-bottom: 18px;
        }

        .creator-profile h1 {
            margin-bottom: 10px;
        }

        .creator-list-block {
            background: #fff;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
            line-height: 1.7;
        }

        .tab-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 16px;
        }

        .tab-nav a {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            color: #0066cc;
            font-weight: bold;
            line-height: 1;
            text-decoration: none;
        }

        .tab-nav a.active {
            background: #333;
            border-color: #333;
            color: #fff;
        }

        .page-description {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 14px 16px;
            margin: 16px 0 20px;
            color: #444;
            line-height: 1.8;
            font-size: 14px;
        }

        .page-description strong {
            color: #222;
        }

        .filter-box {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
        }

        .filter-box input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .filter-box button,
        .filter-box a {
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: bold;
            line-height: 1;
            white-space: nowrap;
        }

        .filter-box button {
            border: 1px solid #333;
            background: #333;
            color: #fff;
            cursor: pointer;
        }

        .filter-box a {
            border: 1px solid #ddd;
            background: #fff;
            color: #0066cc;
            text-decoration: none;
        }

        .pagination {
            margin: 16px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
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
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
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

        .page {
            width: 100px;
            text-align: center;
        }

        .episode {
            width: 100px;
        }

        .num {
            width: 90px;
            text-align: right;
            white-space: nowrap;
        }

        .year {
            width: 130px;
            white-space: nowrap;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .note-row .note-cell {
            background: #fafafa;
        }

        .note-row .note-cell::before {
            content: none;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
            margin-top: 4px;
        }

        .creator-kana {
            color: #555;
            font-size: 0.95em;
            line-height: 1.25;
            margin-bottom: 0;
        }

        .debut-work {
            color: #555;
            font-size: 14px;
            margin: -4px 0 0;
        }

        .title-main {
            font-weight: bold;
        }

        .empty {
            color: #666;
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

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .creator-profile,
            .creator-list-block {
                padding: 12px;
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

            .filter-box {
                flex-wrap: wrap;
            }

            .filter-box input {
                flex: 1 1 100%;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            thead {
                display: none;
            }

            tbody.creator-item {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 8px;
            }

            tr {
                border: none;
                margin: 0;
                padding: 0;
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

            td.issue::before { content: "雑誌・号"; }
            td.page::before { content: "ページ"; }
            td.title::before { content: "タイトル"; }
            td.episode::before { content: "話数"; }
            td.episode-title::before { content: "話タイトル"; }
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
$current_page = 'creator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <section class="creator-profile" aria-label="人物プロフィール">
        <?php $nameKana = trim((string)($creator['name_kana'] ?? '')); ?>
        <?php if ($nameKana !== ''): ?>
            <div class="creator-kana"><?= h($nameKana) ?></div>
        <?php endif; ?>

        <h1><?= h($creator['name']) ?></h1>

        <?php if (!empty($creator['debut_work_id']) && !empty($creator['debut_work_title'])): ?>
            <div class="debut-work">
                デビュー作：
                <a href="work.php?work_id=<?= h($creator['debut_work_id']) ?>"><?= h($creator['debut_work_title']) ?></a>
            </div>
        <?php endif; ?>

        <div class="page-description">
            <?php if ($creator_total_content_count > 0): ?>
                このページでは、<strong><?= h($creator['name']) ?></strong>に関連する掲載情報を掲載しています。登録済みの掲載<?= h($creator_total_content_count) ?>件、関連作品<?= h($creator_work_count) ?>件、関連雑誌号<?= h($creator_issue_count) ?>件について、作品、雑誌号、役割、掲載順などを確認できます。<?= $creator_roles !== '' ? '主な役割：' . h($creator_roles) . '。' : '' ?>
            <?php else: ?>
                この人物に関連する掲載情報はまだ登録されていません。データは順次追加・整理しています。
            <?php endif; ?>
        </div>
    </section>

    <section class="creator-list-block" aria-label="掲載リスト">

    <div class="tab-nav" aria-label="表示切替">
        <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, 'issues')) ?>" class="<?= $view === 'issues' ? 'active' : '' ?>">掲載一覧</a>
        <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, 'works')) ?>" class="<?= $view === 'works' ? 'active' : '' ?>">作品別</a>
    </div>

    <div class="condition-box">
        表示：<?= h($view === 'works' ? '作品別' : '掲載一覧') ?> /
        キーワード：<?= h($keyword !== '' ? $keyword : '指定なし') ?> /
        並び順：<?= h($current_sort_label) ?><?= h($dir === 'asc' ? ' 昇順' : ' 降順') ?>
    </div>

    <div class="count">
        全<?= h($total_count) ?>件中 <?= h($from_count) ?>〜<?= h($to_count) ?>件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?>ページ、1ページ<?= h($per_page) ?>件
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination" aria-label="ページ移動">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, $view)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $creator_id, $sort, $dir, $keyword, $view)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, $view)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $creator_id, $sort, $dir, $keyword, $view)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $creator_id, $sort, $dir, $keyword, $view)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $creator_id, $sort, $dir, $keyword, $view)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $creator_id, $sort, $dir, $keyword, $view)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="creator.php" class="page-jump">
                <input type="hidden" name="creator_id" value="<?= h($creator_id) ?>">
                <?php if ($view !== 'issues'): ?>
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php endif; ?>
                <?php if ($keyword !== ''): ?>
                    <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'issue'): ?>
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

    <form method="get" action="creator.php" class="filter-box">
        <input type="hidden" name="creator_id" value="<?= h($creator_id) ?>">
        <?php if ($view !== 'issues'): ?>
            <input type="hidden" name="view" value="<?= h($view) ?>">
        <?php endif; ?>
        <?php if ($sort !== 'issue'): ?>
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <?php endif; ?>
        <?php if ($dir !== 'asc'): ?>
            <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <?php endif; ?>
        <input type="text" name="keyword" value="<?= h($keyword) ?>" placeholder="全データから雑誌名・号・タイトルで絞り込み">
        <button type="submit">絞り込み</button>
        <?php if ($keyword !== ''): ?>
            <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, '', $view)) ?>">解除</a>
        <?php endif; ?>
    </form>

    <?php if (empty($contents)): ?>

        <p class="empty">この人物に関連する掲載情報はまだ登録されていません。データは順次追加・整理しています。</p>

    <?php else: ?>

        <table id="contentsTable">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" href="<?= h(build_sort_url('issue', $sort, $dir, $creator_id, $keyword)) ?>">
                            雑誌・号<span class="sort-mark"><?= h(sort_mark('issue', $sort, $dir)) ?></span>
                        </a>
                    </th>
                    <th class="page">ページ</th>
                    <th>
                        <a class="sort-link" href="<?= h(build_sort_url('title', $sort, $dir, $creator_id, $keyword)) ?>">
                            タイトル<span class="sort-mark"><?= h(sort_mark('title', $sort, $dir)) ?></span>
                        </a>
                    </th>
                    <th class="episode">話数</th>
                    <th>話タイトル</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
                <tr>
                    <td class="issue">
                        <a href="issue.php?id=<?= h($row['issue_id']) ?>">
                            <?= h($row['magazine_name']) ?><br>
                            <?= h($row['issue_label']) ?>
                        </a>
                        <div class="small">
                            <?= h($row['publisher_name']) ?><br>
                            <?php if (!empty($row['roles'])): ?>
                                役割：<?= h($row['roles']) ?>
                            <?php endif; ?>
                        </div>
                    </td>

                        <td class="page">
                            <?php if ($row['page_start'] !== null && $row['page_start'] !== '' && $row['page_end'] !== null && $row['page_end'] !== ''): ?>
                                <?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?>
                            <?php elseif ($row['page_start'] !== null && $row['page_start'] !== ''): ?>
                                <?= h($row['page_start']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td class="title">
                            <span class="title-main">
                                <?php if (!empty($row['work_id'])): ?>
                                    <a href="work.php?work_id=<?= h($row['work_id']) ?>">
                                        <?= h($row['title_main']) ?>
                                    </a>
                                <?php else: ?>
                                    <?= h($row['title_main']) ?>
                                <?php endif; ?>
                            </span>
                        </td>

                    <td class="episode"><?= h($row['episode_no'] ?? '') ?></td>

                    <td class="episode-title"><?= h($row['episode_title'] ?? '') ?></td>

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

    <?php if ($total_pages > 1): ?>
        <div class="pagination" aria-label="ページ移動">
            <?php if ($page > 1): ?>
                <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, $view)) ?>">最初</a>
                <a href="<?= h(build_page_url($page - 1, $creator_id, $sort, $dir, $keyword, $view)) ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">最初</span>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
            ?>

            <?php if ($start_page > 1): ?>
                <a href="<?= h(build_page_url(1, $creator_id, $sort, $dir, $keyword, $view)) ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= h($i) ?></span>
                <?php else: ?>
                    <a href="<?= h(build_page_url($i, $creator_id, $sort, $dir, $keyword, $view)) ?>"><?= h($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="dots">…</span>
                <?php endif; ?>
                <a href="<?= h(build_page_url($total_pages, $creator_id, $sort, $dir, $keyword, $view)) ?>"><?= h($total_pages) ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= h(build_page_url($page + 1, $creator_id, $sort, $dir, $keyword, $view)) ?>">次へ</a>
                <a href="<?= h(build_page_url($total_pages, $creator_id, $sort, $dir, $keyword, $view)) ?>">最後</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
                <span class="disabled">最後</span>
            <?php endif; ?>

            <form method="get" action="creator.php" class="page-jump">
                <input type="hidden" name="creator_id" value="<?= h($creator_id) ?>">
                <?php if ($view !== 'issues'): ?>
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php endif; ?>
                <?php if ($keyword !== ''): ?>
                    <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'issue'): ?>
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

    </section>

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
