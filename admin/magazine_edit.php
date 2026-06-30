<?php
// admin/magazine_edit.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nullable_text($value) {
    $value = trim((string)$value);
    return ($value !== '') ? $value : null;
}

$magazine_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $magazine_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($magazine_id <= 0) {
    exit('雑誌IDが指定されていません。');
}

$message = '';
$error = '';
$publication_frequency_options = ['', '週刊', '月刊', '隔週刊', '隔月刊', '月2回刊', '季刊', '日刊', '不定期刊'];

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '雑誌本体を保存しました。';
}

// 出版社一覧
$stmt = $pdo->query("
    SELECT id, name
    FROM publishers
    ORDER BY name ASC
");
$publishers = $stmt->fetchAll();

// 現在データ取得
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.publisher_id,
        m.name,
        m.name_search,
        m.name_romaji,
        m.publication_frequency,
        m.note,
        m.created_at,
        m.updated_at,
        p.name AS publisher_name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    WHERE m.id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $magazine_id
]);
$magazine = $stmt->fetch();

if (!$magazine) {
    exit('指定された雑誌が見つかりません。');
}

$name = $magazine['name'];
$publisher_id = (int)$magazine['publisher_id'];
$name_search = $magazine['name_search'] ?? '';
$name_romaji = $magazine['name_romaji'] ?? '';
$publication_frequency = $magazine['publication_frequency'] ?? '';
$note = $magazine['note'] ?? '';

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $publisher_id = isset($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : 0;
    $name_search = trim($_POST['name_search'] ?? '');
    $name_romaji = trim($_POST['name_romaji'] ?? '');
    $publication_frequency = trim($_POST['publication_frequency'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if (!in_array($publication_frequency, $publication_frequency_options, true)) {
        $publication_frequency = '';
    }

    if ($name === '') {
        $error = '雑誌名を入力してください。';
    } elseif ($publisher_id <= 0) {
        $error = '出版社を選択してください。';
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
                      AND id <> :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':publisher_id' => $publisher_id,
                    ':name' => $name,
                    ':id' => $magazine_id
                ]);
                $exists = $stmt->fetch();

                if ($exists) {
                    $error = '同じ出版社に同じ雑誌名がすでに登録されています。';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE magazines
                        SET
                            publisher_id = :publisher_id,
                            name = :name,
                            name_search = :name_search,
                            name_romaji = :name_romaji,
                            publication_frequency = :publication_frequency,
                            note = :note
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':publisher_id' => $publisher_id,
                        ':name' => $name,
                        ':name_search' => nullable_text($name_search),
                        ':name_romaji' => nullable_text($name_romaji),
                        ':publication_frequency' => nullable_text($publication_frequency),
                        ':note' => nullable_text($note),
                        ':id' => $magazine_id
                    ]);

                    header('Location: magazine_edit.php?id=' . $magazine_id . '&saved=1');
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 雑誌号一覧
$stmt = $pdo->prepare("
    SELECT
        mi.id,
        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        mi.release_date,
        mi.status,
        COUNT(ic.id) AS content_count
    FROM magazine_issues mi
    LEFT JOIN issue_contents ic ON mi.id = ic.issue_id
    WHERE mi.magazine_id = :magazine_id
    GROUP BY mi.id
    ORDER BY
        mi.issue_year DESC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END DESC,
        mi.id DESC
");
$stmt->execute([
    ':magazine_id' => $magazine_id
]);
$issues = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌本体編集 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 1050px;
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

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-grid .full-row {
            grid-column: 1 / -1;
        }

        .links {
            line-height: 2;
            margin-top: 16px;
        }

        .links a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
            margin-right: 12px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .empty {
            color: #666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-top: 12px;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 8px;
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
            width: 90px;
            text-align: center;
        }

        .status {
            display: inline-block;
            padding: 2px 6px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            color: #555;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .form-grid {
                display: block;
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

            td.issue::before { content: "雑誌号"; }
            td.year::before { content: "年"; }
            td.num::before { content: "目次"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>雑誌本体編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="magazines.php">雑誌本体一覧</a>
        <a href="issues.php">雑誌号一覧</a>
        <a href="publishers.php">出版社一覧</a>
        <a href="../issues.php?magazine_id=<?= h($magazine_id) ?>" target="_blank">公開ページ</a>
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

    <div class="info">
        雑誌ID：<?= h($magazine['id']) ?><br>
        現在：<?= h($magazine['publisher_name']) ?> / <?= h($magazine['name']) ?><br>
        登録号数：<?= h(count($issues)) ?>件<br>
        作成日時：<?= h($magazine['created_at']) ?><br>
        更新日時：<?= h($magazine['updated_at']) ?>
    </div>

    <form method="post" action="magazine_edit.php?id=<?= h($magazine_id) ?>">

        <input type="hidden" name="id" value="<?= h($magazine_id) ?>">

        <div class="form-grid">
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
                <div class="help">変更するのは publishers.id ではなく magazines.publisher_id です。</div>
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

            <div class="form-row full-row">
                <label>備考</label>
                <textarea name="note"><?= h($note) ?></textarea>
            </div>
        </div>

        <button type="submit">更新する</button>

    </form>

    <div class="links">
        <a href="../issues.php?magazine_id=<?= h($magazine_id) ?>" target="_blank">公開：この雑誌の号一覧</a>
        <a href="issues.php">管理：雑誌号一覧</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="magazines.php">雑誌本体一覧</a>
    </div>

    <h2>この雑誌の号一覧</h2>

    <?php if (empty($issues)): ?>

        <p class="empty">この雑誌には雑誌号が登録されていません。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>雑誌号</th>
                    <th class="year">年</th>
                    <th class="num">目次</th>
                    <th>状態</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($issues as $issue): ?>
                <tr>
                    <td class="issue">
                        <a href="issue_edit.php?id=<?= h($issue['id']) ?>">
                            <?= h($issue['issue_label']) ?>
                        </a>
                        <div class="small">
                            ID：<?= h($issue['id']) ?>
                            <?php if (!empty($issue['issue_no'])): ?>
                                / <?= h($issue['issue_no']) ?>号
                            <?php endif; ?>
                            <?php if (!empty($issue['release_date'])): ?>
                                / 発売日：<?= h($issue['release_date']) ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="year"><?= h($issue['issue_year'] ?? '') ?></td>

                    <td class="num"><?= h($issue['content_count']) ?></td>

                    <td>
                        <span class="status"><?= h($issue['status']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
</body>
</html>
