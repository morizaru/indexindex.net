<?php
// admin/episode_format_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function episode_format_column_exists($pdo, $column_name) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'episode_formats'
          AND column_name = :column_name
    ");
    $stmt->execute([
        ':column_name' => $column_name
    ]);
    $row = $stmt->fetch();

    return ((int)($row['cnt'] ?? 0) > 0);
}

function normalize_number_style($value) {
    $value = (string)$value;
    $allowed = ['', 'roman', 'kanji', 'old_kanji'];

    return in_array($value, $allowed, true) ? $value : '';
}

$message = '';
$error = '';
$has_number_style = episode_format_column_exists($pdo, 'number_style');

$name = '';
$prefix = '';
$suffix = '';
$number_style = '';
$note = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $number_style = normalize_number_style($_POST['number_style'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($name === '') {
        $error = '表示名を入力してください。';
    } else {
        try {
            // 重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM episode_formats
                WHERE name = :name
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = '同じ表示名の話数フォーマットがすでに登録されています。';
            } else {
                $number_style_column = $has_number_style ? ",\n                        number_style" : '';
                $number_style_value = $has_number_style ? ",\n                        :number_style" : '';
                $sql_insert = "
                    INSERT INTO episode_formats (
                        name,
                        prefix,
                        suffix,
                        note{$number_style_column}
                    ) VALUES (
                        :name,
                        :prefix,
                        :suffix,
                        :note{$number_style_value}
                    )
                ";

                $stmt = $pdo->prepare($sql_insert);
                $params = [
                    ':name' => $name,
                    ':prefix' => ($prefix !== '') ? $prefix : null,
                    ':suffix' => ($suffix !== '') ? $suffix : null,
                    ':note' => ($note !== '') ? $note : null,
                ];

                if ($has_number_style) {
                    $params[':number_style'] = ($number_style !== '') ? $number_style : null;
                }

                $stmt->execute($params);

                $new_id = (int)$pdo->lastInsertId();

                $message = '話数フォーマットを登録しました。ID：' . $new_id;

                $name = '';
                $prefix = '';
                $suffix = '';
                $number_style = '';
                $note = '';
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
    <title>話数フォーマット登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 850px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .preview {
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 16px;
            line-height: 1.7;
        }

        @media (max-width: 700px) {
            .form-grid {
                display: block;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>話数フォーマット登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="episode_formats.php">話数フォーマット一覧</a>
        <a href="content_add.php">目次登録</a>
        <a href="content_list.php">目次一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="episode_formats.php">話数フォーマット一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="preview">
        例：<br>
        表示名：第◯話 / prefix：第 / suffix：話 → 第1話<br>
        表示名：ROUND◯ / prefix：ROUND / suffix：空欄 → ROUND3
    </div>

    <form method="post" action="episode_format_add.php">

        <div class="form-row">
            <label>
                表示名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：第◯話">
            <div class="help">管理画面で選ぶ時の名前です。数字部分は「◯」で表すのがおすすめです。</div>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>prefix</label>
                <input type="text" name="prefix" value="<?= h($prefix) ?>" placeholder="例：第 / ROUND / No.">
                <div class="help">話数の前につける文字です。</div>
            </div>

            <div class="form-row">
                <label>suffix</label>
                <input type="text" name="suffix" value="<?= h($suffix) ?>" placeholder="例：話 / 回">
                <div class="help">話数の後ろにつける文字です。</div>
            </div>
        </div>

        <div class="form-row">
            <label>番号表示形式</label>
            <select name="number_style">
                <option value="" <?= ($number_style === '') ? 'selected' : '' ?>>アラビア数字（1,2,3...）</option>
                <option value="roman" <?= ($number_style === 'roman') ? 'selected' : '' ?>>ローマ数字（Ⅰ,Ⅱ,Ⅲ...）</option>
                <option value="kanji" <?= ($number_style === 'kanji') ? 'selected' : '' ?>>漢数字（一,二,三...）</option>
                <option value="old_kanji" <?= ($number_style === 'old_kanji') ? 'selected' : '' ?>>旧漢数字（壱,弐,参...）</option>
            </select>
            <?php if (!$has_number_style): ?>
                <div class="help">
                    number_style カラムが無いため、この項目はまだ保存されません。必要なSQL:
                    <code>ALTER TABLE episode_formats ADD COLUMN number_style VARCHAR(20) NULL DEFAULT NULL AFTER format_text;</code>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：通常の話数表示に使用"><?= h($note) ?></textarea>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
