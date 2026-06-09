<?php
// issues.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

$sql = "
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

$stmt = $pdo->query($sql);
$issues = $stmt->fetchAll();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌号一覧 - INDEXINDEX</title>
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
            font-size: 28px;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
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
            text-decoration: none;
            font-weight: bold;
        }

        a:hover {
            text-decoration: underline;
        }

        .date {
            width: 120px;
        }

        .publisher {
            width: 160px;
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
                width: 80px;
                color: #555;
            }

            td.magazine::before { content: "雑誌"; }
            td.publisher::before { content: "出版社"; }
            td.issue::before { content: "号数"; }
            td.date::before { content: "発売日"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>雑誌号一覧</h1>

    <div class="count">
        <?= count($issues) ?>件
    </div>

    <?php if (empty($issues)): ?>

        <p>公開中の雑誌号はありません。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>雑誌名</th>
                    <th class="publisher">出版社</th>
                    <th>号数</th>
                    <th class="date">発売日</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($issues as $row): ?>
                <tr>
                    <td class="magazine">
                        <a href="issue.php?id=<?= h($row['id']) ?>">
                            <?= h($row['magazine_name']) ?>
                        </a>
                    </td>
                    <td class="publisher"><?= h($row['publisher_name']) ?></td>
                    <td class="issue">
                        <a href="issue.php?id=<?= h($row['id']) ?>">
                            <?= h($row['issue_label']) ?>
                        </a>
                    </td>
                    <td class="date"><?= h($row['release_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
</body>
</html>