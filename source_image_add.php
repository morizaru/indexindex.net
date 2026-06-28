<?php
// source_image_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';
$issue_id = trim((string)($_GET['issue_id'] ?? ''));
$note = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '出典画像を登録しました。';
}

// 保存先
$upload_dir = __DIR__ . '/uploads/source_images/';
$upload_url_base = 'uploads/source_images/';

// 雑誌号一覧を取得
$sql_issues = "
    SELECT
        mi.id,
        mi.issue_label,
        mi.release_date,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE mi.status = 'approved'
    ORDER BY mi.release_date DESC, mi.id DESC
";
$stmt = $pdo->query($sql_issues);
$issues = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = trim($_POST['issue_id'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($issue_id === '') {
        $error = '雑誌号を選択してください。';
    } elseif (!isset($_FILES['source_image']) || $_FILES['source_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = '画像ファイルを選択してください。';
    } elseif ($_FILES['source_image']['error'] !== UPLOAD_ERR_OK) {
        $error = '画像アップロードでエラーが発生しました。';
    } else {
        $file = $_FILES['source_image'];

        // サイズ制限：5MB
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = '画像サイズは5MB以下にしてください。';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowed[$mime])) {
                $error = 'アップロードできる画像は JPG / PNG / WEBP のみです。';
            } else {
                try {
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $ext = $allowed[$mime];

                    $file_name = 'issue_' . (int)$issue_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $save_path = $upload_dir . $file_name;
                    $db_path = $upload_url_base . $file_name;

                    if (!move_uploaded_file($file['tmp_name'], $save_path)) {
                        throw new Exception('画像ファイルを保存できませんでした。');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO source_images (
                            issue_id,
                            image_path,
                            note
                        ) VALUES (
                            :issue_id,
                            :image_path,
                            :note
                        )
                    ");

                    $stmt->execute([
                        ':issue_id' => (int)$issue_id,
                        ':image_path' => $db_path,
                        ':note' => ($note !== '') ? $note : null,
                    ]);

                    header('Location: source_image_add.php?issue_id=' . (int)$issue_id . '&saved=1');
                    exit;
                } catch (Exception $e) {
                    $error = '登録エラー：' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>出典画像登録 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
        }

        .nav {
            margin-bottom: 20px;
        }

        .nav a {
            margin-right: 12px;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
        }

        .nav a:hover {
            text-decoration: underline;
        }

        .message {
            padding: 12px;
            background: #e8f5e9;
            border: 1px solid #81c784;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .error {
            padding: 12px;
            background: #ffebee;
            border: 1px solid #e57373;
            margin-bottom: 16px;
            border-radius: 4px;
            color: #b00020;
        }

        .form-row {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        select,
        input[type="file"],
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        textarea {
            min-height: 80px;
        }

        button {
            padding: 10px 20px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #111;
        }

        .required {
            color: #b00020;
            font-size: 13px;
        }

        .help {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
        }

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>出典画像登録</h1>

    <div class="nav">
        <a href="index.php">トップ</a>
        <a href="issues.php">雑誌一覧</a>
        <a href="works.php">作品一覧</a>
        <a href="creators.php">人物一覧</a>
        <a href="search.php">詳細検索</a>
    </div>


    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="source_image_add.php" enctype="multipart/form-data">

        <div class="form-row">
            <label>
                雑誌号 <span class="required">必須</span>
            </label>
            <select name="issue_id">
                <option value="">選択してください</option>
                <?php foreach ($issues as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ((string)$issue_id === (string)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['magazine_name']) ?>
                        <?= h($row['issue_label']) ?>
                        /
                        <?= h($row['publisher_name']) ?>
                        /
                        <?= h($row['release_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>
                出典画像 <span class="required">必須</span>
            </label>
            <input type="file" name="source_image" accept="image/jpeg,image/png,image/webp">
            <div class="help">JPG / PNG / WEBP、5MB以下</div>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：目次ページ"><?= h($note) ?></textarea>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
