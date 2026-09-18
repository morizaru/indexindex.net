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
        $magazineCount = (int)$pdo->query("SELECT COUNT(*) FROM magazines")->fetchColumn();
        $issueCount = (int)$pdo->query("SELECT COUNT(*) FROM magazine_issues")->fetchColumn();
        $workCount = (int)$pdo->query("SELECT COUNT(*) FROM works")->fetchColumn();
        $creatorCount = (int)$pdo->query("SELECT COUNT(*) FROM creators")->fetchColumn();
        $contentCount = (int)$pdo->query("SELECT COUNT(*) FROM issue_contents")->fetchColumn();
    }
} catch (Throwable $e) {
    $magazineCount = null;
    $issueCount = null;
    $workCount = null;
    $creatorCount = null;
    $contentCount = null;
}

// 軽量なサイドコンテンツを追加するときだけ有効にする。
$show_sidebar = false;

$collection_stats = [
    ['label' => '登録雑誌数', 'value' => $magazineCount, 'unit' => '誌'],
    ['label' => '登録雑誌号数', 'value' => $issueCount, 'unit' => '号'],
    ['label' => '登録作品数', 'value' => $workCount, 'unit' => '件'],
    ['label' => '登録人物数', 'value' => $creatorCount, 'unit' => '人'],
    ['label' => '登録目次件数', 'value' => $contentCount, 'unit' => '件'],
];

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
        'description' => '作品の掲載順をグラフで表示します。',
        'url' => 'work_graph.php',
    ],
    [
        'title' => 'メディアミックス',
        'description' => 'アニメ化・映画化・ドラマ化などメディアミックスの作品の一覧を表示します。',
        'url' => 'mediamix.php',
    ],
    [
        'title' => 'アクセスランキング',
        'description' => '作品・雑誌・人物のアクセスランキングを表示します。',
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

        /* サイド領域を使わない間はメインで全幅を使う。 */
        .page-shell {
            max-width: 1358px;
            margin: 0 auto;
            padding: 24px;
            box-sizing: border-box;
        }

        .page-shell.has-sidebar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 240px;
            column-gap: 18px;
        }

        .container {
            width: 100%;
            min-width: 0;
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

        .menu-card-about .menu-title {
            font-size: 18px;
        }

        .menu-card-info .menu-title {
            background: #555;
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

        /* 将来の軽量コンテンツ用。現在はHTMLも出力しない。 */
        .sidebar-area {
            min-width: 0;
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

        .side-ranking {
            margin: 0;
            padding-left: 1.4em;
        }

        .side-ranking li {
            margin-bottom: 8px;
        }

        .side-note {
            margin-top: 10px;
            color: #888;
            font-size: 12px;
            line-height: 1.5;
        }

        .footer {
            width: 100%;
            grid-column: 1 / -1;
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

        @media (max-width: 1400px) {
            .page-shell.has-sidebar {
                grid-template-columns: minmax(0, 1fr);
            }

            .sidebar-area {
                margin-top: 18px;
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

            .menu-card-about .menu-title {
                font-size: 17px;
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

<div class="page-shell<?= $show_sidebar ? ' has-sidebar' : '' ?>">

    <main class="container">

        <div class="hero">
            <img src="assets/images/main_title.png" alt="INDEXINDEX">

            <div class="subtitle">
                雑誌の目次・掲載順・人物・作品情報を閲覧するためのデータベースです。
            </div>
        </div>

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
                <li>雑誌号ごとの収録作品</li>
                <li>収録作品の掲載順</li>
                <li>収録作品の開始ページ</li>
                <li>作者の作画・原作などの種別</li>
                <li>雑誌の発売日・発行日</li>
                <li>作品ごとの掲載履歴</li>
                <li>作者ごとの掲載履歴</li>
                <li>アニメ化・ドラマ化・映画化などの情報</li>
            </ul>
        </section>

        <div class="menu-grid">
            <?php foreach ($menu_items as $item): ?>
                <?php
                    $item_url = $item['url'] ?? '';
                    $info_urls = ['about.php', 'how_to_use.php', 'data_policy.php', 'terms.php'];
                    $menu_card_class = 'menu-card'
                        . ($item_url === 'about.php' ? ' menu-card-about' : '')
                        . (in_array($item_url, $info_urls, true) ? ' menu-card-info' : '');
                ?>
                <a class="<?= h($menu_card_class) ?>" href="<?= h($item['url']) ?>">
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

    <?php if ($show_sidebar): ?>
        <div class="sidebar-area">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>
        </div>
    <?php endif; ?>

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



</body>
</html>
