<?php
// admin/publisher_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

$name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $error = '出版社名を入力してください。';
    } else {
        try {
            // 重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM publishers
                WHERE name = :name
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = 'この出版社はすでに登録されています。';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO publishers (
                        name
                    ) VALUES (
                        :name
                    )
                ");

                $stmt->execute([
                    ':name' => $name
                ]);

                $new_id = (int)$pdo->lastInsertId();

                header('Location: publisher_edit.php?id=' . $new_id . '&saved=1');
                exit;
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
    <title>出版社登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="container">

    <h1>出版社登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="publishers.php">出版社一覧</a>
        <a href="issue_add.php">雑誌を登録する</a>
        <a href="../index.php">公開トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="publishers.php">出版社一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="publisher_add.php">

        <div class="form-row">
            <label>
                出版社名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：講談社">
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
