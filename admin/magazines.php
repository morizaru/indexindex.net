<?php
// admin/magazines.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $magazine_id = isset($_POST['magazine_id']) ? (int)$_POST['magazine_id'] : 0;

    if ($magazine_id <= 0) {
        $error = '雑誌IDが不正です。';
    } else {
        try {
            // 雑誌号に使われているか確認
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM magazine_issues
                WHERE magazine_id = :magazine_id
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id
            ]);
            $row = $stmt->fetch();

            if ((int)$row['cnt'] > 0) {
                $error = 'この雑誌は雑誌号に使用されているため削除できません。先に雑誌号側を修正してください。';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM magazines
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $magazine_id
                ]);

                $message = '雑誌を削除しました。';
            }
        } catch (Exception $e) {
            $error = '削除エラー：' . $e->getMessage();
        }
    }
}

// 一覧取得
$sql = "
    SELECT
        m.id,
        m.name,
        m.publisher_id,
        p.name AS publisher_name,
        COUNT(mi.id) AS issue_count,
        MIN(mi.issue_year) AS first_year,
        MAX(mi.issue_year) AS last_year,
        m.created_at,
        m.updated_at
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN magazine_issues mi ON m.id = mi.magazine_id
    GROUP BY m.id
    ORDER BY p.name ASC, m.name ASC
";

$stmt = $pdo->query($sql);
$magazines = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌本体一覧 - INDEXINDEX 管理</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1200px;
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

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .num {
            width: 90px;
            text-align: right;
        }

        .year {
            width: 150px;
            text-align: center;
        }

        .actions {
            width: 210px;
            white-space: nowrap;
        }

        .button-link {
            display: inline-block;
            padding: 6px 10px;
            background: #333;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            margin-right: 4px;
            margin-bottom: 4px;
        }

        .button-link.public {
            background: #0066cc;
        }

        .button-link.issue {
            background: #00897b;
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

        button.delete-button:disabled {
            background: #aaa;
            border-color: #aaa;
            cursor: not-allowed;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 900px) {
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
                width: 110px;
                color: #555;
            }

            td.magazine::before { content: "雑誌"; }
            td.publisher::before { content: "出版社"; }
            td.num::before { content: "号数"; }
            td.year::before { content: "収録年"; }
            td.actions::before { content: "操作"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>雑誌本体一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issues.php">雑誌号一覧</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="publishers.php">出版社一覧</a>
        <a href="../issues.php">公開：雑誌一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="count">
        <?= h(count($magazines)) ?>誌
    </div>

    <div class="filter-box">
        <input type="text" id="magazineFilter" placeholder="雑誌名・出版社名・年で絞り込み">
    </div>

    <?php if (empty($magazines)): ?>

        <p>雑誌データがありません。</p>

    <?php else: ?>

        <table id="magazinesTable">
            <thead>
                <tr>
                    <th>雑誌名</th>
                    <th>出版社</th>
                    <th class="num">登録号数</th>
                    <th class="year">収録年</th>
                    <th>更新日時</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($magazines as $row): ?>
                <?php
                    $can_delete = ((int)$row['issue_count'] === 0);
                ?>
                <tr>
                    <td class="magazine">
                        <strong><?= h($row['name']) ?></strong>
                        <div class="small">
                            雑誌ID：<?= h($row['id']) ?>
                        </div>
                    </td>

                    <td class="publisher">
                        <?= h($row['publisher_name']) ?>
                        <div class="small">
                            出版社ID：<?= h($row['publisher_id']) ?>
                        </div>
                    </td>

                    <td class="num"><?= h($row['issue_count']) ?></td>

                    <td class="year">
                        <?php if ($row['first_year'] && $row['last_year']): ?>
                            <?php if ($row['first_year'] == $row['last_year']): ?>
                                <?= h($row['first_year']) ?>年
                            <?php else: ?>
                                <?= h($row['first_year']) ?>年〜<?= h($row['last_year']) ?>年
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="small">
                            作成：<?= h($row['created_at']) ?><br>
                            更新：<?= h($row['updated_at']) ?>
                        </div>
                    </td>

                    <td class="actions">
                        <a class="button-link public" href="../issues.php?magazine_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                        <a class="button-link" href="magazine_edit.php?id=<?= h($row['id']) ?>">編集</a>
                        <a class="button-link issue" href="issues.php?magazine_id=<?= h($row['id']) ?>">号一覧</a>

                        <form method="post" action="magazines.php" style="display:inline;"
                              onsubmit="return confirm('この雑誌本体を削除しますか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="magazine_id" value="<?= h($row['id']) ?>">
                            <button type="submit" class="delete-button" <?= $can_delete ? '' : 'disabled' ?>>
                                削除
                            </button>
                        </form>

                        <?php if (!$can_delete): ?>
                            <div class="small">※雑誌号に使用中</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>

<script>
const filterInput = document.getElementById('magazineFilter');
const table = document.getElementById('magazinesTable');

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