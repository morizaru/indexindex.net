<?php
// admin/magazine_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nullable_text($value) {
    $value = trim((string)$value);
    return ($value !== '') ? $value : null;
}

$message = '';
$error = '';

$publisher_id = isset($_GET['publisher_id']) ? (int)$_GET['publisher_id'] : 0;
$name = '';
$name_search = '';
$name_romaji = '';
$publication_frequency = '';
$note = '';
$publication_frequency_options = ['', '週刊', '月刊', '隔週刊', '隔月刊', '月2回刊', '季刊', '日刊', '不定期刊'];

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
    $name_search = trim($_POST['name_search'] ?? '');
    $name_romaji = trim($_POST['name_romaji'] ?? '');
    $publication_frequency = trim($_POST['publication_frequency'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if (!in_array($publication_frequency, $publication_frequency_options, true)) {
        $publication_frequency = '';
    }

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
                            name,
                            name_search,
                            name_romaji,
                            publication_frequency,
                            note
                        ) VALUES (
                            :publisher_id,
                            :name,
                            :name_search,
                            :name_romaji,
                            :publication_frequency,
                            :note
                        )
                    ");
                    $stmt->execute([
                        ':publisher_id' => $publisher_id,
                        ':name' => $name,
                        ':name_search' => nullable_text($name_search),
                        ':name_romaji' => nullable_text($name_romaji),
                        ':publication_frequency' => nullable_text($publication_frequency),
                        ':note' => nullable_text($note)
                    ]);

                    $new_id = (int)$pdo->lastInsertId();

                    header('Location: magazine_edit.php?id=' . $new_id . '&saved=1');
                    exit;
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

        <div class="form-row">
            <label>検索用名称</label>
            <input type="text" name="name_search" value="<?= h($name_search) ?>">
        </div>

        <div class="form-row">
            <label>ローマ字</label>
            <input type="text" name="name_romaji" value="<?= h($name_romaji) ?>">
        </div>

        <div class="form-row">
            <label>刊行頻度</label>
            <select name="publication_frequency">
                <option value="">未選択</option>
                <?php foreach (array_slice($publication_frequency_options, 1) as $option): ?>
                    <option value="<?= h($option) ?>" <?= ($publication_frequency === $option) ? 'selected' : '' ?>>
                        <?= h($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note"><?= h($note) ?></textarea>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
