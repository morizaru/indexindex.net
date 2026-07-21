<?php
// data_policy.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('データ登録方針');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$page_title = 'データ登録方針';
$page_description = 'INDEXINDEXの漫画雑誌目次データ登録方針。雑誌号、作品名、作者名、掲載順、開始ページ、発売日、読切や新連載などの扱いを説明します。';
$canonical_url = 'https://morizaru.net/indexindex/data_policy.php';
$current_page = 'data_policy';
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
        }

        h2 {
            margin: 32px 0 12px;
            padding-left: 10px;
            border-left: 5px solid #333;
            font-size: 20px;
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
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <article class="box">
        <h1>データ登録方針</h1>

        <p>
            INDEXINDEXでは、漫画雑誌の目次を後から参照しやすくするため、確認できた情報を一定の方針に沿って登録しています。目次に掲載された文字情報を基本にしながら、検索性を高めるために作品、人物、雑誌号を関連付けます。資料サイトとしての使いやすさを重視していますが、原資料の状態や表記の違いにより、未確認項目や暫定的な登録が含まれる場合があります。
        </p>

        <h2>基本項目の扱い</h2>
        <dl>
            <dt>雑誌号</dt>
            <dd>雑誌名、出版社、年、号数、増刊・特別号などの補足を、確認できる範囲で登録します。号の表記は雑誌上の表記を優先し、検索しやすいよう発行年や発売日も併せて扱います。</dd>

            <dt>作品名</dt>
            <dd>目次に記載されたタイトルを基本とします。副題、シリーズ名、短編タイトルなどがある場合は、作品としてのまとまりと目次上の表記の両方を考慮して整理します。</dd>

            <dt>作者名・関係者名</dt>
            <dd>作者、原作、作画、脚本、構成、協力など、目次で確認できる役割を登録します。人物名は検索しやすいように関連付けますが、同姓同名や別名義の扱いは確認状況に応じて慎重に判断します。</dd>

            <dt>掲載順・開始ページ</dt>
            <dd>掲載順は目次に並んだ順番を基準にします。巻頭カラー、センターカラー、記事、企画ページなどが混在する場合、漫画作品として確認できる項目を中心に整理します。開始ページは目次上で確認できるページ番号を登録します。</dd>

            <dt>発売日・発行日</dt>
            <dd>発売日と発行日は一致しないことがあります。INDEXINDEXでは、確認できる場合は発売日を優先して参照できるようにし、発行年や号数表記も併せて記録します。</dd>
        </dl>

        <h2>掲載区分の扱い</h2>
        <p>
            読切、連載、出張掲載、新連載、最終回などの区分は、目次や誌面上の記載をもとに登録します。読切は単発掲載として扱いますが、後に連載化した作品やシリーズ読切の場合は、作品ページで関連する掲載履歴を確認できるようにします。出張掲載は、通常の掲載誌とは異なる雑誌に掲載されたものとして扱い、特別編や宣伝掲載など、判断が難しいものは確認できる表記を尊重します。
        </p>

        <h2>表記ゆれと名義</h2>
        <p>
            作品名や作者名には、旧字体、新字体、かな表記、英字表記、改名、共同名義、原作・作画の役割変更などの表記ゆれがあります。登録時は目次上の表記を残しつつ、同一人物または同一作品と確認できる範囲で関連付けます。確証が弱い場合は無理に統合せず、後から修正できるように扱います。
        </p>

        <h2>誤記や修正依頼</h2>
        <p>
            誤記、抜け、表記ゆれの統合、発売日の補足などにお気づきの場合は、<a href="contact.php">お問い合わせ</a> からご連絡ください。該当ページのURL、雑誌名、号数、作品名、人物名、確認できる資料名などを添えていただけると確認しやすくなります。いただいた情報は内容を確認したうえで、必要に応じて反映します。
        </p>
    </article>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
