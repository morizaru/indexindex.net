<?php
// admin/creator_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

$name = '';
$name_kana = '';
$name_search = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $name_kana = trim($_POST['name_kana'] ?? '');
    $name_search = trim($_POST['name_search'] ?? '');

    if ($name === '') {
        $error = '人物名を入力してください。';
    } elseif (mb_strlen($name_search, 'UTF-8') > 255) {
        $error = '検索用表記ゆれは255文字以内で入力してください。';
    } else {
        try {
            // 重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM creators
                WHERE name = :name
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = 'この人物はすでに登録されています。';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO creators (
                        name,
                        name_kana,
                        name_search
                    ) VALUES (
                        :name,
                        :name_kana,
                        :name_search
                    )
                ");

                $stmt->execute([
                    ':name' => $name,
                    ':name_kana' => ($name_kana !== '') ? $name_kana : null,
                    ':name_search' => ($name_search !== '') ? $name_search : null,
                ]);

                $new_id = (int)$pdo->lastInsertId();

                $message = '人物を登録しました。ID：' . $new_id;

                $name = '';
                $name_kana = '';
                $name_search = '';
            }
        } catch (Exception $e) {
            $error = '登録エラー：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>人物登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="container">

    <h1>人物登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="creators.php">人物一覧</a>
        <a href="content_add.php">目次登録</a>
        <a href="../creators.php">公開：人物一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="creators.php">人物一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="creator_add.php">

        <div class="form-row">
            <label>
                人物名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：ちばてつや">
        </div>

        <div class="form-row">
            <label>人物名かな</label>
            <input type="text" name="name_kana" value="<?= h($name_kana) ?>" placeholder="例：ちばてつや / チバテツヤ">
            <div class="help">任意。検索・並び替え用に使えます。</div>
        </div>

        <div class="form-row">
            <label>検索用表記ゆれ</label>
            <input type="text" name="name_search" value="<?= h($name_search) ?>" placeholder="例：髙橋よしひろ / 渡邉まさこ 渡邊まさこ">
            <div class="help">任意。表示名とは別に、検索で拾いたい表記をスペース区切りで入力します。</div>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
