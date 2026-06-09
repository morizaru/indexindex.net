<?php
// admin/publishers.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $publisher_id = isset($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : 0;

    if ($publisher_id <= 0) {
        $error = '出版社IDが不正です。';
    } else {
        try {
            // 雑誌に使われているか確認
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM magazines
                WHERE publisher_id = :publisher_id
            ");
            $stmt->execute([
                ':publisher_id' => $publisher_id
            ]);
            $row = $stmt->fetch();

            if ((int)$row['cnt'] > 0) {
                $error = 'この出版社は雑誌に使用されているため削除できません。先に雑誌側を修正してください。';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM publishers
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $publisher_id
                ]);

                $message = '出版社を削除しました。';
            }
        } catch (Exception $e) {
            $error = '削除エラー：' . $e->getMessage();
        }
    }
}

// 一覧取得
$sql = "
    SELECT
        p.id,
        p.name,
        COUNT(m.id) AS magazine_count,
        p.created_at,
        p.updated_at
    FROM publishers p
    LEFT JOIN magazines m ON p.id = m.publisher_id
    GROUP BY p.id
    ORDER BY p.name ASC
";

$stmt = $pdo->query($sql);
$publishers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>出版社一覧 - INDEXINDEX 管理</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1000px;
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
            color: #b00020;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
        }

        .filter-box {
            margin-bottom: 16px;
        }

        .filter-box input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
        }

        th {
            background: #eee;
            text-align: left;
        }

        .num {
            width: 100px;
            text-align: right;
        }

        .actions {
            width: 160px;
            white-space: nowrap;
        }

        a.button-link {
            display: inline-block;
            padding: 6px 10px;
            background: #333;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            margin-right: 4px;
        }

        button.delete-button {
            padding: 6px 10px;
            background: #b00020;
            color: #fff;
            border: 1px solid #b00020;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            thead {
                display: none;
            }

            tr {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 8px;
            }

            td {
                border: none;
                padding: 4px 0;
            }

            td::before {
                font-weight: bold;
                display: inline-block;
                width: 100px;
                color: #555;
            }

            td.name::before { content: "出版社"; }
            td.num::before { content: "雑誌数"; }
            td.actions::before { content: "操作"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>出版社一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="publisher_add.php">出版社登録</a>
        <a href="issues.php">雑誌一覧</a>
        <a href="../index.php">公開トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="count">
        <?= h(count($publishers)) ?>件
    </div>

    <div class="filter-box">
        <input type="text" id="publisherFilter" placeholder="出版社名で絞り込み">
    </div>

    <?php if (empty($publishers)): ?>

        <p>出版社データがありません。</p>

    <?php else: ?>

        <table id="publishersTable">
            <thead>
                <tr>
                    <th>出版社名</th>
                    <th class="num">雑誌数</th>
                    <th>更新日時</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($publishers as $row): ?>
                <tr>
                    <td class="name">
                        <?= h($row['name']) ?>
                        <div class="small">ID：<?= h($row['id']) ?></div>
                    </td>

                    <td class="num"><?= h($row['magazine_count']) ?></td>

                    <td>
                        <div class="small">
                            作成：<?= h($row['created_at']) ?><br>
                            更新：<?= h($row['updated_at']) ?>
                        </div>
                    </td>

                    <td class="actions">
                        <a class="button-link" href="publisher_edit.php?id=<?= h($row['id']) ?>">編集</a>

                        <form method="post" action="publishers.php" style="display:inline;"
                              onsubmit="return confirm('この出版社を削除しますか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="publisher_id" value="<?= h($row['id']) ?>">
                            <button type="submit" class="delete-button">削除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>

<script>
const filterInput = document.getElementById('publisherFilter');
const table = document.getElementById('publishersTable');

if (filterInput && table) {
    filterInput.addEventListener('input', () => {
        const keyword = filterInput.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = (!keyword || text.includes(keyword)) ? '' : 'none';
        });
    });
}
</script>

</body>
</html>