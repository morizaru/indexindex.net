<?php
// admin/content_edit.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/content_creators.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// 指定した雑誌号の目次を開始ページ順で並べ直し、掲載順を 1,2,3... に振り直す
function renumber_issue_contents($pdo, $issue_id) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM issue_contents
        WHERE issue_id = :issue_id
        ORDER BY
            CASE
                WHEN page_start IS NULL THEN 1
                ELSE 0
            END ASC,
            page_start ASC,
            id ASC
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

function normalize_creator_rows($posted_rows) {
    if (!is_array($posted_rows)) {
        return [];
    }

    $rows = [];
    $auto_order = 1;
    $input_index = 0;

    foreach ($posted_rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $existing_id = (int)($row['id'] ?? 0);
        $creator_name = $existing_id > 0
            ? (string)($row['creator_name'] ?? '')
            : mb_convert_kana(trim((string)($row['creator_name'] ?? '')), 'as', 'UTF-8');

        if (trim($creator_name) === '') {
            continue;
        }

        $role = $existing_id > 0
            ? (string)($row['role'] ?? '')
            : mb_convert_kana(trim((string)($row['role'] ?? '')), 'as', 'UTF-8');

        $display_order_raw = trim((string)($row['display_order'] ?? ''));
        if ($existing_id > 0 && preg_match('/^-?[0-9]+$/', $display_order_raw)) {
            $display_order = (int)$display_order_raw;
        } elseif ($display_order_raw !== '' && preg_match('/^[0-9]+$/', $display_order_raw)) {
            $display_order = (int)$display_order_raw;
            if ($display_order <= 0) {
                $display_order = $auto_order;
            }
        } else {
            $display_order = $auto_order;
        }

        $role_id_raw = trim((string)($row['role_id'] ?? ''));
        $role_id = ($role_id_raw !== '' && preg_match('/^[0-9]+$/', $role_id_raw)) ? (int)$role_id_raw : null;
        $is_visible = (isset($row['is_visible']) && (string)$row['is_visible'] === '1') ? 1 : 0;

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'role_id' => $role_id,
            'role' => $role,
            'creator_name' => $creator_name,
            'display_order' => $display_order,
            'is_visible' => $is_visible,
            'input_index' => $input_index,
        ];

        $auto_order++;
        $input_index++;
    }

    usort($rows, function ($a, $b) {
        if ($a['display_order'] === $b['display_order']) {
            return $a['input_index'] <=> $b['input_index'];
        }

        return $a['display_order'] <=> $b['display_order'];
    });

    return $rows;
}

