<?php
// how_to_use.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('INDEXINDEXの使い方');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$page_title = 'INDEXINDEXの使い方';
$page_description = '漫画雑誌目次データベース INDEXINDEX の使い方。雑誌号、作品名、作者・関係者、掲載順グラフから目的の情報を探す方法と検索のコツを説明します。';
$canonical_url = 'https://morizaru.net/indexindex/how_to_use.php';
$current_page = 'how_to_use';
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
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 700;
            margin: 0 0 18px;
            font-size: 28px;
            line-height: 1.35;
        }

        h2 {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 600;
            margin: 32px 0 12px;
            padding-left: 10px;
            border-left: 5px solid #333;
            font-size: 19px;
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

        a {
            color: #0066cc;
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
                font-size: 17px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <article class="box">
        <h1>INDEXINDEXの使い方</h1>

        <p>
            INDEXINDEXでは、漫画雑誌の目次情報を「雑誌号」「作品」「作者・関係者」の三つの入口から探せます。探したいものがはっきりしている場合は検索、掲載当時の並びを見たい場合は雑誌号、作品の長期的な掲載状況を見たい場合は作品ページや掲載順グラフを使うと、目的の情報にたどり着きやすくなります。
        </p>

        <h2>雑誌号から探す方法</h2>
        <p>
            雑誌一覧では、登録済みの雑誌を出版社や発行年で絞り込めます。雑誌名を選ぶと号の一覧が表示され、各号のページでは、その号に掲載された作品、作者・関係者、掲載順、開始ページを確認できます。特定の年月や号数を調べたいとき、または当時の誌面全体の流れを見たいときは、雑誌号から入るのが便利です。
        </p>

        <h2>作品名から探す方法</h2>
        <p>
            作品一覧では、作品名、区分、頭文字、発行年で絞り込めます。作品ページを開くと、その作品が登録済みのどの雑誌号に掲載されているかを一覧できます。連載作品の場合は掲載回の推移、読切の場合は掲載された号、同名作品や副題がある場合は関連する表記も確認できます。表記があいまいな場合は、作品名の一部だけで検索してみてください。
        </p>

        <h2>作者・関係者から探す方法</h2>
        <p>
            人物一覧や詳細検索では、作者名、原作者名、作画担当者名などから関連する掲載情報を探せます。漫画雑誌の目次では、同じ人物でも名義、読み、共同名義、役割表記が変わることがあります。人物ページでは、登録されている範囲で関係作品をまとめて確認できるため、特定の作者の活動時期や複数作品への参加状況を調べる入口になります。
        </p>

        <h2>掲載順グラフの使い方</h2>
        <p>
            掲載順グラフでは、作品ごとの掲載順を年別または号ごとに視覚的に確認できます。上にあるほど誌面の前方、下にあるほど後方に近い位置として見ると、掲載位置の変化を大まかに追えます。ただし、増刊、特別編成、目次の扱い、ページ構成の違いによって単純比較しにくい場合もあります。グラフは順位の傾向を見る補助として使い、詳細は各号の目次ページで確認してください。
        </p>

        <h2>検索のコツ</h2>
        <ul>
            <li>正式名称が不明な場合は、作品名や作者名の一部だけを入力してください。</li>
            <li>旧字体、別名義、読みの違いがありそうな場合は、短い語で検索すると見つけやすくなります。</li>
            <li>雑誌名と年を組み合わせると、目的の号に近い範囲まで絞り込めます。</li>
            <li>読切や出張掲載は、作品ページだけでなく雑誌号ページから確認すると見落としを減らせます。</li>
        </ul>

        <p>
            迷ったときは、まず <a href="search.php">詳細検索</a> で広めに探し、見つかった作品ページ、人物ページ、雑誌号ページを行き来するのがおすすめです。登録内容に誤りや補足がある場合は、<a href="contact.php">お問い合わせ</a> からお知らせください。
        </p>
    </article>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
