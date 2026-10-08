<?php
// contact.php

session_start();

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('お問い合わせ');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$site_name  = 'INDEXINDEX';
$site_url   = 'https://indexindex.net/';

// 受信先：自分のメインメール
$to_email   = 'morizaru@gmail.com';

// 送信元：サイト用メールアドレス
// ※できればサーバー側で info@morizaru.com を作成しておく
$from_email = 'info@morizaru.com';

$errors = [];
$success = false;

if (empty($_SESSION['contact_token'])) {
    $_SESSION['contact_token'] = bin2hex(random_bytes(32));
}

$name    = '';
$email   = '';
$subject = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';

    if (!hash_equals($_SESSION['contact_token'], $token)) {
        $errors[] = '不正な送信です。もう一度お試しください。';
    }

    $name    = trim((string)($_POST['name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($name === '') {
        $errors[] = 'お名前を入力してください。';
    }

    if ($email === '') {
        $errors[] = 'メールアドレスを入力してください。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が正しくありません。';
    }

    if ($subject === '') {
        $errors[] = '件名を入力してください。';
    }

    if ($message === '') {
        $errors[] = 'お問い合わせ内容を入力してください。';
    }

    if (mb_strlen($name) > 100) {
        $errors[] = 'お名前は100文字以内で入力してください。';
    }

    if (mb_strlen($email) > 255) {
        $errors[] = 'メールアドレスは255文字以内で入力してください。';
    }

    if (mb_strlen($subject) > 100) {
        $errors[] = '件名は100文字以内で入力してください。';
    }

    if (mb_strlen($message) > 3000) {
        $errors[] = 'お問い合わせ内容は3000文字以内で入力してください。';
    }

    // 簡易スパム対策：通常の利用者には見えない項目
    $homepage = trim((string)($_POST['homepage'] ?? ''));
    if ($homepage !== '') {
        $errors[] = '送信できませんでした。';
    }

    if (empty($errors)) {
        $mail_subject = '【INDEXINDEX お問い合わせ】' . $subject;

        $mail_body = "";
        $mail_body .= "INDEXINDEXにお問い合わせがありました。\n\n";
        $mail_body .= "----------------------------------------\n";
        $mail_body .= "お名前\n";
        $mail_body .= $name . "\n\n";
        $mail_body .= "メールアドレス\n";
        $mail_body .= $email . "\n\n";
        $mail_body .= "件名\n";
        $mail_body .= $subject . "\n\n";
        $mail_body .= "お問い合わせ内容\n";
        $mail_body .= $message . "\n";
        $mail_body .= "----------------------------------------\n\n";
        $mail_body .= "送信元URL: " . $site_url . "contact.php\n";
        $mail_body .= "送信日時: " . date('Y-m-d H:i:s') . "\n";

        $headers = [];
        $headers[] = 'From: INDEXINDEX <' . $from_email . '>';
        $headers[] = 'Reply-To: ' . $email;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=ISO-2022-JP';
        $headers[] = 'Content-Transfer-Encoding: 7bit';

        $result = mb_send_mail(
            $to_email,
            $mail_subject,
            $mail_body,
            implode("\r\n", $headers),
            '-f ' . $from_email
        );

        if ($result) {
            $success = true;

            $_SESSION['contact_token'] = bin2hex(random_bytes(32));

            $name    = '';
            $email   = '';
            $subject = '';
            $message = '';
        } else {
            $errors[] = '送信に失敗しました。時間をおいて再度お試しください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php require_once __DIR__ . '/includes/public_url.php'; ?>
    <link rel="canonical" href="<?= htmlspecialchars(ii_public_canonical('contact.php'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="UTF-8">
    <title>お問い合わせ - <?= h($site_name) ?></title>

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

        label {
            display: block;
            font-weight: bold;
            margin-top: 18px;
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            font-family: inherit;
            background: #fff;
        }

        textarea {
            min-height: 180px;
            resize: vertical;
        }

        .required {
            color: #c00;
            font-size: 13px;
            margin-left: 4px;
        }

        .button-area {
            margin-top: 24px;
        }

        button {
            border: none;
            background: #222;
            color: #fff;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #444;
        }

        .errors {
            background: #fff0f0;
            border: 1px solid #e0a0a0;
            color: #900;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .success {
            background: #f0fff3;
            border: 1px solid #9ad0a4;
            color: #075c18;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .note {
            color: #666;
            font-size: 14px;
        }

        .hidden-field {
            display: none;
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

            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="box">
        <h1>お問い合わせ</h1>

        <p>
            <?= h($site_name) ?>へのお問い合わせは、下記フォームよりお願いいたします。
        </p>

        <?php if ($success): ?>
            <div class="success">
                お問い合わせを送信しました。内容を確認のうえ、必要に応じて返信いたします。
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>入力内容をご確認ください。</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="contact.php">
            <input type="hidden" name="token" value="<?= h($_SESSION['contact_token']) ?>">

            <div class="hidden-field">
                <label for="homepage">ホームページ</label>
                <input type="text" id="homepage" name="homepage" value="">
            </div>

            <label for="name">
                お名前<span class="required">必須</span>
            </label>
            <input
                type="text"
                id="name"
                name="name"
                value="<?= h($name) ?>"
                maxlength="100"
                required
            >

            <label for="email">
                メールアドレス<span class="required">必須</span>
            </label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= h($email) ?>"
                maxlength="255"
                required
            >

            <label for="subject">
                件名<span class="required">必須</span>
            </label>
            <input
                type="text"
                id="subject"
                name="subject"
                value="<?= h($subject) ?>"
                maxlength="100"
                required
            >

            <label for="message">
                お問い合わせ内容<span class="required">必須</span>
            </label>
            <textarea
                id="message"
                name="message"
                maxlength="3000"
                required
            ><?= h($message) ?></textarea>

            <p class="note">
                不具合や掲載内容についてのお問い合わせの場合は、該当ページのURL、雑誌名、作品名、人物名などを記載いただけると確認しやすくなります。
            </p>

            <div class="button-area">
                <button type="submit">送信する</button>
            </div>
        </form>

        <h2>サイト情報</h2>

        <p>
            サイト名：<?= h($site_name) ?><br>
            URL：<?= h($site_url) ?><br>
            ご連絡はこのページのお問い合わせフォームよりお願いいたします。
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
