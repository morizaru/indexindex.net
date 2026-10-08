<?php
// privacy.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('プライバシーポリシー');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$site_url  = 'https://indexindex.net/';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php require_once __DIR__ . '/includes/public_url.php'; ?>
    <link rel="canonical" href="<?= htmlspecialchars(ii_public_canonical('privacy.php'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="UTF-8">
    <title>プライバシーポリシー - <?= h($site_name) ?></title>

    <script type="text/javascript" src="//code.typesquare.com/static/ZDbTe4IzCko%253D/ts106f.js" charset="utf-8"></script>

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
            max-width: 900px;
            margin: 0 auto;
            padding: 24px;
        }

        .box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 28px;
        }

        h1 {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 700;
            font-size: 28px;
            margin-top: 0;
            margin-bottom: 20px;
        }

        h2 {
            font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 600;
            font-size: 19px;
            margin-top: 32px;
            margin-bottom: 12px;
            border-left: 5px solid #333;
            padding-left: 10px;
        }

        p {
            margin: 0 0 14px;
        }

        ul {
            margin-top: 0;
        }

        a {
            color: #0066cc;
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

<div class="container">

    <div class="box">
        <h1>プライバシーポリシー</h1>

        <p>
            <?= h($site_name) ?>（以下、「当サイト」といいます。）では、
            利用者の個人情報の保護に配慮し、以下のとおりプライバシーポリシーを定めます。
        </p>

        <h2>個人情報の利用目的</h2>
        <p>
            当サイトでは、お問い合わせ等の際に、名前、メールアドレス等の個人情報を入力いただく場合があります。
            取得した個人情報は、お問い合わせへの回答や必要な連絡のために利用し、
            これらの目的以外では利用いたしません。
        </p>

        <h2>広告について</h2>
        <p>
            当サイトでは、第三者配信の広告サービス「Google AdSense」を利用する場合があります。
        </p>
        <p>
            Googleなどの第三者広告配信事業者は、利用者の興味に応じた広告を表示するために
            Cookieを使用することがあります。
        </p>
        <p>
            Cookieを使用することで、当サイトは利用者のコンピュータを識別できるようになりますが、
            氏名、住所、メールアドレス、電話番号などを特定するものではありません。
        </p>
        <p>
            Googleによる広告でのCookieの使用を無効にする方法については、
            Googleの広告設定をご確認ください。
        </p>

        <h2>アクセス解析について</h2>
        <p>
            当サイトでは、アクセス状況の把握やサイト改善のために、アクセス解析ツールを利用する場合があります。
        </p>
        <p>
            アクセス解析ツールではCookieを使用する場合がありますが、
            収集される情報は匿名であり、個人を特定するものではありません。
        </p>

        <h2>Cookieについて</h2>
        <p>
            当サイトでは、広告配信、アクセス解析、利便性向上のためにCookieを使用する場合があります。
        </p>
        <p>
            利用者はブラウザの設定により、Cookieを無効にすることができます。
            ただし、Cookieを無効にした場合、一部の機能が正常に利用できない場合があります。
        </p>

        <h2>免責事項</h2>
        <p>
            当サイトに掲載している情報については、できる限り正確な内容を掲載するよう努めていますが、
            正確性、完全性、安全性を保証するものではありません。
        </p>
        <p>
            当サイトの情報を利用することで生じた損害等について、当サイトでは責任を負いかねます。
        </p>

        <h2>著作権について</h2>
        <p>
            当サイトに掲載している文章、画像、データ等の著作権は、
            当サイトまたは各権利所有者に帰属します。
        </p>
        <p>
            著作権法上認められている場合を除き、無断転載・無断利用を禁止します。
        </p>

        <h2>リンクについて</h2>
        <p>
            当サイトは、原則としてリンク自由です。
            ただし、画像への直リンクや、公序良俗に反するサイトからのリンクはご遠慮ください。
        </p>

        <h2>プライバシーポリシーの変更について</h2>
        <p>
            当サイトは、必要に応じて本プライバシーポリシーの内容を変更することがあります。
            変更後の内容は、当ページに掲載した時点で有効になるものとします。
        </p>

        <h2>運営者情報</h2>
        <p>
            サイト名：<?= h($site_name) ?><br>
            URL：<?= h($site_url) ?><br>
            運営者：<?= h($site_name) ?>運営
        </p>

        <h2>お問い合わせ</h2>
        <p>
            当サイトに関するお問い合わせは、以下のページよりお願いいたします。
        </p>
        <p>
            <a href="contact.php">お問い合わせページ</a>
        </p>

        <p>
            制定日：2026年5月1日
        </p>
    </div>

    <div class="footer">
        <a href="index.php">トップページ</a> |
        <a href="privacy.php">プライバシーポリシー</a> |
        <a href="contact.php">お問い合わせ</a> |
        <a href="about.php">運営者情報</a>
        <br>
        INDEXINDEX
    </div>

</div>

</body>
</html>
