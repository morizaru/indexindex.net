<?php
// about.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('INDEXINDEXについて');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$page_title = 'INDEXINDEXについて';
$page_description = 'INDEXINDEXは漫画雑誌の目次、掲載順、開始ページ、作者・関係者情報を整理して公開する資料サイトです。作成目的、収録情報、調査に使える内容を説明します。';
$canonical_url = 'https://morizaru.net/indexindex/about.php';
$current_page = 'about';
$site_header_width = 'narrow';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?> - <?= h($site_name) ?></title>
    <meta name="description" content="<?= h($page_description) ?>">
    <link rel="canonical" href="<?= h($canonical_url) ?>">

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            background: #f5f5f5;
            color: #222;
            line-height: 1.8;
        }

        .container {
            max-width: 940px;
            margin: 0 auto;
            padding: 24px;
            box-sizing: border-box;
        }

        .box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 28px;
            box-sizing: border-box;
        }

        h1 {
            margin: 0 0 18px;
            font-size: 28px;
            line-height: 1.35;
        }

        h2 {
            margin: 32px 0 12px;
            padding-left: 10px;
            border-left: 5px solid #333;
            font-size: 20px;
            line-height: 1.45;
        }

        p {
            margin: 0 0 14px;
        }

        ul {
            margin: 0 0 14px;
            padding-left: 1.4em;
        }

        li {
            margin-bottom: 6px;
        }

        .link-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-top: 18px;
        }

        .link-list a {
            display: block;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            color: #222;
            text-decoration: none;
            background: #fafafa;
        }

        .link-list a:hover {
            border-color: #999;
            background: #f1f3f5;
        }

        @media (max-width: 700px) {
            .container {
                padding: 12px;
            }

            .box {
                padding: 18px;
                border-radius: 8px;
            }

            h1 {
                font-size: 24px;
            }

            h2 {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <article class="box">
        <h1>INDEXINDEXについて</h1>

        <p>
            INDEXINDEXは、漫画雑誌の目次情報を号ごとに整理し、作品名、作者・関係者名、掲載順、開始ページ、発売日などを横断して確認できるようにする資料サイトです。単にデータを並べるだけではなく、雑誌のどの号に何が掲載されていたのか、作品がどの時期にどのような位置で扱われていたのかを、あとから調べやすい形に整えることを目的に運営しています。
        </p>

        <h2>作っている目的</h2>
        <p>
            漫画雑誌は、作品単行本や電子書籍とは別に、その時代の編集方針、読切の登場、連載開始、最終回、出張掲載、企画ページなどを記録している一次的な手がかりです。しかし、過去の号の目次をまとめて確認できる場所は限られており、作品名だけでは探しにくい情報も少なくありません。INDEXINDEXでは、雑誌号を起点にした目次情報を蓄積し、漫画史、作品研究、作者の掲載歴確認、古書購入時の確認などに使える索引を目指しています。
        </p>

        <h2>収録している情報</h2>
        <p>
            主に、雑誌名、出版社、号数、発行年、発売日、掲載作品名、作者名、原作者名、作画担当者名、掲載順、開始ページ、連載・読切などの区分を扱います。目次上で確認できる表記を尊重しつつ、検索しやすいように作品ページ、人物ページ、雑誌号ページを相互に結びます。登録状況は号によって差があり、すべての項目が必ず揃っているとは限りませんが、確認できた情報から順に整理しています。
        </p>

        <h2>調査に使えること</h2>
        <p>
            雑誌号から当時の掲載作品を確認する、作品名から掲載された号を追う、作者や関係者から参加作品を探す、掲載順グラフで作品の位置の変化を見る、といった使い方ができます。たとえば、ある読切が後の連載とどう関係しているかを調べたり、特定の作者が複数の雑誌でどの時期に活動していたかを確認したり、古い雑誌を入手する前に目次の見当をつけたりできます。
        </p>

        <h2>掲載内容について</h2>
        <p>
            データは確認できる範囲で丁寧に登録していますが、雑誌や資料の状態、表記ゆれ、発売日と発行日の違いなどにより、誤記や未確認の項目が残る場合があります。修正依頼や補足情報は、お問い合わせページから該当ページのURL、雑誌名、号数、作品名などを添えてお知らせください。確認できた内容から順次反映します。
        </p>

        <div class="link-list" aria-label="関連ページ">
            <a href="how_to_use.php">使い方を見る</a>
            <a href="data_policy.php">データ登録方針を見る</a>
            <a href="terms.php">用語説明を見る</a>
            <a href="contact.php">修正依頼・お問い合わせ</a>
        </div>
    </article>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