function resolve_creator_id($pdo, $creator_name) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM creators
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $creator_name
    ]);
    $creator = $stmt->fetch();

    if ($creator) {
        return (int)$creator['id'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT creator_id
            FROM creator_aliases
            WHERE alias_name = :alias_name
            LIMIT 1
        ");
        $stmt->execute([
            ':alias_name' => $creator_name
        ]);
        $alias = $stmt->fetch();

        if ($alias) {
            return (int)$alias['creator_id'];
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO creators (name)
        VALUES (:name)
    ");
    $stmt->execute([
        ':name' => $creator_name
    ]);

    return (int)$pdo->lastInsertId();
}

$content_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$message = '';
$error = '';

if ($content_id <= 0) {
    die('目次IDが指定されていません。');
}

// 現在の目次データ取得
$stmt = $pdo->prepare("
    SELECT
        ic.id,
        ic.issue_id,
        ic.work_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_title,
        ic.episode_no,
        ic.note,
        ic.content_type,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode,
        mi.issue_label,
        mi.release_date,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name,
        w.title AS work_title,
        GROUP_CONCAT(c.name ORDER BY cc.display_order SEPARATOR '、') AS creator_names
    FROM issue_contents ic
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id
    LEFT JOIN creators c ON cc.creator_id = c.id
    WHERE ic.id = :id
    GROUP BY
        ic.id,
        ic.issue_id,
        ic.work_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_title,
        ic.episode_no,
        ic.note,
        ic.content_type,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode,
        mi.issue_label,
        mi.release_date,
        mi.issue_year,
        mi.issue_no,
        m.name,
        p.name,
        w.title
    LIMIT 1
");
$stmt->execute([
    ':id' => $content_id
]);
$content = $stmt->fetch();

if (!$content) {
    die('指定された目次データが見つかりません。');
}

$issue_id = (int)$content['issue_id'];

$sort_order = $content['sort_order'];
$page_start = $content['page_start'];
$page_end = $content['page_end'];
$title_main = $content['title_main'];
$episode_title = $content['episode_title'];
$episode_no = $content['episode_no'];
$note = $content['note'];

$sql_creators = "
    SELECT
        cc.id,
        cc.creator_id,
        cc.role_id,
        cc.role,
        cc.display_name,
        cc.display_affiliation,
        cc.display_order,
        cc.is_visible,
        c.name AS creator_name
    FROM content_creators cc
    JOIN creators c ON cc.creator_id = c.id
    WHERE cc.content_id = :content_id
    ORDER BY
        cc.display_order ASC,
        cc.id ASC
";
$stmt = $pdo->prepare($sql_creators);
$stmt->execute([
    ':content_id' => $content_id
]);
$creator_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$creator_revision = hash('sha256', serialize($creator_rows));

if (empty($creator_rows)) {
    $creator_rows = [
        [
            'role_id' => null,
            'role' => 'author',
            'display_order' => 1,
            'is_visible' => 1,
            'creator_name' => '',
        ],
    ];
}

$publication_type = $content['publication_type'] ?: '連載';
$is_one_shot = ($publication_type === '読切') ? 1 : 0;
$is_guest_publication = ($publication_type === '出張掲載') ? 1 : 0;
$is_new_series = (int)($content['is_new_series'] ?? 0);
$is_final_episode = (int)($content['is_final_episode'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sort_order = trim($_POST['sort_order'] ?? '');
    $page_start = trim($_POST['page_start'] ?? '');
    $page_end = trim($_POST['page_end'] ?? '');
    $title_main = trim($_POST['title_main'] ?? '');
    $episode_title = trim($_POST['episode_title'] ?? '');
    $episode_no = trim($_POST['episode_no'] ?? '');
    $creator_rows = normalize_creator_rows($_POST['creators'] ?? []);
    $note = trim($_POST['note'] ?? '');

    $is_one_shot = isset($_POST['is_one_shot']) ? 1 : 0;
    $is_guest_publication = isset($_POST['is_guest_publication']) ? 1 : 0;
    $is_new_series = isset($_POST['is_new_series']) ? 1 : 0;
    $is_final_episode = isset($_POST['is_final_episode']) ? 1 : 0;

    if ($is_one_shot && $is_guest_publication) {
        $publication_type = '';
    } elseif ($is_one_shot) {
        $publication_type = '読切';
    } elseif ($is_guest_publication) {
        $publication_type = '出張掲載';
    } else {
        $publication_type = '連載';
    }

    if ($title_main === '') {
        $error = 'タイトルを入力してください。';
    } elseif ($page_start !== '' && !preg_match('/^[0-9]+$/', $page_start)) {
        $error = '開始ページは数字で入力してください。';
    } elseif ($page_end !== '' && !preg_match('/^[0-9]+$/', $page_end)) {
        $error = '終了ページは数字で入力してください。';
    } elseif ($sort_order !== '' && !preg_match('/^[0-9]+$/', $sort_order)) {
        $error = '掲載順は数字で入力してください。';
    } elseif ($is_one_shot && $is_guest_publication) {
        $error = '読切と出張掲載は同時に選択できません。';
    } else {
        try {
            $pdo->beginTransaction();
            $creator_writer = new ContentCreatorWriter($pdo);
            $creator_writer->lockContent($content_id);
            $stmt = $pdo->prepare($sql_creators);
            $stmt->execute([':content_id' => $content_id]);
            $stored_creator_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!hash_equals(hash('sha256', serialize($stored_creator_rows)), (string)($_POST['creator_revision'] ?? ''))) {
                throw new Exception('人物情報が更新されています。画面を再読み込みして編集し直してください。');
            }

            // 作品を取得、なければ追加
            $stmt = $pdo->prepare("
                SELECT id, publication_type
                FROM works
                WHERE BINARY title = BINARY :title
                LIMIT 1
            ");
            $stmt->execute([
                ':title' => $title_main
            ]);
            $work = $stmt->fetch();

            if ($work) {
                $work_id = (int)$work['id'];

                // 作品側の publication_type が空の場合だけ補完
                if (empty($work['publication_type'])) {
                    $stmt = $pdo->prepare("
                        UPDATE works
                        SET publication_type = :publication_type
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':publication_type' => $publication_type,
                        ':id' => $work_id
                    ]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO works (
                        title,
                        publication_type
                    ) VALUES (
                        :title,
                        :publication_type
                    )
                ");
                $stmt->execute([
                    ':title' => $title_main,
                    ':publication_type' => $publication_type
                ]);
                $work_id = (int)$pdo->lastInsertId();
            }

            // 目次データ更新
            $stmt = $pdo->prepare("
                UPDATE issue_contents
                SET
                    work_id = :work_id,
                    sort_order = :sort_order,
                    page_start = :page_start,
                    page_end = :page_end,
                    title_main = :title_main,
                    episode_title = :episode_title,
                    episode_no = :episode_no,
                    note = :note,
                    content_type = 'manga',
                    publication_type = :publication_type,
                    is_new_series = :is_new_series,
                    is_final_episode = :is_final_episode
                WHERE id = :id
            ");
            $stmt->execute([
                ':work_id' => $work_id,
                ':sort_order' => ($sort_order !== '') ? (int)$sort_order : 999999,
                ':page_start' => ($page_start !== '') ? (int)$page_start : null,
                ':page_end' => ($page_end !== '') ? (int)$page_end : null,
                ':title_main' => $title_main,
                ':episode_title' => ($episode_title !== '') ? $episode_title : null,
                ':episode_no' => ($episode_no !== '') ? $episode_no : null,
                ':note' => ($note !== '') ? $note : null,
                ':publication_type' => $publication_type,
                ':is_new_series' => $is_new_series,
                ':is_final_episode' => $is_final_episode,
                ':id' => $content_id
            ]);

            // 行IDを維持し、掲載時の表示名・所属には触れない。
            $stored_by_id = [];
            foreach ($stored_creator_rows as $stored_row) {
                $stored_by_id[(int)$stored_row['id']] = $stored_row;
            }
            $kept_ids = [];
            $update_creator = $pdo->prepare('
                UPDATE content_creators
                SET creator_id = ?, role_id = ?, role = ?, display_order = ?, is_visible = ?
                WHERE id = ? AND content_id = ?
            ');
            $check_duplicate = $pdo->prepare('
                SELECT id FROM content_creators
                WHERE content_id = ? AND creator_id = ? AND role = ? AND id <> ? LIMIT 1
            ');
            // 明示的にフォームから削除された行だけを削除。
            $submitted_ids = array_column($creator_rows, 'id');
            $delete_creator = $pdo->prepare('DELETE FROM content_creators WHERE id = ? AND content_id = ?');
            foreach ($stored_by_id as $id => $stored_row) {
                if (!in_array($id, $submitted_ids, true)) {
                    $delete_creator->execute([$id, $content_id]);
                }
            }
            foreach ($creator_rows as $creator_row) {
                $id = $creator_row['id'];
                if ($id < 0 || ($id > 0 && (!isset($stored_by_id[$id]) || isset($kept_ids[$id])))) {
                    throw new Exception('人物行の指定が不正です。');
                }
                $old = $id > 0 ? $stored_by_id[$id] : null;
                $creator_id = $old && $old['creator_name'] === $creator_row['creator_name']
                    ? (int)$old['creator_id'] : resolve_creator_id($pdo, $creator_row['creator_name']);
                $role_id = $old && $old['role'] === $creator_row['role']
                    ? $old['role_id'] : $creator_writer->findRoleId($creator_row['role']);
                if ($id > 0) {
                    // 既存重複は保持するが、人物・役割の変更で重複を増やさない。
                    if ((int)$old['creator_id'] !== $creator_id || $old['role'] !== $creator_row['role']) {
                        $check_duplicate->execute([$content_id, $creator_id, $creator_row['role'], $id]);
                        if ($check_duplicate->fetchColumn() !== false) {
                            throw new Exception('同じ人物・役割がすでに登録されています。');
                        }
                    }
                    $update_creator->execute([
                        $creator_id, $role_id, $creator_row['role'], $creator_row['display_order'],
                        $creator_row['is_visible'], $id, $content_id,
                    ]);
                    $kept_ids[$id] = true;
                } else {
                    $creator_writer->insertIfMissing(
                        $content_id, $creator_id, $role_id, $creator_row['role'],
                        $creator_row['display_order'], $creator_row['is_visible']
                    );
                }
            }
            // 作品変更でも自動置換しない。明示操作時だけ不足分を追加。
            if (isset($_POST['import_work_creators'])) {
                $creator_writer->syncWorkCreatorsToContentCreators($work_id, $content_id);
            }

            // 開始ページ順で掲載順を振り直す
            renumber_issue_contents($pdo, $issue_id);

            $pdo->commit();

            header('Location: content_edit.php?id=' . $content_id . '&updated=1');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = '目次データを更新しました。';
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '目次データを登録しました。開始ページ順で掲載順を自動更新しました。';
}

// 同じ雑誌号の登録済み目次
$stmt = $pdo->prepare("
    SELECT
        ic.id,
        ic.sort_order,
        ic.page_start,
        ic.title_main,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode,
        GROUP_CONCAT(c.name ORDER BY cc.display_order SEPARATOR '、') AS creator_names
    FROM issue_contents ic
    LEFT JOIN content_creators cc ON ic.id = cc.content_id
    LEFT JOIN creators c ON cc.creator_id = c.id
    WHERE ic.issue_id = :issue_id
    GROUP BY
        ic.id,
        ic.sort_order,
        ic.page_start,
        ic.title_main,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode
    ORDER BY
        ic.sort_order ASC,
        CASE
            WHEN ic.page_start IS NULL THEN 1
            ELSE 0
        END ASC,
        ic.page_start ASC,
        ic.id ASC
");
$stmt->execute([
    ':issue_id' => $issue_id
]);
$existing_contents = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>目次編集 - INDEXINDEX</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 900px;
        }

        h2 {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 20px;
        }

        .selected-issue {
            padding: 12px;
            margin-bottom: 16px;
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 6px;
            line-height: 1.7;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .check-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 10px 0 2px;
        }

        .check-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: bold;
            cursor: pointer;
        }

        .check-label input {
            width: auto;
        }

        .creator-list {
            display: grid;
            gap: 8px;
        }

        .creator-row {
            display: grid;
            grid-template-columns: auto 1fr 2fr 90px auto;
            gap: 8px;
            align-items: center;
        }

        .creator-row input {
            margin: 0;
        }

        .creator-visible {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            font-weight: bold;
        }

        .creator-visible input {
            width: auto;
        }

        .creator-remove {
            padding: 8px 12px;
            white-space: nowrap;
        }

        .creator-add {
            margin-top: 10px;
        }

        textarea {
            min-height: 90px;
        }

        .existing-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .existing-table th,
        .existing-table td {
            border: 1px solid #ddd;
            padding: 8px;
            font-size: 13px;
            vertical-align: top;
        }

        .existing-table th {
            background: #f0f0f0;
        }

        .existing-table .current {
            background: #fff8e1;
        }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            background: #eee;
            font-size: 12px;
            margin-right: 4px;
            margin-bottom: 3px;
        }

        .badge-strong {
            background: #222;
            color: #fff;
        }

        @media (max-width: 700px) {
            .form-grid {
                display: block;
            }

            .creator-row {
                grid-template-columns: 1fr;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 6px;
                background: #fafafa;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const oneShot = document.getElementById('is_one_shot');
            const guestPublication = document.getElementById('is_guest_publication');

            if (oneShot && guestPublication) {
                oneShot.addEventListener('change', function () {
                    if (oneShot.checked) {
                        guestPublication.checked = false;
                    }
                });

                guestPublication.addEventListener('change', function () {
                    if (guestPublication.checked) {
                        oneShot.checked = false;
                    }
                });
            }

            const creatorList = document.getElementById('creatorList');
            const addCreatorButton = document.getElementById('addCreatorButton');
            let creatorRowIndex = creatorList ? creatorList.querySelectorAll('.creator-row').length : 0;

            function createCreatorInput(name, value, placeholder, type) {
                const input = document.createElement('input');
                input.type = type || 'text';
                input.name = name;
                input.value = value || '';
                input.placeholder = placeholder || '';
                return input;
            }

            function addCreatorRow(row) {
                if (!creatorList) {
                    return;
                }

                const index = creatorRowIndex;
                creatorRowIndex++;

                const wrap = document.createElement('div');
                wrap.className = 'creator-row';

                const visibleLabel = document.createElement('label');
                visibleLabel.className = 'creator-visible';
                const visibleHidden = createCreatorInput('creators[' + index + '][is_visible]', '0', '', 'hidden');
                const visibleCheckbox = createCreatorInput('creators[' + index + '][is_visible]', '1', '', 'checkbox');
                visibleCheckbox.checked = row.is_visible !== '0' && row.is_visible !== 0;
                visibleLabel.appendChild(visibleHidden);
                visibleLabel.appendChild(visibleCheckbox);
                visibleLabel.appendChild(document.createTextNode('表示'));
                wrap.appendChild(visibleLabel);

                wrap.appendChild(createCreatorInput('creators[' + index + '][role]', row.role || '', '役割'));
                wrap.appendChild(createCreatorInput('creators[' + index + '][creator_name]', row.creator_name || '', '作者名'));
                wrap.appendChild(createCreatorInput('creators[' + index + '][display_order]', row.display_order || String(index + 1), '表示順', 'number'));

                const roleId = createCreatorInput('creators[' + index + '][role_id]', row.role_id || '', '', 'hidden');
                wrap.appendChild(roleId);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'creator-remove';
                removeButton.textContent = '削除';
                removeButton.addEventListener('click', function () {
                    wrap.remove();
                });
                wrap.appendChild(removeButton);

                creatorList.appendChild(wrap);
            }

            if (creatorList) {
                creatorList.addEventListener('click', function (event) {
                    if (event.target.classList.contains('creator-remove')) {
                        event.preventDefault();
                        event.target.closest('.creator-row').remove();
                    }
                });
            }

            if (addCreatorButton) {
                addCreatorButton.addEventListener('click', function () {
                    addCreatorRow({});
                });
            }
        });
    </script>
</head>
<body>
<div class="container">

    <h1>目次編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="content_add.php?issue_id=<?= h($issue_id) ?>">目次登録</a>
        <a href="content_bulk_add.php?issue_id=<?= h($issue_id) ?>">目次一括登録</a>
        <a href="content_list.php?issue_id=<?= h($issue_id) ?>">目次一覧</a>
        <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="selected-issue">
        <strong>対象雑誌号：</strong>
        <?= h($content['magazine_name']) ?>
        <?= h($content['issue_label']) ?>
        /
        <?= h($content['publisher_name']) ?>
        <?php if (!empty($content['issue_no'])): ?>
            /
            <?= h($content['issue_no']) ?>号
        <?php endif; ?>
        <?php if (!empty($content['release_date'])): ?>
            /
            <?= h($content['release_date']) ?>
        <?php endif; ?>
    </div>

    <form method="post" action="content_edit.php?id=<?= h($content_id) ?>">

        <div class="form-grid">
            <div class="form-row">
                <label>掲載順</label>
                <input type="number" name="sort_order" value="<?= h($sort_order) ?>" placeholder="自動採番">
                <div class="help">
                    更新後、開始ページ順で自動採番します。
                </div>
            </div>

            <div class="form-row">
                <label>話数</label>
                <input type="text" name="episode_no" value="<?= h($episode_no) ?>" placeholder="例：1">
            </div>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>開始ページ</label>
                <input type="number" name="page_start" value="<?= h($page_start) ?>" placeholder="例：3">
            </div>

            <div class="form-row">
                <label>終了ページ</label>
                <input type="number" name="page_end" value="<?= h($page_end) ?>" placeholder="例：22">
            </div>
        </div>

        <div class="form-row">
            <label>
                タイトル <span class="required">必須</span>
            </label>
            <input type="text" name="title_main" value="<?= h($title_main) ?>" placeholder="例：サンプル漫画A">
        </div>

        <div class="form-row">
            <label>エピソードタイトル</label>
            <input type="text" name="episode_title" value="<?= h($episode_title) ?>" placeholder="例：冒険の始まり">
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>掲載区分</label>
                <div class="check-row">
                    <label class="check-label">
                        <input
                            type="checkbox"
                            id="is_one_shot"
                            name="is_one_shot"
                            value="1"
                            <?= ((int)$is_one_shot === 1) ? 'checked' : '' ?>
                        >
                        読切
                    </label>

                    <label class="check-label">
                        <input
                            type="checkbox"
                            id="is_guest_publication"
                            name="is_guest_publication"
                            value="1"
                            <?= ((int)$is_guest_publication === 1) ? 'checked' : '' ?>
                        >
                        出張掲載
                    </label>
                </div>
                <div class="help">
                    未チェックの場合は「連載」として保存します。読切と出張掲載は同時選択できません。
                </div>
            </div>

            <div class="form-row">
                <label>連載状態フラグ</label>
                <div class="check-row">
                    <label class="check-label">
                        <input
                            type="checkbox"
                            name="is_new_series"
                            value="1"
                            <?= ((int)$is_new_series === 1) ? 'checked' : '' ?>
                        >
                        新連載
                    </label>

                    <label class="check-label">
                        <input
                            type="checkbox"
                            name="is_final_episode"
                            value="1"
                            <?= ((int)$is_final_episode === 1) ? 'checked' : '' ?>
                        >
                        最終回
                    </label>
                </div>
                <div class="help">
                    必要な場合だけチェックしてください。
                </div>
            </div>
        </div>

        <div class="form-row">
            <label>
                作者
            </label>
            <div class="creator-list" id="creatorList">
                <input type="hidden" name="creator_revision" value="<?= h($_POST['creator_revision'] ?? $creator_revision) ?>">
                <?php foreach ($creator_rows as $index => $creator_row): ?>
                    <div class="creator-row">
                        <input type="hidden" name="creators[<?= h($index) ?>][id]" value="<?= h($creator_row['id'] ?? 0) ?>">
                        <label class="creator-visible">
                            <input
                                type="hidden"
                                name="creators[<?= h($index) ?>][is_visible]"
                                value="0"
                            >
                            <input
                                type="checkbox"
                                name="creators[<?= h($index) ?>][is_visible]"
                                value="1"
                                <?= ((int)($creator_row['is_visible'] ?? 1) === 1) ? 'checked' : '' ?>
                            >
                            表示
                        </label>
                        <input
                            type="text"
                            name="creators[<?= h($index) ?>][role]"
                            value="<?= h($creator_row['role'] ?? '') ?>"
                            placeholder="役割"
                        >
                        <input
                            type="text"
                            name="creators[<?= h($index) ?>][creator_name]"
                            value="<?= h($creator_row['creator_name'] ?? '') ?>"
                            placeholder="作者名"
                        >
                        <input
                            type="number"
                            name="creators[<?= h($index) ?>][display_order]"
                            value="<?= h($creator_row['display_order'] ?? ($index + 1)) ?>"
                            placeholder="表示順"
                        >
                        <input
                            type="hidden"
                            name="creators[<?= h($index) ?>][role_id]"
                            value="<?= h($creator_row['role_id'] ?? '') ?>"
                        >
                        <button type="button" class="creator-remove">削除</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="creator-add" id="addCreatorButton">＋ 作者を追加</button>
            <div class="help">
                作者名が空欄の行は保存されません。役割の空欄はそのまま保存します。
            </div>
            <label><input type="checkbox" name="import_work_creators" value="1" <?= isset($_POST['import_work_creators']) ? 'checked' : '' ?>> 保存時に作品の人物を不足分だけ追加する</label>
            <div class="help">作品を変更しても登録済みの人物・表示設定は保持します。追加する人物には作品の表示初期値を使用します。</div>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：巻頭カラー"><?= h($note) ?></textarea>
        </div>

        <button type="submit">更新する</button>

    </form>

    <h2>同じ雑誌号の登録済み目次</h2>

    <table class="existing-table">
        <thead>
            <tr>
                <th>順</th>
                <th>開始P</th>
                <th>タイトル</th>
                <th>作者</th>
                <th>区分</th>
                <th>フラグ</th>
                <th>編集</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($existing_contents as $row): ?>
                <tr class="<?= ((int)$row['id'] === (int)$content_id) ? 'current' : '' ?>">
                    <td><?= h($row['sort_order']) ?></td>
                    <td><?= h($row['page_start']) ?></td>
                    <td><?= h($row['title_main']) ?></td>
                    <td><?= h($row['creator_names']) ?></td>
                    <td>
                        <?php if (!empty($row['publication_type'])): ?>
                            <span class="badge"><?= h($row['publication_type']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$row['is_new_series'] === 1): ?>
                            <span class="badge badge-strong">新連載</span>
                        <?php endif; ?>

                        <?php if ((int)$row['is_final_episode'] === 1): ?>
                            <span class="badge badge-strong">最終回</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$row['id'] === (int)$content_id): ?>
                            編集中
                        <?php else: ?>
                            <a href="content_edit.php?id=<?= h($row['id']) ?>">編集</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</div>
</body>
</html>
