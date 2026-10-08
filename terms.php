<?php
// terms.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('用語説明');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$page_title = '用語説明';
$page_description = 'INDEXINDEXで使っている漫画雑誌目次関連の用語説明。雑誌号、目次、掲載順、開始ページ、読切、出張掲載、新連載、最終回、作者、原作、作画などを解説します。';
$canonical_url = 'https://indexindex.net/terms.php';
$current_page = 'terms';
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

        dl {
            margin: 0;
        }

        dt {
            margin-top: 18px;
            font-weight: 700;
        }

        dd {
            margin: 4px 0 0;
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
        <h1>INDEXINDEXで使っている用語</h1>

        <p>
            INDEXINDEXでは、漫画雑誌の目次を整理するために、雑誌や掲載情報に関する用語を使っています。出版社や時代によって表記や意味が少しずつ異なる場合がありますが、このページではサイト内での基本的な考え方を説明します。詳しい登録方針は <a href="data_policy.php">データ登録方針</a> も確認してください。
        </p>

        <h2>雑誌・号に関する用語</h2>
        <dl>
            <dt>雑誌号</dt>
            <dd>特定の雑誌の特定の号を指します。例として、年、号数、増刊、特別号、合併号などで区別されます。INDEXINDEXでは、目次情報を確認する基本単位です。</dd>

            <dt>目次</dt>
            <dd>雑誌に掲載された作品や記事の一覧です。作品名、作者名、開始ページ、特集名などが並びます。INDEXINDEXでは、目次から読み取れる掲載作品を中心に整理しています。</dd>

            <dt>発売日</dt>
            <dd>雑誌が店頭や流通上で発売された日を指します。発行日や号数上の年月とは異なる場合があります。確認できない場合は、発行年や号数を手がかりとして扱います。</dd>
        </dl>

        <h2>掲載位置に関する用語</h2>
        <dl>
            <dt>掲載順</dt>
            <dd>目次に並んでいる順番、または誌面上で作品が掲載される順番を指します。INDEXINDEXの掲載順グラフでは、この順番をもとに作品の位置の変化を見られるようにしています。</dd>

            <dt>開始ページ</dt>
            <dd>その作品や項目が始まるページ番号です。目次にページ番号が記載されている場合に登録します。ページ番号の扱いは雑誌によって異なるため、確認できる範囲で整理しています。</dd>
        </dl>

        <h2>掲載区分に関する用語</h2>
        <dl>
            <dt>読切</dt>
            <dd>基本的に一回の掲載で完結する作品です。シリーズ読切や連載化前の読切など、後の作品履歴と関係する場合もあります。</dd>

            <dt>連載</dt>
            <dd>複数号にわたって継続掲載される作品です。毎号掲載、隔号掲載、不定期掲載など、掲載ペースは作品や雑誌によって異なります。</dd>

            <dt>出張掲載</dt>
            <dd>通常掲載されている雑誌とは別の雑誌や増刊に掲載されることを指します。宣伝、特別企画、コラボレーションなどの理由で行われる場合があります。</dd>

            <dt>新連載</dt>
            <dd>連載の初回として案内された掲載です。目次や誌面で「新連載」と記載されている場合、その情報を掲載区分として扱います。</dd>

            <dt>最終回</dt>
            <dd>連載作品の終了回として案内された掲載です。誌面上に最終回の表記がある場合に登録します。</dd>
        </dl>

        <h2>人物・役割に関する用語</h2>
        <dl>
            <dt>作者</dt>
            <dd>作品の制作に関わる人物を広く指します。単独名義の場合もあれば、原作、作画、脚本など複数名義に分かれる場合もあります。</dd>

            <dt>原作</dt>
            <dd>物語や設定の元になる内容を担当する役割です。漫画化作品や共同制作作品では、原作者と作画担当者が分かれて記載されることがあります。</dd>

            <dt>作画</dt>
            <dd>漫画の絵や画面制作を担当する役割です。原作付き作品では、原作名義と作画名義を分けて登録することがあります。</dd>
        </dl>
    </article>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
