<?php
// admin/magazine_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

$publisher_id = isset($_GET['publisher_id']) ? (int)$_GET['publisher_id'] : 0;
$name = '';

// 出版社一覧
$stmt = $pdo->query("
    SELECT id, name
    FROM publishers
    ORDER BY name ASC
");
$publishers = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publisher_id = isset($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : 0;
    $name = trim($_POST['name'] ?? '');

    if ($publisher_id <= 0) {
        $error = '出版社を選択してください。';
    } elseif ($name === '') {
        $error = '雑誌名を入力してください。';
    } else {
        try {
            // 出版社存在チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM publishers
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $publisher_id
            ]);
            $publisher_exists = $stmt->fetch();

            if (!$publisher_exists) {
                $error = '選択した出版社が見つかりません。';
            } else {
                // 同じ出版社内で同じ雑誌名がないか確認
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM magazines
                    WHERE publisher_id = :publisher_id
                      AND name = :name
                    LIMIT 1
                ");
                $stmt->execute([
                    ':publisher_id' => $publisher_id,
                    ':name' => $name
                ]);
                $exists = $stmt->fetch();

                if ($exists) {
                    $error = '同じ出版社に同じ雑誌名がすでに登録されています。';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO magazines (
                            publisher_id,
                            name
                        ) VALUES (
                            :publisher_id,
                            :name
                        )
                    ");
                    $stmt->execute([
                        ':publisher_id' => $publisher_id,
                        ':name' => $name
                    ]);

                    $new_id = (int)$pdo->lastInsertId();

                    $message = '雑誌本体を登録しました。ID：' . $new_id;

                    $name = '';
                }
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
    <title>雑誌本体登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .container {
            max-width: 850px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>雑誌本体登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="magazines.php">雑誌本体一覧</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="publishers.php">出版社一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="magazines.php">雑誌本体一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="magazine_add.php">

        <div class="form-row">
            <label>
                出版社 <span class="required">必須</span>
            </label>
            <select name="publisher_id">
                <option value="">選択してください</option>
                <?php foreach ($publishers as $publisher): ?>
                    <option value="<?= h($publisher['id']) ?>" <?= ($publisher_id === (int)$publisher['id']) ? 'selected' : '' ?>>
                        <?= h($publisher['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="help">先に出版社が無い場合は、出版社登録から追加してください。</div>
        </div>

        <div class="form-row">
            <label>
                雑誌名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：COM">
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
