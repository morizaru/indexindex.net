<?php
// index.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('トップページ');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$magazineCount = null;
$issueCount = null;
$workCount = null;
$creatorCount = null;
$contentCount = null;

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $magazineCount = (int)$pdo->query("
            SELECT COUNT(DISTINCT m.id)
            FROM magazines m
            JOIN magazine_issues mi ON mi.magazine_id = m.id
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ")->fetchColumn();
        $issueCount = (int)$pdo->query("
            SELECT COUNT(DISTINCT mi.id)
            FROM magazine_issues mi
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ")->fetchColumn();
        $workCount = (int)$pdo->query("SELECT COUNT(*) FROM works")->fetchColumn();
        $creatorCount = (int)$pdo->query("SELECT COUNT(*) FROM creators")->fetchColumn();
        $contentCount = (int)$pdo->query("
            SELECT COUNT(*)
            FROM issue_contents ic
            JOIN magazine_issues mi ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ")->fetchColumn();
    }
} catch (Throwable $e) {
    $magazineCount = null;
    $issueCount = null;
    $workCount = null;
    $creatorCount = null;
    $contentCount = null;
}

function index_fetch_all(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function index_fetch_access_ranking(PDO $pdo, string $type, int $limit = 5) {
    if ($type === 'works') {
        $sql = "
            SELECT
                w.id,
                w.title AS name,
                COUNT(*) AS pv,
                COUNT(DISTINCT al.visitor_hash) AS uu
            FROM access_logs al
            JOIN works w
              ON w.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'work_id=', -1), '&', 1) AS UNSIGNED)
            WHERE al.site_key = 'indexindex'
              AND al.is_bot = 0
              AND al.path IN ('/indexindex/work.php', '/indexindex/work_graph.php')
              AND al.query_string REGEXP '(^|&)work_id=[0-9]+'
            GROUP BY w.id, w.title
            ORDER BY pv DESC, uu DESC, w.title ASC
            LIMIT {$limit}
        ";
        return index_fetch_all($pdo, $sql);
    }

    if ($type === 'magazines') {
        $sql = "
            SELECT
                m.id,
                m.name AS name,
                COUNT(*) AS pv,
                COUNT(DISTINCT r.visitor_hash) AS uu
            FROM (
                SELECT
                    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'magazine_id=', -1), '&', 1) AS UNSIGNED) AS magazine_id,
                    al.visitor_hash
                FROM access_logs al
                WHERE al.site_key = 'indexindex'
                  AND al.is_bot = 0
                  AND al.path = '/indexindex/issues.php'
                  AND al.query_string REGEXP '(^|&)magazine_id=[0-9]+'

                UNION ALL

                SELECT
                    mi.magazine_id,
                    al.visitor_hash
                FROM access_logs al
                JOIN magazine_issues mi
                  ON mi.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'id=', -1), '&', 1) AS UNSIGNED)
                WHERE al.site_key = 'indexindex'
                  AND al.is_bot = 0
                  AND al.path = '/indexindex/issue.php'
                  AND al.query_string REGEXP '(^|&)id=[0-9]+'
            ) r
            JOIN magazines m ON m.id = r.magazine_id
            GROUP BY m.id, m.name
            ORDER BY pv DESC, uu DESC, m.name ASC
            LIMIT {$limit}
        ";
        return index_fetch_all($pdo, $sql);
    }

    $sql = "
        SELECT
            c.id,
            c.name,
            COUNT(*) AS pv,
            COUNT(DISTINCT al.visitor_hash) AS uu
        FROM access_logs al
        JOIN creators c
          ON c.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'creator_id=', -1), '&', 1) AS UNSIGNED)
        WHERE al.site_key = 'indexindex'
          AND al.is_bot = 0
          AND al.path = '/indexindex/creator.php'
          AND al.query_string REGEXP '(^|&)creator_id=[0-9]+'
        GROUP BY c.id, c.name
        ORDER BY pv DESC, uu DESC, c.name ASC
        LIMIT {$limit}
    ";
    return index_fetch_all($pdo, $sql);
}

