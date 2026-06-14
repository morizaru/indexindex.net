<?php
// admin/content_list.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function editable_issue_status_sql(): string {
    return "'approved', 'in_progress', 'verified'";
}

// 現在の sort_order 順で 1,2,3... に振り直す
function renumber_issue_contents_by_current_order($pdo, $issue_id) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM issue_contents
        WHERE issue_id = :issue_id
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([
        ':issue_id' => $issue_id
    ]);

    $rows = $stmt->fetchAll();

    $order = 1;

    foreach ($rows as $row) {
        $stmt_update = $pdo->prepare("
            UPDATE issue_contents
            SET sort_order = :sort_order
            WHERE id = :id
        ");
        $stmt_update->execute([
            ':sort_order' => $order,
            ':id' => $row['id']
        ]);

        $order++;
    }
}

$publisher_id = isset($_GET['publisher_id']) ? (int)$_GET['publisher_id'] : 0;
$magazine_id = isset($_GET['magazine_id']) ? (int)$_GET['magazine_id'] : 0;
$publication_year = isset($_GET['publication_year']) ? trim($_GET['publication_year']) : '';
$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;

if ($publication_year !== '' && !preg_match('/^[0-9]{4}$/', $publication_year)) {
    $publication_year = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : $issue_id;
}

$message = '';
$error = '';

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $content_id = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
    $post_issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;

    if ($content_id <= 0) {
        $error = '目次IDが不正です。';
    } else {
        try {
            $pdo->beginTransaction();

            // 念のため作者紐付けも削除
            $stmt = $pdo->prepare("
                DELETE FROM content_creators
                WHERE content_id = :content_id
            ");
            $stmt->execute([
                ':content_id' => $content_id
            ]);

            $stmt = $pdo->prepare("
                DELETE FROM issue_contents
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $content_id
            ]);

            if ($post_issue_id > 0) {
                renumber_issue_contents_by_current_order($pdo, $post_issue_id);
            }

            $pdo->commit();

            $message = '目次データを削除しました。';
            $issue_id = $post_issue_id;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '削除エラー：' . $e->getMessage();
        }
    }
}

