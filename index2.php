<?php
// index2.php

header('Content-Type: text/html; charset=UTF-8');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

        .page-layout {
            max-width: 1320px;
            margin: 0 auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: minmax(0, 1100px) 220px;
            gap: 18px;
            align-items: start;
            box-sizing: border-box;
        }

        .container {
            max-width: 1100px;
            margin: 0;
            padding: 24px 0;
            box-sizing: border-box;
        }

        .hero {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            border: 1px solid #e0e0e0;
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
            margin-bottom: 8px;
            color: #222;
        }

        .menu-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .side-box {
            margin-top: 24px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 14px;
            font-size: 13px;
            line-height: 1.6;
            box-sizing: border-box;
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
            margin-top: 28px;
            text-align: center;
            color: #888;
            font-size: 13px;
            line-height: 1.8;
        }

        .footer a {
            color: #666;
            text-decoration: none;
            margin: 0 6px;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .page-layout {
                display: block;
                max-width: 1100px;
                padding: 0 12px;
            }

            .container {
                max-width: 1100px;
                margin: 0 auto;
                padding: 12px 0;
            }

            .side-box {
                margin: 18px 0 0;
            }
        }

        @media (max-width: 700px) {
            .hero {
                padding: 14px;
                border-radius: 8px;
            }

            .subtitle {
                font-size: 14px;
            }

            .menu-card {
                padding: 16px;
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

<div class="page-layout">

    <main class="container">

        <div class="hero">
            <img src="assets/images/main_title.png" alt="INDEXINDEX">

            <div class="subtitle">
                雑誌の目次・掲載順・人物・作品情報を閲覧するためのデータベースです。
            </div>
        </div>

        <div class="menu-grid">
            <?php foreach ($menu_items as $item): ?>
                <a class="menu-card" href="<?= h($item['url']) ?>">
                    <div class="menu-title"><?= h($item['title']) ?></div>
                    <div class="menu-description"><?= h($item['description']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="footer">
            <a href="index.php">トップページ</a> |
            <a href="privacy.php">プライバシーポリシー</a> |
            <a href="contact.php">お問い合わせ</a> |
            <a href="about.php">運営者情報</a>
            <br>
            INDEXINDEX
        </div>

    </main>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

</div>

</body>
</html>
