<?php
// admin/publisher_edit.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$publisher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publisher_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($publisher_id <= 0) {
    exit('出版社IDが指定されていません。');
}

$message = '';
$error = '';

// 現在データ取得
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        created_at,
        updated_at
    FROM publishers
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $publisher_id
]);
$publisher = $stmt->fetch();

if (!$publisher) {
    exit('指定された出版社が見つかりません。');
}

$name = $publisher['name'];

// 更新処理
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
                  AND id <> :id
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name,
                ':id' => $publisher_id
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = '同じ名前の出版社がすでに登録されています。';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE publishers
                    SET name = :name
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':id' => $publisher_id
                ]);

                $message = '出版社を更新しました。';

                // 再取得
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        name,
                        created_at,
                        updated_at
                    FROM publishers
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':id' => $publisher_id
                ]);
                $publisher = $stmt->fetch();
                $name = $publisher['name'];
            }
        } catch (Exception $e) {
            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 使用中雑誌一覧
$stmt = $pdo->prepare("
    SELECT
        id,
        name
    FROM magazines
    WHERE publisher_id = :publisher_id
    ORDER BY name ASC
");
$stmt->execute([
    ':publisher_id' => $publisher_id
]);
$magazines = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>出版社編集 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 850px;
        }

        h2 {
            margin-top: 28px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .info {
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-radius: 4px;
            line-height: 1.7;
            color: #555;
        }

        ul {
            line-height: 1.9;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .empty {
            color: #666;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>出版社編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="publishers.php">出版社一覧</a>
        <a href="publisher_add.php">出版社登録</a>
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

    <div class="info">
        ID：<?= h($publisher['id']) ?><br>
        作成日時：<?= h($publisher['created_at']) ?><br>
        更新日時：<?= h($publisher['updated_at']) ?>
    </div>

    <form method="post" action="publisher_edit.php?id=<?= h($publisher_id) ?>">

        <input type="hidden" name="id" value="<?= h($publisher_id) ?>">

        <div class="form-row">
            <label>
                出版社名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：講談社">
        </div>

        <button type="submit">更新する</button>

    </form>

    <h2>この出版社の雑誌</h2>

    <?php if (empty($magazines)): ?>

        <p class="empty">この出版社に紐づく雑誌はありません。</p>

    <?php else: ?>

        <ul>
            <?php foreach ($magazines as $magazine): ?>
                <li>
                    <?= h($magazine['name']) ?>
                    <a href="issues.php">雑誌一覧</a>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

</div>
</body>
</html>
