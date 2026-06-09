<?php
// about.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('運営者情報');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name = 'INDEXINDEX';
$site_url  = 'https://morizaru.net/indexindex/';
$contact_url = 'contact.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>運営者情報 - <?= h($site_name) ?></title>

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
            font-size: 28px;
            margin-top: 0;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 20px;
            margin-top: 32px;
            margin-bottom: 12px;
            border-left: 5px solid #333;
            padding-left: 10px;
        }

        p {
            margin: 0 0 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            background: #fff;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 12px 14px;
            text-align: left;
            vertical-align: top;
        }

        th {
            width: 180px;
            background: #f8f8f8;
            font-weight: bold;
        }

        a {
            color: #0066cc;
        }

        .note {
            color: #666;
            font-size: 14px;
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
                font-size: 18px;
            }

            table,
            tbody,
            tr,
            th,
            td {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }

            th {
                border-bottom: none;
            }

            td {
                border-top: none;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="box">
        <h1>運営者情報</h1>

        <p>
            <?= h($site_name) ?>は、漫画雑誌の目次・掲載順・人物・作品情報を整理し、
            閲覧しやすくすることを目的としたデータベースサイトです。
        </p>

        <h2>サイト情報</h2>

        <table>
            <tr>
                <th>サイト名</th>
                <td><?= h($site_name) ?></td>
            </tr>
            <tr>
                <th>URL</th>
                <td>
                    <a href="<?= h($site_url) ?>">
                        <?= h($site_url) ?>
                    </a>
                </td>
            </tr>
            <tr>
                <th>運営者</th>
                <td><?= h($site_name) ?>運営</td>
            </tr>
            <tr>
                <th>サイト内容</th>
                <td>
                    漫画雑誌の目次、掲載順、作品情報、人物情報などを整理・公開するデータベースサイトです。
                </td>
            </tr>
            <tr>
                <th>お問い合わせ</th>
                <td>
                    <a href="<?= h($contact_url) ?>">お問い合わせページ</a>よりお願いいたします。
                </td>
            </tr>
        </table>

        <h2>掲載内容について</h2>

        <p>
            当サイトでは、漫画雑誌の目次情報、作品情報、人物情報などを整理して掲載しています。
            掲載内容については、できる限り正確な情報となるよう努めていますが、
            正確性・完全性を保証するものではありません。
        </p>

        <p>
            内容に誤りや修正希望がある場合は、お問い合わせページよりご連絡ください。
            確認のうえ、必要に応じて修正いたします。
        </p>

        <h2>著作権について</h2>

        <p>
            当サイトに掲載している文章、画像、データ等の著作権は、
            当サイトまたは各権利所有者に帰属します。
        </p>

        <p>
            著作権法上認められている場合を除き、
            無断転載・無断利用を禁止します。
        </p>

        <h2>免責事項</h2>

        <p>
            当サイトの情報を利用することで生じた損害等について、
            当サイトでは責任を負いかねます。
        </p>

        <p>
            また、当サイトからリンクしている外部サイトの内容についても、
            当サイトでは責任を負いかねます。
        </p>

        <h2>関連ページ</h2>

        <p>
            <a href="privacy.php">プライバシーポリシー</a><br>
            <a href="contact.php">お問い合わせ</a>
        </p>

        <p class="note">
            最終更新日：2026年5月8日
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