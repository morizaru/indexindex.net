<?php
// admin/work_edit.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$work_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($work_id <= 0) {
    exit('作品IDが指定されていません。');
}

$message = '';
$error = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '作品を保存しました。';
}

// 話数フォーマット一覧
$stmt = $pdo->query("
    SELECT
        id,
        name,
        prefix,
        suffix
    FROM episode_formats
    ORDER BY id ASC
");
$episode_formats = $stmt->fetchAll();

// 現在データ取得
$stmt = $pdo->prepare("
    SELECT
        id,
        title,
        title_sub,
        title_kana,
        publication_type,
        episode_format_id,
        created_at,
        updated_at
    FROM works
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $work_id
]);
$work = $stmt->fetch();

if (!$work) {
    exit('指定された作品が見つかりません。');
}

$title = $work['title'];
$title_sub = $work['title_sub'] ?? '';
$title_kana = $work['title_kana'] ?? '';
$publication_type = $work['publication_type'] ?? '連載';
$episode_format_id = isset($work['episode_format_id']) ? (int)$work['episode_format_id'] : 0;

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $title_sub = trim($_POST['title_sub'] ?? '');
    $title_kana = trim($_POST['title_kana'] ?? '');
    $publication_type = trim($_POST['publication_type'] ?? '連載');
    $episode_format_id = isset($_POST['episode_format_id']) ? (int)$_POST['episode_format_id'] : 0;

    if ($title === '') {
        $error = '作品名を入力してください。';
    } elseif (!in_array($publication_type, ['連載', '読切'], true)) {
        $error = '掲載フラグが不正です。';
    } else {
        try {
            // 話数フォーマット存在チェック
            if ($episode_format_id > 0) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM episode_formats
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':id' => $episode_format_id
                ]);
                $format_exists = $stmt->fetch();

                if (!$format_exists) {
                    throw new Exception('選択した話数フォーマットが見つかりません。');
                }
            }

            // 重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM works
                WHERE title = :title
                  AND id <> :id
                LIMIT 1
            ");
            $stmt->execute([
                ':title' => $title,
                ':id' => $work_id
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = '同じ作品名がすでに登録されています。';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE works
                    SET
                        title = :title,
                        title_sub = :title_sub,
                        title_kana = :title_kana,
                        publication_type = :publication_type,
                        episode_format_id = :episode_format_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':title_sub' => ($title_sub !== '') ? $title_sub : null,
                    ':title_kana' => ($title_kana !== '') ? $title_kana : null,
                    ':publication_type' => $publication_type,
                    ':episode_format_id' => ($episode_format_id > 0) ? $episode_format_id : null,
                    ':id' => $work_id
                ]);

                // issue_contents.title_main も同期
                $stmt = $pdo->prepare("
                    UPDATE issue_contents
                    SET title_main = :title
                    WHERE work_id = :work_id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':work_id' => $work_id
                ]);

                header('Location: work_edit.php?id=' . $work_id . '&saved=1');
                exit;
            }
        } catch (Exception $e) {
            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 掲載一覧
$stmt = $pdo->prepare("
    SELECT
        ic.id AS content_id,
        ic.issue_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.episode_no,
        ic.episode_title,
        ic.note,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode,
        ic.episode_format_id AS content_episode_format_id,
        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name,
        GROUP_CONCAT(
            DISTINCT
            CASE
                WHEN r.name IS NOT NULL AND r.name <> '' THEN CONCAT(r.name, '：', c.name)
                ELSE c.name
            END
            ORDER BY cc.display_order
            SEPARATOR ' / '
        ) AS creators,
        cef.name AS content_episode_format_name,
        cef.prefix AS content_episode_format_prefix,
        cef.suffix AS content_episode_format_suffix
    FROM issue_contents ic
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id
    LEFT JOIN creators c ON cc.creator_id = c.id
    LEFT JOIN roles r ON cc.role_id = r.id
    LEFT JOIN episode_formats cef ON ic.episode_format_id = cef.id
    WHERE ic.work_id = :work_id
    GROUP BY ic.id
    ORDER BY
        mi.issue_year ASC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END ASC,
        mi.id ASC,
        ic.sort_order ASC
");
$stmt->execute([
    ':work_id' => $work_id
]);
$contents = $stmt->fetchAll();

$current_format_preview = '';
foreach ($episode_formats as $format) {
    if ((int)$format['id'] === $episode_format_id) {
        $current_format_preview = (string)($format['prefix'] ?? '') . '1' . (string)($format['suffix'] ?? '');
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>作品編集 - INDEXINDEX 管理</title>
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

        h2 {
            margin-top: 28px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
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

        .info {
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-radius: 4px;
            line-height: 1.7;
            color: #555;
        }

        .form-row {
            margin-bottom: 16px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input[type="text"],
        select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
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
            line-height: 1.6;
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

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .order {
            width: 70px;
            text-align: center;
        }

        .page {
            width: 100px;
            text-align: center;
        }

        .format-preview {
            font-weight: bold;
            color: #444;
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
                width: 110px;
                color: #555;
            }

            td.issue::before { content: "雑誌・号"; }
            td.order::before { content: "掲載順"; }
            td.page::before { content: "ページ"; }
            td.episode::before { content: "話数"; }
            td.episode-title::before { content: "話タイトル"; }
            td.publication-type::before { content: "掲載区分"; }
            td.serialization-status::before { content: "連載状態"; }
            td.creators::before { content: "人物"; }
            td.note-cell::before { content: "備考"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>作品編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="works.php">作品一覧</a>
        <a href="episode_formats.php">話数フォーマット一覧</a>
        <a href="../work.php?work_id=<?= h($work_id) ?>" target="_blank">公開ページ</a>
        <a href="../work_graph.php?work_id=<?= h($work_id) ?>" target="_blank">グラフ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="works.php">作品一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="info">
        ID：<?= h($work['id']) ?><br>
        掲載件数：<?= h(count($contents)) ?>件<br>
        話数フォーマット：
        <?php if ($episode_format_id > 0): ?>
            <?= h($current_format_preview) ?>
        <?php else: ?>
            未設定
        <?php endif; ?><br>
        作成日時：<?= h($work['created_at']) ?><br>
        更新日時：<?= h($work['updated_at']) ?>
    </div>

    <form method="post" action="work_edit.php?id=<?= h($work_id) ?>">

        <input type="hidden" name="id" value="<?= h($work_id) ?>">

        <div class="form-row">
            <label>
                作品名 <span class="required">必須</span>
            </label>
            <input type="text" name="title" value="<?= h($title) ?>" placeholder="例：紫電改のタカ">
            <div class="help">
                作品名を変更すると、この作品に紐づく目次タイトルも同じ名前に更新します。
            </div>
        </div>

        <div class="form-row">
            <label>作品サブタイトル</label>
            <input type="text" name="title_sub" value="<?= h($title_sub) ?>" placeholder="例：別題・シリーズ名など">
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>作品名かな</label>
                <input type="text" name="title_kana" value="<?= h($title_kana) ?>" placeholder="例：しでんかいのたか">
            </div>

            <div class="form-row">
                <label>掲載フラグ</label>
                <select name="publication_type">
                    <option value="連載" <?= ($publication_type === '連載') ? 'selected' : '' ?>>連載</option>
                    <option value="読切" <?= ($publication_type === '読切') ? 'selected' : '' ?>>読切</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <label>話数フォーマット</label>
            <select name="episode_format_id">
                <option value="">未設定</option>
                <?php foreach ($episode_formats as $format): ?>
                    <?php
                        $preview = (string)($format['prefix'] ?? '') . '1' . (string)($format['suffix'] ?? '');
                    ?>
                    <option value="<?= h($format['id']) ?>" <?= ($episode_format_id === (int)$format['id']) ? 'selected' : '' ?>>
                        <?= h($format['name']) ?>（例：<?= h($preview) ?>）
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="help">
                作品全体の標準フォーマットです。公開ページでは「第」＋話数＋「話」のように表示します。<br>
                例外的に1話だけ違う形式にしたい場合は、後で目次側の話数フォーマットを優先させます。
            </div>
        </div>

        <button type="submit">更新する</button>

    </form>

    <div class="links">
        <a href="../work.php?work_id=<?= h($work_id) ?>" target="_blank">公開ページ</a>
        <a href="../work_graph.php?work_id=<?= h($work_id) ?>" target="_blank">連載順グラフ</a>
        <a href="works.php">作品一覧</a>
    </div>

    <h2>この作品の掲載一覧</h2>

    <?php if (empty($contents)): ?>

        <p class="empty">この作品に紐づく目次データはありません。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>雑誌・号</th>
                    <th class="order">掲載順</th>
                    <th class="page">ページ</th>
                    <th>話数</th>
                    <th>話タイトル</th>
                    <th>掲載区分</th>
                    <th>連載状態</th>
                    <th>人物</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
                <?php
                    $episode_display = $row['episode_no'] ?? '';

                    if ($episode_display !== '' && !empty($row['content_episode_format_id'])) {
                        $episode_display = (string)($row['content_episode_format_prefix'] ?? '') . $episode_display . (string)($row['content_episode_format_suffix'] ?? '');
                    } elseif ($episode_display !== '' && $episode_format_id > 0) {
                        foreach ($episode_formats as $format) {
                            if ((int)$format['id'] === $episode_format_id) {
                                $episode_display = (string)($format['prefix'] ?? '') . $episode_display . (string)($format['suffix'] ?? '');
                                break;
                            }
                        }
                    }
                ?>
                <tr>
                    <td class="issue">
                        <a href="content_edit.php?id=<?= h($row['content_id']) ?>">
                            <?= h($row['magazine_name']) ?>
                            <?= h($row['issue_label']) ?>
                        </a>
                        <div class="small">
                            <?= h($row['publisher_name']) ?>
                            <?php if (!empty($row['issue_no'])): ?>
                                / <?= h($row['issue_no']) ?>号
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="order"><?= h($row['sort_order']) ?></td>

                    <td class="page">
                        <?php if ($row['page_start'] && $row['page_end']): ?>
                            <?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?>
                        <?php elseif ($row['page_start']): ?>
                            <?= h($row['page_start']) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td class="episode">
                        <?= h($episode_display) ?>
                    </td>

                    <td class="episode-title"><?= h($row['episode_title'] ?? '') ?></td>

                    <td class="publication-type">
                        <?= h(($row['publication_type'] ?? '') !== '' ? $row['publication_type'] : '-') ?>
                    </td>

                    <td class="serialization-status">
                        <?php
                            $status_labels = [];
                            if ((int)($row['is_new_series'] ?? 0) === 1) {
                                $status_labels[] = '新連載';
                            }
                            if ((int)($row['is_final_episode'] ?? 0) === 1) {
                                $status_labels[] = '最終回';
                            }
                        ?>
                        <?= h(!empty($status_labels) ? implode(' / ', $status_labels) : '-') ?>
                    </td>

                    <td class="creators"><?= h($row['creators'] ?? '') ?></td>

                    <td class="note-cell">
                        <?php if (!empty($row['note'])): ?>
                            <span class="note"><?= h($row['note']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
</body>
</html>