function index_fetch_table_columns(PDO $pdo, string $table) {
    $allowed_tables = ['magazine_issues', 'creators', 'magazines', 'works'];
    if (!in_array($table, $allowed_tables, true)) {
        return [];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}`");
    $stmt->execute();

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $columns[$row['Field']] = true;
        }
    }

    return $columns;
}

function index_has_column(array $columns, string $name) {
    return isset($columns[$name]);
}

function index_select_column(array $columns, string $alias, string $column, string $as) {
    return index_has_column($columns, $column) ? "{$alias}.`{$column}` AS {$as}" : "NULL AS {$as}";
}

function index_recent_datetime_expr(array $columns, string $alias) {
    if (index_has_column($columns, 'updated_at') && index_has_column($columns, 'created_at')) {
        return "COALESCE({$alias}.updated_at, {$alias}.created_at)";
    }

    if (index_has_column($columns, 'updated_at')) {
        return "{$alias}.updated_at";
    }

    if (index_has_column($columns, 'created_at')) {
        return "{$alias}.created_at";
    }

    return 'NULL';
}

function index_recent_order_sql(array $columns, string $alias) {
    $order = index_recent_datetime_expr($columns, $alias) . ' DESC';

    if (index_has_column($columns, 'id')) {
        $order .= ", {$alias}.id DESC";
    }

    return $order;
}

function index_fetch_recent_issues(PDO $pdo, array $issue_columns, array $magazine_columns, int $limit = 5) {
    if (!index_has_column($issue_columns, 'id')) {
        return [];
    }

    $join_magazines = index_has_column($issue_columns, 'magazine_id') && index_has_column($magazine_columns, 'id');
    $magazine_name_select = $join_magazines && index_has_column($magazine_columns, 'name')
        ? 'm.name AS magazine_name'
        : 'NULL AS magazine_name';

    $sql = "
        SELECT
            mi.id,
            {$magazine_name_select},
            " . index_select_column($issue_columns, 'mi', 'issue_label', 'issue_label') . "
        FROM magazine_issues mi
    ";

    if ($join_magazines) {
        $sql .= "
            LEFT JOIN magazines m
                ON m.id = mi.magazine_id
        ";
    }

    $sql .= "
        ORDER BY " . index_recent_order_sql($issue_columns, 'mi') . "
        LIMIT {$limit}
    ";

    return index_fetch_all($pdo, $sql);
}

function index_fetch_recent_works(PDO $pdo, array $work_columns, int $limit = 5) {
    if (!index_has_column($work_columns, 'id')) {
        return [];
    }

    $sql = "
        SELECT
            w.id,
            " . index_select_column($work_columns, 'w', 'title', 'title') . "
        FROM works w
        ORDER BY " . index_recent_order_sql($work_columns, 'w') . "
        LIMIT {$limit}
    ";

    return index_fetch_all($pdo, $sql);
}

function index_fetch_recent_creators(PDO $pdo, array $creator_columns, int $limit = 5) {
    if (!index_has_column($creator_columns, 'id')) {
        return [];
    }

    $sql = "
        SELECT
            c.id,
            " . index_select_column($creator_columns, 'c', 'name', 'name') . "
        FROM creators c
        ORDER BY " . index_recent_order_sql($creator_columns, 'c') . "
        LIMIT {$limit}
    ";

    return index_fetch_all($pdo, $sql);
}

$sideAccessRankings = [
    'works' => [],
    'magazines' => [],
    'creators' => [],
];
$sideRecentUpdates = [
    'issues' => [],
    'works' => [],
    'creators' => [],
];

$collection_stats = [
    ['label' => '登録雑誌数', 'value' => $magazineCount, 'unit' => '誌'],
    ['label' => '登録雑誌号数', 'value' => $issueCount, 'unit' => '号'],
    ['label' => '登録作品数', 'value' => $workCount, 'unit' => '件'],
    ['label' => '登録人物数', 'value' => $creatorCount, 'unit' => '人'],
    ['label' => '登録目次件数', 'value' => $contentCount, 'unit' => '件'],
];

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $sideAccessRankings['works'] = index_fetch_access_ranking($pdo, 'works', 5);
        $sideAccessRankings['magazines'] = index_fetch_access_ranking($pdo, 'magazines', 5);
        $sideAccessRankings['creators'] = index_fetch_access_ranking($pdo, 'creators', 5);

        $issue_columns = index_fetch_table_columns($pdo, 'magazine_issues');
        $work_columns = index_fetch_table_columns($pdo, 'works');
        $creator_columns = index_fetch_table_columns($pdo, 'creators');
        $magazine_columns = index_fetch_table_columns($pdo, 'magazines');

        $sideRecentUpdates['issues'] = index_fetch_recent_issues($pdo, $issue_columns, $magazine_columns, 5);
        $sideRecentUpdates['works'] = index_fetch_recent_works($pdo, $work_columns, 5);
        $sideRecentUpdates['creators'] = index_fetch_recent_creators($pdo, $creator_columns, 5);
    }
} catch (Throwable $e) {
    $sideAccessRankings = [
        'works' => [],
        'magazines' => [],
        'creators' => [],
    ];
    $sideRecentUpdates = [
        'issues' => [],
        'works' => [],
        'creators' => [],
    ];
}

$menu_items = [
    [
        'title' => '雑誌一覧',
        'description' => '雑誌データを表示します。',
        'url' => 'issues.php',
    ],
    [
        'title' => '作品一覧',
        'description' => '掲載作品データを表示します。',
        'url' => 'works.php',
    ],
    [
        'title' => '人物一覧',
        'description' => '関連人物データを表示します。',
        'url' => 'creators.php',
    ],
    [
        'title' => '詳細検索',
        'description' => '作品名・作者名・雑誌名から検索します。',
        'url' => 'search.php',
    ],
    [
        'title' => '作品連載順グラフ',
        'description' => '作品の掲載順を年別にグラフで表示します。',
        'url' => 'work_graph.php',
    ],
    [
        'title' => 'メディアミックス',
        'description' => 'メディアミックスの作品の一覧を表示します。',
        'url' => 'mediamix.php',
    ],
    [
        'title' => 'アクセスランキング',
        'description' => '雑誌・人物・作品のアクセスランキングを表示します。',
        'url' => 'ranking.php',
    ],
    [
        'title' => '最近の更新',
        'description' => '最近更新した雑誌号、人物、雑誌を表示します。',
        'url' => 'recent_updates.php',
    ],
    [
        'title' => 'INDEXINDEXについて',
        'description' => '漫画雑誌目次データベースを作っている目的と、収録している情報を説明します。',
        'url' => 'about.php',
    ],
    [
        'title' => '使い方',
        'description' => '雑誌号、作品名、作者・関係者、掲載順グラフから探す方法を説明します。',
        'url' => 'how_to_use.php',
    ],
    [
        'title' => 'データ登録方針',
        'description' => '雑誌号、作品名、作者名、掲載順、発売日、読切や新連載などの扱いを説明します。',
        'url' => 'data_policy.php',
    ],
    [
        'title' => '用語説明',
        'description' => '雑誌号、目次、掲載順、読切、出張掲載、原作、作画などの用語を説明します。',
        'url' => 'terms.php',
    ],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>INDEXINDEX</title>

    <!-- お名前.com レンタルサーバー / モリサワWebフォント -->
    <script type="text/javascript" src="//code.typesquare.com/static/ZDbTe4IzCko%253D/ts106f.js" charset="utf-8"></script>

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            background: #f5f5f5;
            color: #222;
        }

        /*
            main部分を中央に置くための外枠
            sidebarはこの外枠の右側に配置
        */
        .page-shell {
            position: relative;
            max-width: calc(1100px + 18px + 240px);
            margin: 0 auto;
            padding: 24px 0;
            box-sizing: border-box;
        }

        /*
            中央のmain部分
        */
        .container {
            width: 1100px;
            box-sizing: border-box;
        }

        .hero {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            border: 1px solid #e0e0e0;
            box-sizing: border-box;
        }

        .hero img {
            width: 100%;
            max-width: 900px;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .subtitle {
            margin-top: 16px;
            color: #666;
            font-size: 15px;
            line-height: 1.7;
        }

        .top-search {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 24px;
            box-sizing: border-box;
        }

        .top-search-form {
            display: flex;
            gap: 10px;
        }

        .top-search-form input[type="text"] {
            flex: 1 1 auto;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
        }

        .top-search-form button {
            flex: 0 0 auto;
            padding: 0 22px;
            border: 0;
            border-radius: 8px;
            background: #222;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .top-search-form button:hover {
            background: #444;
        }

        .stats-section,
        .guide-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 24px;
            box-sizing: border-box;
        }

        .stats-section h2,
        .guide-section h2 {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0 0 14px;
            font-size: 22px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        .stats-card {
            padding: 14px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            background: #fafafa;
            box-sizing: border-box;
        }

        .stats-label {
            color: #666;
            font-size: 13px;
            line-height: 1.4;
        }

        .stats-value {
            margin-top: 6px;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            color: #222;
        }

        .guide-section p {
            margin: 0 0 12px;
            color: #444;
            line-height: 1.8;
        }

        .guide-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px 18px;
            margin: 0;
            padding-left: 1.4em;
            color: #444;
            line-height: 1.7;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .about-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 0;
            margin-bottom: 24px;
            box-sizing: border-box;
            line-height: 1.8;
            overflow: hidden;
        }

        .about-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 14px 18px;
            border: 0;
            background: #222;
            color: #fff;
            cursor: pointer;
            text-align: left;
        }

        .about-toggle::after {
            content: "+";
            flex: 0 0 auto;
            margin-left: 8px;
            font-size: 20px;
            line-height: 1;
        }

        .about-toggle[aria-expanded="true"]::after {
            content: "-";
        }

        .about-content {
            padding: 18px 22px 20px;
        }

        .about-toggle-title {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }

        .about-section h3 {
            margin: 18px 0 8px;
            font-size: 17px;
            color: #222;
        }

        .about-section p {
            margin: 0 0 10px;
            color: #444;
        }

        .about-section ul {
            margin: 0 0 10px;
            padding-left: 1.4em;
            color: #444;
        }

        .about-section li {
            margin-bottom: 4px;
        }

        .about-section p:last-child,
        .about-section ul:last-child {
            margin-bottom: 0;
        }

        .menu-card {
            display: block;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            text-decoration: none;
            color: #222;
            transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
            box-sizing: border-box;
            overflow: hidden;
        }

        .menu-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            border-color: #bbb;
        }

        .menu-title {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 20px;
            font-weight: bold;
            margin: -20px -20px 14px;
            padding: 12px 20px;
            background: #222;
            color: #fff;
            line-height: 1.35;
        }

        .menu-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .menu-counts {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 13px;
            line-height: 1.7;
        }

        /*
            sidebarはmain部分の右側に添える
            mainの中央位置には影響させない
        */
        .sidebar-area {
            position: absolute;
            top: 24px;
            left: calc(1100px + 18px);
            width: 240px;
        }

        .side-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 14px;
            font-size: 13px;
            line-height: 1.6;
            box-sizing: border-box;
        }

        .side-box + .side-box {
            margin-top: 18px;
        }

        .side-section + .side-section {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #eee;
        }

        .side-box-title {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #222;
        }

        .side-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin: -14px -14px 0;
            padding: 12px 14px;
            border: 0;
            border-radius: 12px 12px 0 0;
            background: #222;
            color: #fff;
            cursor: pointer;
            text-align: left;
        }

        .side-toggle::after {
            content: "+";
            flex: 0 0 auto;
            margin-left: 8px;
            font-size: 18px;
            line-height: 1;
        }

        .side-toggle[aria-expanded="true"]::after {
            content: "-";
        }

        .side-toggle-content {
            padding-top: 10px;
        }

        .about-section .side-toggle {
            margin: 0;
            padding: 14px 18px;
            border-radius: 0;
        }

        .side-ranking {
            margin: 0;
            padding-left: 1.4em;
        }

        .side-ranking li {
            margin-bottom: 8px;
        }

        .side-subtitle {
            margin: 14px 0 6px;
            padding: 5px 8px;
            border-left: 4px solid #222;
            background: #f3f3f3;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 700;
            color: #222;
        }

        .side-link {
            display: block;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            color: #333;
            text-decoration: none;
        }

        .side-link:last-child {
            border-bottom: 0;
        }

        .side-link:hover {
            text-decoration: underline;
        }

        .side-note {
            margin-top: 10px;
            color: #888;
            font-size: 12px;
            line-height: 1.5;
        }

        .footer {
            width: 1100px;
            margin-top: 28px;
            text-align: center;
            color: #888;
            font-size: 13px;
            line-height: 1.8;
            box-sizing: border-box;
        }

        .footer a {
            color: #666;
            text-decoration: none;
            margin: 0 6px;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /*
            sidebar込みで横幅が足りない時は、
            main → sidebar → footer の順で縦並びにする
        */
        @media (max-width: 1400px) {
            .page-shell {
                padding: 24px;
            }

            .container {
                width: 100%;
            }

            .sidebar-area {
                position: static;
                width: 100%;
                margin-top: 18px;
            }

            .footer {
                width: 100%;
            }
        }

        @media (max-width: 700px) {
            .page-shell {
                padding: 12px;
            }

            .hero {
                padding: 14px;
                border-radius: 8px;
            }

            .subtitle {
                font-size: 14px;
            }

            .top-search {
                padding: 14px;
                border-radius: 8px;
            }

            .stats-section,
            .guide-section {
                padding: 16px;
            }

            .stats-value {
                font-size: 21px;
            }

            .top-search-form {
                flex-direction: column;
            }

            .top-search-form button {
                width: 100%;
                min-height: 44px;
            }

            .menu-card {
                padding: 16px;
            }

            .menu-title {
                margin: -16px -16px 12px;
                padding: 11px 16px;
                font-size: 18px;
            }

            .about-section {
                padding: 0;
            }

            .menu-title {
                font-size: 18px;
            }

            .footer {
                font-size: 12px;
            }

            .footer a {
                display: inline-block;
                margin: 0 4px;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

</head>
<body>

<div class="page-shell">

    <main class="container">

        <div class="hero">
            <img src="assets/images/main_title.png" alt="INDEXINDEX">

            <div class="subtitle">
                雑誌の目次・掲載順・人物・作品情報を閲覧するためのデータベースです。
            </div>
        </div>

        <section class="about-section">
            <button type="button" class="about-toggle side-toggle" aria-expanded="false">
                <span class="about-toggle-title">INDEXINDEXについて</span>
            </button>
            <div class="about-content" hidden>
                <p>
                    INDEXINDEXは、漫画雑誌の目次情報を整理して公開するデータベースです。雑誌号ごとの掲載作品、作者、掲載順、開始ページ、発売日などを確認できます。
                </p>

                <h3>収録している情報</h3>
                <ul>
                    <li>雑誌名、出版社、号数、発売日などの雑誌号情報</li>
                    <li>各号に掲載された作品名、作者名、掲載順、開始ページ</li>
                    <li>読切、新連載、最終回など、目次から確認できる掲載区分</li>
                </ul>

                <h3>使い方</h3>
                <p>
                    作品名・作者名・雑誌名から検索できるため、漫画雑誌の掲載情報を調べたいときや、過去号にどの作品が載っていたかを確認したいときに利用できます。作品連載順グラフでは、登録された掲載順データをもとに、作品の掲載位置の推移も確認できます。
                </p>
            </div>
        </section>

        <section class="top-search" aria-label="フリーワード検索">
            <form method="get" action="search.php" class="top-search-form">
                <input type="text" name="q" placeholder="作品名・作者名・雑誌名で検索">
                <button type="submit">検索</button>
            </form>
        </section>

        <section class="stats-section" aria-labelledby="collectionStatsTitle">
            <h2 id="collectionStatsTitle">現在の収録状況</h2>
            <div class="stats-grid">
                <?php foreach ($collection_stats as $stat): ?>
                    <?php if ($stat['value'] !== null): ?>
                        <div class="stats-card">
                            <div class="stats-label"><?= h($stat['label']) ?></div>
                            <div class="stats-value"><?= h(number_format((int)$stat['value'])) ?><?= h($stat['unit']) ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="guide-section" aria-labelledby="guideTitle">
            <h2 id="guideTitle">INDEXINDEXで調べられること</h2>
            <p>
                INDEXINDEXでは、漫画雑誌の目次から読み取れる情報を作品・人物・雑誌号ごとに整理しています。雑誌号単位の目次だけでなく、作品ごとの掲載履歴や関係者の情報もたどれるようにしています。
            </p>
            <ul class="guide-list">
                <li>雑誌号ごとの収録作</li>
                <li>掲載順</li>
                <li>ページ</li>
                <li>作者・原作者・作画などの種別</li>
                <li>雑誌の発売日・発行日</li>
                <li>作品ごとの掲載履歴</li>
                <li>作者ごとの掲載履歴</li>
            </ul>
        </section>

        <div class="menu-grid">
            <?php foreach ($menu_items as $item): ?>
                <a class="menu-card" href="<?= h($item['url']) ?>">
                    <div class="menu-title"><?= h($item['title']) ?></div>
                    <div class="menu-description"><?= h($item['description']) ?></div>
                    <?php if (!empty($item['counts'])): ?>
                        <?php $visible_counts = array_filter($item['counts'], function ($count_item) { return $count_item['value'] !== null; }); ?>
                        <?php if (!empty($visible_counts)): ?>
                            <div class="menu-counts">
                                <?php foreach ($visible_counts as $count_item): ?>
                                    <div>
                                        <?= h($count_item['label']) ?>：<?= h(number_format((int)$count_item['value'])) ?><?= h($count_item['unit']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

    </main>

    <div class="sidebar-area">
        <aside class="side-box" aria-label="アクセスランキング">
            <section class="side-section">
                <button type="button" class="side-box-title side-toggle" aria-expanded="false">アクセスランキング</button>
                <div class="side-toggle-content" hidden>

                <div class="side-subtitle">作品</div>
                <?php if (empty($sideAccessRankings['works'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideAccessRankings['works'] as $row): ?>
                        <a class="side-link" href="work.php?work_id=<?= h($row['id'] ?? '') ?>"><?= h($row['name'] ?? '') ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="side-subtitle">雑誌</div>
                <?php if (empty($sideAccessRankings['magazines'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideAccessRankings['magazines'] as $row): ?>
                        <a class="side-link" href="issues.php?magazine_id=<?= h($row['id'] ?? '') ?>"><?= h($row['name'] ?? '') ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="side-subtitle">人物</div>
                <?php if (empty($sideAccessRankings['creators'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideAccessRankings['creators'] as $row): ?>
                        <a class="side-link" href="creator.php?creator_id=<?= h($row['id'] ?? '') ?>"><?= h($row['name'] ?? '') ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </section>
        </aside>

        <aside class="side-box" aria-label="最近の更新">
            <section class="side-section">
                <button type="button" class="side-box-title side-toggle" aria-expanded="false">最近の更新</button>
                <div class="side-toggle-content" hidden>

                <div class="side-subtitle">雑誌号</div>
                <?php if (empty($sideRecentUpdates['issues'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideRecentUpdates['issues'] as $row): ?>
                        <?php
                            $issue_name = trim((string)($row['magazine_name'] ?? '') . ' ' . (string)($row['issue_label'] ?? ''));
                        ?>
                        <a class="side-link" href="issue.php?id=<?= h($row['id'] ?? '') ?>"><?= h($issue_name) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="side-subtitle">作品</div>
                <?php if (empty($sideRecentUpdates['works'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideRecentUpdates['works'] as $row): ?>
                        <a class="side-link" href="work.php?work_id=<?= h($row['id'] ?? '') ?>"><?= h($row['title'] ?? '') ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="side-subtitle">人物</div>
                <?php if (empty($sideRecentUpdates['creators'])): ?>
                    <div class="side-note">まだありません</div>
                <?php else: ?>
                    <?php foreach ($sideRecentUpdates['creators'] as $row): ?>
                        <a class="side-link" href="creator.php?creator_id=<?= h($row['id'] ?? '') ?>"><?= h($row['name'] ?? '') ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </section>
        </aside>

        <?php include __DIR__ . '/includes/sidebar.php'; ?>
    </div>

    <div class="footer">
        <a href="index.php">トップページ</a> |
        <a href="about.php">INDEXINDEXについて</a> |
        <a href="how_to_use.php">使い方</a> |
        <a href="data_policy.php">データ登録方針</a> |
        <a href="terms.php">用語説明</a> |
        <a href="privacy.php">プライバシーポリシー</a> |
        <a href="contact.php">お問い合わせ</a>
        <br>
        INDEXINDEX
    </div>

</div>

<script>
document.querySelectorAll('.side-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const content = button.nextElementSibling;
        const isOpen = button.getAttribute('aria-expanded') === 'true';

        button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

        if (content) {
            content.hidden = isOpen;
        }
    });
});
</script>

</body>
</html>
