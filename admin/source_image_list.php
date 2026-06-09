<?php
// admin/source_image_list.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;

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

$images = [];

if ($issue_id > 0) {
    $sql_images = "
        SELECT
            si.id,
            si.issue_id,
            si.image_path,
            si.note,
            si.created_at,
            mi.issue_label,
            mi.release_date,
            m.name AS magazine_name,
            p.name AS publisher_name
        FROM source_images si
        JOIN magazine_issues mi ON si.issue_id = mi.id
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE si.issue_id = :issue_id
        ORDER BY si.created_at DESC, si.id DESC
    ";

    $stmt = $pdo->prepare($sql_images);
    $stmt->execute([
        ':issue_id' => $issue_id
    ]);
    $images = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>出典画像一覧 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1100px;
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

        .form-row {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            margin-top: 8px;
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

        .count {
            margin: 16px 0;
            color: #666;
        }

        .public-link {
            margin: 12px 0 20px;
        }

        .public-link a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        .public-link a:hover {
            text-decoration: underline;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        .image-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px;
            background: #fafafa;
        }

        .image-card img {
            width: 100%;
            height: 180px;
            object-fit: contain;
            background: #fff;
            border: 1px solid #ddd;
        }

        .image-info {
            margin-top: 10px;
            font-size: 14px;
            line-height: 1.6;
        }

        .image-info a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        .image-info a:hover {
            text-decoration: underline;
        }

        .note {
            color: #666;
        }

        .empty {
            color: #666;
            margin-top: 20px;
        }

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .image-grid {
                grid-template-columns: 1fr;
            }

            .image-card img {
                height: auto;
                max-height: 300px;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>出典画像一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="source_image_add.php">出典画像登録</a>
        <a href="content_list.php">登録済み目次一覧</a>
        <a href="../issues.php">公開：雑誌号一覧</a>
    </div>

    <form method="get" action="source_image_list.php">
        <div class="form-row">
            <label>雑誌号を選択</label>
            <select name="issue_id">
                <option value="">選択してください</option>
                <?php foreach ($issues as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ($issue_id === (int)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['magazine_name']) ?>
                        <?= h($row['issue_label']) ?>
                        /
                        <?= h($row['publisher_name']) ?>
                        /
                        <?= h($row['release_date']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">表示する</button>
        </div>
    </form>

    <?php if ($issue_id <= 0): ?>

        <p class="empty">確認したい雑誌号を選択してください。</p>

    <?php else: ?>

        <div class="count">
            登録済み出典画像：<?= count($images) ?>件
        </div>

        <div class="public-link">
            <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">
                公開ページを開く
            </a>
        </div>

        <?php if (empty($images)): ?>

            <p class="empty">この雑誌号には、まだ出典画像が登録されていません。</p>

        <?php else: ?>

            <div class="image-grid">
                <?php foreach ($images as $row): ?>
                    <div class="image-card">
                        <a href="../<?= h($row['image_path']) ?>" target="_blank">
                            <img src="../<?= h($row['image_path']) ?>" alt="出典画像">
                        </a>

                        <div class="image-info">
                            <div>
                                <a href="../<?= h($row['image_path']) ?>" target="_blank">
                                    画像を開く
                                </a>
                            </div>

                            <div>
                                登録日時：<?= h($row['created_at']) ?>
                            </div>

                            <?php if (!empty($row['note'])): ?>
                                <div class="note">
                                    備考：<?= h($row['note']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="note">
                                ID：<?= h($row['id']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>
</body>
</html>