// 並び順更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_order') {
    $post_issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $content_order = trim((string)($_POST['content_order'] ?? ''));
    $order_json = $_POST['order_json'] ?? '';

    if ($post_issue_id <= 0) {
        $error = '雑誌号IDが不正です。';
    } elseif ($content_order === '' && $order_json === '') {
        $error = '並び順データがありません。';
    } else {
        try {
            if ($content_order !== '') {
                $ids = array_filter(array_map('trim', explode(',', $content_order)), 'strlen');
            } else {
                $ids = json_decode($order_json, true);
            }

            if (!is_array($ids) || empty($ids)) {
                throw new Exception('並び順データが不正です。');
            }

            $content_ids = [];
            foreach ($ids as $id) {
                $content_id = (int)$id;
                if ($content_id > 0) {
                    $content_ids[] = $content_id;
                }
            }

            $content_ids = array_values(array_unique($content_ids));

            if (empty($content_ids)) {
                throw new Exception('並び順データが不正です。');
            }

            $placeholders = [];
            $check_params = [
                ':issue_id' => $post_issue_id
            ];

            foreach ($content_ids as $index => $content_id) {
                $key = ':id' . $index;
                $placeholders[] = $key;
                $check_params[$key] = $content_id;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM issue_contents
                WHERE issue_id = :issue_id
                  AND id IN (" . implode(',', $placeholders) . ")
            ");
            $stmt->execute($check_params);
            $valid_count = (int)($stmt->fetch()['cnt'] ?? 0);

            if ($valid_count !== count($content_ids)) {
                throw new Exception('対象外の目次データが含まれています。');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM issue_contents
                WHERE issue_id = :issue_id
            ");
            $stmt->execute([
                ':issue_id' => $post_issue_id
            ]);
            $issue_content_count = (int)($stmt->fetch()['cnt'] ?? 0);

            if ($issue_content_count !== count($content_ids)) {
                throw new Exception('並び順データの件数が一致しません。画面を再読み込みしてから再度お試しください。');
            }

            $pdo->beginTransaction();

            $order = 1;

            foreach ($content_ids as $content_id) {
                // 対象の雑誌号に属する目次だけ更新
                $stmt = $pdo->prepare("
                    UPDATE issue_contents
                    SET sort_order = :sort_order
                    WHERE id = :id
                      AND issue_id = :issue_id
                ");
                $stmt->execute([
                    ':sort_order' => $order,
                    ':id' => $content_id,
                    ':issue_id' => $post_issue_id
                ]);

                $order++;
            }

            $pdo->commit();

            $message = '並び順を更新しました。';
            $issue_id = $post_issue_id;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '並び順更新エラー：' . $e->getMessage();
        }
    }
}

// 雑誌号一覧を取得
$stmt = $pdo->query("
    SELECT
        id,
        name
    FROM publishers
    ORDER BY name ASC
");
$publisher_rows = $stmt->fetchAll();

$sql_magazines = "
    SELECT
        id,
        name
    FROM magazines
    WHERE 1 = 1
";
$magazine_params = [];

if ($publisher_id > 0) {
    $sql_magazines .= " AND publisher_id = :publisher_id";
    $magazine_params[':publisher_id'] = $publisher_id;
}

$sql_magazines .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql_magazines);
$stmt->execute($magazine_params);
$magazines = $stmt->fetchAll();

$filter_year_expr = "COALESCE(
    mi.publication_year,
    YEAR(mi.publication_date),
    YEAR(mi.release_date),
    mi.issue_year
)";

$stmt = $pdo->query("
    SELECT DISTINCT
        COALESCE(
            publication_year,
            YEAR(publication_date),
            YEAR(release_date),
            issue_year
        ) AS filter_year
    FROM magazine_issues
    WHERE status IN (" . editable_issue_status_sql() . ")
      AND COALESCE(
            publication_year,
            YEAR(publication_date),
            YEAR(release_date),
            issue_year
          ) IS NOT NULL
    ORDER BY filter_year DESC
");
$publication_years = $stmt->fetchAll();

$sql_issues = "
    SELECT
        mi.id,
        mi.issue_label,
        mi.release_date,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE mi.status IN (" . editable_issue_status_sql() . ")
";
$issue_params = [];

if ($publisher_id > 0) {
    $sql_issues .= " AND m.publisher_id = :publisher_id";
    $issue_params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $sql_issues .= " AND mi.magazine_id = :magazine_id";
    $issue_params[':magazine_id'] = $magazine_id;
}

if ($publication_year !== '') {
    $sql_issues .= " AND {$filter_year_expr} = :publication_year";
    $issue_params[':publication_year'] = $publication_year;
}

$sql_issues .= "
    ORDER BY
        mi.issue_year DESC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END DESC,
        mi.id DESC
";
$stmt = $pdo->prepare($sql_issues);
$stmt->execute($issue_params);
$issues = $stmt->fetchAll();

if ($issue_id > 0) {
    $selected_issue_exists = false;

    foreach ($issues as $row) {
        if ((int)$row['id'] === $issue_id) {
            $selected_issue_exists = true;
            break;
        }
    }

    if (!$selected_issue_exists) {
        $issue_id = 0;
    }
}

$filter_query_params = [];

if ($publisher_id > 0) {
    $filter_query_params['publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $filter_query_params['magazine_id'] = $magazine_id;
}

if ($publication_year !== '') {
    $filter_query_params['publication_year'] = $publication_year;
}

if ($issue_id > 0) {
    $filter_query_params['issue_id'] = $issue_id;
}

$filter_query = http_build_query($filter_query_params);
$content_list_action = 'content_list.php' . ($filter_query !== '' ? '?' . $filter_query : '');

$contents = [];

if ($issue_id > 0) {
    $sql_contents = "
        SELECT
            ic.id,
            ic.issue_id,
            ic.sort_order,
            ic.page_start,
            ic.page_end,
            ic.title_main,
            w.title_sub,
            ic.episode_title,
            ic.episode_no,
            ic.note,
            GROUP_CONCAT(
                DISTINCT
                CASE
                    WHEN r.name IS NOT NULL AND r.name <> '' THEN CONCAT(r.name, '：', c.name)
                    ELSE c.name
                END
                ORDER BY cc.display_order
                SEPARATOR ' / '
            ) AS creators
        FROM issue_contents ic
        LEFT JOIN works w ON ic.work_id = w.id
        LEFT JOIN content_creators cc ON ic.id = cc.content_id
        LEFT JOIN creators c ON cc.creator_id = c.id
        LEFT JOIN roles r ON cc.role_id = r.id
        WHERE ic.issue_id = :issue_id
        GROUP BY ic.id
        ORDER BY ic.sort_order ASC, ic.id ASC
    ";

    $stmt = $pdo->prepare($sql_contents);
    $stmt->execute([
        ':issue_id' => $issue_id
    ]);
    $contents = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>登録済み目次一覧 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1250px;
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

        .nav a,
        .message a {
            margin-right: 12px;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
        }

        .nav a:hover,
        .message a:hover {
            text-decoration: underline;
        }

        .message {
            padding: 12px;
            background: #e8f5e9;
            border: 1px solid #81c784;
            margin-bottom: 16px;
            border-radius: 4px;
            line-height: 1.8;
        }

        .error {
            padding: 12px;
            background: #ffebee;
            border: 1px solid #e57373;
            color: #b00020;
            margin-bottom: 16px;
            border-radius: 4px;
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
            margin-right: 12px;
        }

        .public-link a:hover {
            text-decoration: underline;
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

        .help-box {
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #666;
            font-size: 14px;
            line-height: 1.7;
            margin: 12px 0 16px;
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

        .order {
            width: 60px;
            text-align: center;
        }

        .move {
            width: 95px;
            text-align: center;
        }

        .page {
            width: 100px;
            text-align: center;
        }

        .actions {
            width: 150px;
            white-space: nowrap;
        }

        .title-main {
            font-weight: bold;
            font-size: 16px;
        }

        .title-sub {
            color: #666;
            font-size: 14px;
            margin-top: 4px;
        }

        .episode-title {
            color: #444;
            font-size: 13px;
            margin-top: 4px;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .empty {
            color: #666;
            margin-top: 20px;
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

        .button-link:hover {
            background: #111;
            text-decoration: none;
        }

        .delete-button {
            padding: 6px 10px;
            background: #b00020;
            color: #fff;
            border: 1px solid #b00020;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 0;
        }

        .delete-button:hover {
            background: #8a0018;
        }

        .move-button {
            display: inline-block;
            padding: 4px 8px;
            margin: 0 2px 4px;
            font-size: 13px;
            border: 1px solid #777;
            background: #fff;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
        }

        .move-button:hover {
            background: #eee;
        }

        .drag-handle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            margin-right: 4px;
            border: 1px solid #bbb;
            border-radius: 4px;
            background: #f7f7f7;
            color: #555;
            cursor: grab;
            font-weight: bold;
            user-select: none;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        tr.is-dragging {
            opacity: 0.45;
        }

        tr.drag-over {
            outline: 2px solid #00897b;
            outline-offset: -2px;
        }

        .update-order-box {
            margin-top: 20px;
            padding: 16px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-align: right;
        }

        .update-order-button {
            margin-top: 0;
            background: #00897b;
            border-color: #00897b;
        }

        .update-order-button:hover {
            background: #00695c;
        }

        .muted {
            color: #666;
            font-size: 13px;
            margin-right: 12px;
        }

        @media (max-width: 800px) {
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
                width: 90px;
                color: #555;
            }

            td.order::before { content: "順"; }
            td.move::before { content: "入替"; }
            td.page::before { content: "ページ"; }
            td.title::before { content: "タイトル"; }
            td.episode::before { content: "話数"; }
            td.creators::before { content: "作者"; }
            td.note-cell::before { content: "備考"; }
            td.actions::before { content: "操作"; }

            .update-order-box {
                text-align: left;
            }

            .update-order-button {
                width: 100%;
                margin-top: 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>登録済み目次一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="content_add.php">目次登録</a>
        <a href="../issues.php">公開：雑誌号一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <?php if ($issue_id > 0): ?>
                <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="get" action="content_list.php" id="issueFilterForm">
        <div class="form-row">
            <label>出版社</label>
            <select name="publisher_id" id="publisherFilter">
                <option value="">すべて</option>
                <?php foreach ($publisher_rows as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ($publisher_id === (int)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>雑誌名</label>
            <select name="magazine_id" id="magazineFilter">
                <option value="">すべて</option>
                <?php foreach ($magazines as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ($magazine_id === (int)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>発行年</label>
            <select name="publication_year" id="publicationYearFilter">
                <option value="">すべて</option>
                <?php foreach ($publication_years as $row): ?>
                    <?php $year = (string)$row['filter_year']; ?>
                    <option value="<?= h($year) ?>" <?= ($publication_year === $year) ? 'selected' : '' ?>>
                        <?= h($year) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>雑誌号を選択</label>
            <select name="issue_id" id="issueSelect">
                <option value="">選択してください</option>
                <?php foreach ($issues as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ($issue_id === (int)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['magazine_name']) ?>
                        <?= h($row['issue_label']) ?>
                        /
                        <?= h($row['publisher_name']) ?>
                        <?php if (!empty($row['issue_no'])): ?>
                            /
                            <?= h($row['issue_no']) ?>号
                        <?php endif; ?>
                        <?php if (!empty($row['release_date'])): ?>
                            /
                            <?= h($row['release_date']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">表示する</button>
            <a class="button-link" href="content_list.php">条件をリセット</a>
        </div>
    </form>

    <?php if ($issue_id <= 0): ?>

        <p class="empty">確認したい雑誌号を選択してください。</p>

    <?php else: ?>

        <div class="count">
            登録済み目次：<?= h(count($contents)) ?>件
        </div>

        <div class="public-link">
            <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">
                公開ページを開く
            </a>
            <a href="content_add.php?issue_id=<?= h($issue_id) ?>">
                この雑誌号に目次を追加
            </a>
        </div>

        <?php if (empty($contents)): ?>

            <p class="empty">この雑誌号には、まだ目次が登録されていません。</p>

        <?php else: ?>

            <div class="help-box">
                「↑」「↓」またはドラッグ＆ドロップで行を入れ替えたあと、一番下の「並び順を保存」を押すと掲載順が確定します。<br>
                フィルター中に入れ替えると見えない行との位置関係が分かりにくくなるため、並び替え前は絞り込みを空にするのがおすすめです。
            </div>

            <div class="filter-box">
                <input type="text" id="contentFilter" placeholder="タイトル・作者・話数・備考で絞り込み">
            </div>

            <table id="contentsTable">
                <thead>
                    <tr>
                        <th class="order">順</th>
                        <th class="move">入替</th>
                        <th class="page">ページ</th>
                        <th>タイトル</th>
                        <th>話数</th>
                        <th>作者</th>
                        <th>備考</th>
                        <th class="actions">操作</th>
                    </tr>
                </thead>
                <tbody id="contentsTbody">
                <?php foreach ($contents as $row): ?>
                    <tr data-content-id="<?= h($row['id']) ?>" draggable="true">
                        <td class="order">
                            <span class="order-number"><?= h($row['sort_order']) ?></span>
                        </td>

                        <td class="move">
                            <span class="drag-handle" title="ドラッグで並び替え">⇅</span>
                            <button type="button" class="move-button move-up">↑</button>
                            <button type="button" class="move-button move-down">↓</button>
                        </td>

                        <td class="page">
                            <?php if ($row['page_start'] && $row['page_end']): ?>
                                <?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?>
                            <?php elseif ($row['page_start']): ?>
                                <?= h($row['page_start']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td class="title">
                            <div class="title-main"><?= h($row['title_main']) ?></div>

                            <?php if (!empty($row['title_sub'])): ?>
                                <div class="title-sub"><?= h($row['title_sub']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($row['episode_title'])): ?>
                                <div class="episode-title"><?= h($row['episode_title']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td class="episode"><?= h($row['episode_no'] ?? '') ?></td>

                        <td class="creators"><?= h($row['creators'] ?? '') ?></td>

                        <td class="note-cell">
                            <?php if (!empty($row['note'])): ?>
                                <span class="note"><?= h($row['note']) ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="actions">
                            <a class="button-link" href="content_edit.php?id=<?= h($row['id']) ?>">編集</a>

                            <form method="post" action="<?= h($content_list_action) ?>" style="display:inline;"
                                  onsubmit="return confirm('この目次データを削除しますか？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="content_id" value="<?= h($row['id']) ?>">
                                <input type="hidden" name="issue_id" value="<?= h($issue_id) ?>">
                                <button type="submit" class="delete-button">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="<?= h($content_list_action) ?>" id="orderForm">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="issue_id" value="<?= h($issue_id) ?>">
                <input type="hidden" name="content_order" id="contentOrder" value="">
                <input type="hidden" name="order_json" id="orderJson" value="">

                <div class="update-order-box">
                    <span class="muted">現在の画面上の並び順で掲載順を更新します。</span>
                    <button type="submit" class="update-order-button">並び順を保存</button>
                </div>
            </form>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
const filterInput = document.getElementById('contentFilter');
const table = document.getElementById('contentsTable');
const tbody = document.getElementById('contentsTbody');
const orderForm = document.getElementById('orderForm');
const contentOrder = document.getElementById('contentOrder');
const orderJson = document.getElementById('orderJson');
const issueFilterForm = document.getElementById('issueFilterForm');
const publisherFilter = document.getElementById('publisherFilter');
const magazineFilter = document.getElementById('magazineFilter');
const publicationYearFilter = document.getElementById('publicationYearFilter');
const issueSelect = document.getElementById('issueSelect');
let draggedRow = null;

function resetIssueSelection() {
    if (issueSelect) {
        issueSelect.value = '';
    }
}

function submitIssueFilterForm() {
    if (issueFilterForm) {
        issueFilterForm.submit();
    }
}

if (publisherFilter) {
    publisherFilter.addEventListener('change', () => {
        if (magazineFilter) {
            magazineFilter.value = '';
        }
        resetIssueSelection();
        submitIssueFilterForm();
    });
}

if (magazineFilter) {
    magazineFilter.addEventListener('change', () => {
        resetIssueSelection();
        submitIssueFilterForm();
    });
}

if (publicationYearFilter) {
    publicationYearFilter.addEventListener('change', () => {
        resetIssueSelection();
        submitIssueFilterForm();
    });
}

if (issueSelect) {
    issueSelect.addEventListener('change', submitIssueFilterForm);
}

function refreshOrderNumbers() {
    if (!tbody) {
        return;
    }

    const rows = tbody.querySelectorAll('tr');
    let order = 1;

    rows.forEach(row => {
        const number = row.querySelector('.order-number');
        if (number) {
            number.textContent = order;
        }
        order++;
    });
}

function moveRow(row, direction) {
    if (!row || !tbody) {
        return;
    }

    if (direction === 'up') {
        const prev = row.previousElementSibling;
        if (prev) {
            tbody.insertBefore(row, prev);
        }
    }

    if (direction === 'down') {
        const next = row.nextElementSibling;
        if (next) {
            tbody.insertBefore(next, row);
        }
    }

    refreshOrderNumbers();
}

if (tbody) {
    tbody.addEventListener('click', event => {
        const target = event.target;

        if (!target.classList.contains('move-button')) {
            return;
        }

        const row = target.closest('tr');

        if (target.classList.contains('move-up')) {
            moveRow(row, 'up');
        }

        if (target.classList.contains('move-down')) {
            moveRow(row, 'down');
        }
    });

    tbody.addEventListener('dragstart', event => {
        const row = event.target.closest('tr');

        if (!row || !row.dataset.contentId) {
            return;
        }

        draggedRow = row;
        row.classList.add('is-dragging');

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', row.dataset.contentId);
        }
    });

    tbody.addEventListener('dragover', event => {
        if (!draggedRow) {
            return;
        }

        event.preventDefault();

        const targetRow = event.target.closest('tr');
        if (!targetRow || targetRow === draggedRow || targetRow.parentNode !== tbody) {
            return;
        }

        tbody.querySelectorAll('tr.drag-over').forEach(row => {
            row.classList.remove('drag-over');
        });
        targetRow.classList.add('drag-over');

        const rect = targetRow.getBoundingClientRect();
        const insertBefore = event.clientY < rect.top + rect.height / 2;

        if (insertBefore) {
            tbody.insertBefore(draggedRow, targetRow);
        } else {
            tbody.insertBefore(draggedRow, targetRow.nextElementSibling);
        }

        refreshOrderNumbers();
    });

    tbody.addEventListener('drop', event => {
        event.preventDefault();
    });

    tbody.addEventListener('dragend', () => {
        if (draggedRow) {
            draggedRow.classList.remove('is-dragging');
        }

        tbody.querySelectorAll('tr.drag-over').forEach(row => {
            row.classList.remove('drag-over');
        });

        draggedRow = null;
        refreshOrderNumbers();
    });
}

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

if (orderForm && orderJson && tbody) {
    orderForm.addEventListener('submit', event => {
        const ids = [];

        tbody.querySelectorAll('tr').forEach(row => {
            const id = row.dataset.contentId;
            if (id) {
                ids.push(id);
            }
        });

        if (ids.length === 0) {
            event.preventDefault();
            alert('更新する目次データがありません。');
            return;
        }

        if (contentOrder) {
            contentOrder.value = ids.join(',');
        }
        orderJson.value = JSON.stringify(ids);
    });
}

refreshOrderNumbers();
</script>

</body>
</html>
