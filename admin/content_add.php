<?php
// admin/content_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/content_creators.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function editable_issue_status_sql(): string {
    return "'approved', 'in_progress', 'verified'";
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

        $creator_name = mb_convert_kana(trim((string)($row['creator_name'] ?? '')), 'as', 'UTF-8');

        if ($creator_name === '') {
            continue;
        }

        $role = mb_convert_kana(trim((string)($row['role'] ?? '')), 'as', 'UTF-8');
        if ($role === '') {
            $role = 'author';
        }

        $display_order_raw = trim((string)($row['display_order'] ?? ''));
        if ($display_order_raw !== '' && preg_match('/^[0-9]+$/', $display_order_raw)) {
            $display_order = (int)$display_order_raw;
            if ($display_order <= 0) {
                $display_order = $auto_order;
            }
        } else {
            $display_order = $auto_order;
        }

        $role_id_raw = trim((string)($row['role_id'] ?? ''));
        $role_id = ($role_id_raw !== '' && preg_match('/^[0-9]+$/', $role_id_raw)) ? (int)$role_id_raw : null;

        $rows[] = array_merge(array_intersect_key($row, array_flip(ContentCreatorWriter::SNAPSHOT_FIELDS)), [
            'role_id' => $role_id,
            'role' => $role,
            'creator_name' => $creator_name,
            'display_order' => $display_order,
            'is_visible' => (string)($row['is_visible'] ?? '1') === '1' ? 1 : 0,
            'input_index' => $input_index,
        ]);

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

function make_empty_creator_rows() {
    return [
        [
            'role_id' => null,
            'role' => '',
            'display_order' => 1,
            'creator_name' => '',
        ],
    ];
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

$message = '';
$error = '';

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : '';
$sort_order = '';
$page_start = '';
$page_end = '';
$title_main = '';
$episode_title = '';
$episode_no = '';
$creator_rows = make_empty_creator_rows();
$note = '';

$is_one_shot = 0;
$is_guest_publication = 0;
$is_new_series = 0;
$is_final_episode = 0;
$publication_type = '連載';

// 雑誌号一覧を取得
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
    ORDER BY
        mi.issue_year DESC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+$' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END DESC,
        mi.id DESC
";
$stmt = $pdo->query($sql_issues);
$issues = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id       = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $sort_order     = trim($_POST['sort_order'] ?? '');
    $page_start     = trim($_POST['page_start'] ?? '');
    $page_end       = trim($_POST['page_end'] ?? '');
    $title_main     = trim($_POST['title_main'] ?? '');
    $episode_title  = trim($_POST['episode_title'] ?? '');
    $episode_no     = trim($_POST['episode_no'] ?? '');
    $creator_rows   = normalize_creator_rows($_POST['creators'] ?? []);
    $note           = trim($_POST['note'] ?? '');

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

    if ($issue_id <= 0) {
        $error = '雑誌号を選択してください。';
    } elseif ($title_main === '') {
        $error = 'タイトルを入力してください。';
    } elseif ($is_one_shot && $is_guest_publication) {
        $error = '読切と出張掲載は同時に選択できません。';
    } else {
        try {
            $pdo->beginTransaction();

            // 雑誌号存在チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM magazine_issues
                WHERE id = :id
                  AND status IN (" . editable_issue_status_sql() . ")
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $issue_id
            ]);
            $issue_exists = $stmt->fetch();

            if (!$issue_exists) {
                throw new Exception('選択した雑誌号が見つかりません。');
            }

            // 1. 作品を取得、なければ追加
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

            // 3. 目次データを追加
            // sort_order は後で自動採番するため仮値を入れる
            $stmt = $pdo->prepare("
                INSERT INTO issue_contents (
                    issue_id,
                    work_id,
                    sort_order,
                    page_start,
                    page_end,
                    title_main,
                    episode_title,
                    episode_no,
                    note,
                    content_type,
                    publication_type,
                    is_new_series,
                    is_final_episode
                ) VALUES (
                    :issue_id,
                    :work_id,
                    :sort_order,
                    :page_start,
                    :page_end,
                    :title_main,
                    :episode_title,
                    :episode_no,
                    :note,
                    'manga',
                    :publication_type,
                    :is_new_series,
                    :is_final_episode
                )
            ");

            $stmt->execute([
                ':issue_id'         => $issue_id,
                ':work_id'          => $work_id,
                ':sort_order'       => 999999,
                ':page_start'       => ($page_start !== '') ? (int)$page_start : null,
                ':page_end'         => ($page_end !== '') ? (int)$page_end : null,
                ':title_main'       => $title_main,
                ':episode_title'    => ($episode_title !== '') ? $episode_title : null,
                ':episode_no'       => ($episode_no !== '') ? $episode_no : null,
                ':note'             => ($note !== '') ? $note : null,
                ':publication_type' => $publication_type,
                ':is_new_series'    => $is_new_series,
                ':is_final_episode' => $is_final_episode,
            ]);

            $content_id = (int)$pdo->lastInsertId();

            // この新規目次への手入力を先に保存し、作品の人物は不足分だけコピーする。
            $creator_writer = new ContentCreatorWriter($pdo);
            // INSERT済みの新規目次は、このトランザクションがロックを保持している。

            foreach ($creator_rows as $creator_row) {
                $creator_id = resolve_creator_id($pdo, $creator_row['creator_name']);

                $inserted = $creator_writer->insertIfMissing(
                    $content_id, $creator_id,
                    $creator_writer->findRoleId($creator_row['role']),
                    $creator_row['role'], $creator_row['display_order'], $creator_row['is_visible'], $creator_row
                );
                if (!$inserted) { throw new RuntimeException('同じ人物・役割が重複しています。'); }
            }
            $creator_writer->syncWorkCreatorsToContentCreators($work_id, $content_id);

            // 5. 開始ページ順で掲載順を振り直す
            renumber_issue_contents($pdo, $issue_id);

            $pdo->commit();

            header('Location: content_edit.php?id=' . $content_id . '&saved=1');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '登録エラー：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>目次登録 - INDEXINDEX</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 900px;
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
            grid-template-columns: 1fr 2fr 90px auto;
            gap: 8px;
            align-items: center;
        }

        .creator-row input {
            margin: 0;
        }

        .creator-remove {
            padding: 8px 12px;
            white-space: nowrap;
        }

        .creator-add {
            margin-top: 10px;
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
    <link rel="stylesheet" href="content_creator_fields.css">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
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
                visibleLabel.appendChild(createCreatorInput('creators[' + index + '][is_visible]', '0', '', 'hidden'));
                const visibleCheckbox = createCreatorInput('creators[' + index + '][is_visible]', '1', '', 'checkbox');
                visibleCheckbox.checked = true;
                visibleLabel.appendChild(visibleCheckbox);
                visibleLabel.appendChild(document.createTextNode('表示'));
                wrap.appendChild(visibleLabel);

                wrap.appendChild(createCreatorInput('creators[' + index + '][role]', row.role || '', '役割'));
                wrap.appendChild(createCreatorInput('creators[' + index + '][creator_name]', row.creator_name || '', '作者名'));
                Object.entries({display_name: '表示名', display_title: '肩書', display_affiliation: '所属'}).forEach(([field, label]) => {
                    const input = createCreatorInput('creators[' + index + '][' + field + ']', row[field] || '', label);
                    input.className = 'creator-snapshot ' + field;
                    input.maxLength = 255;
                    input.setAttribute('aria-label', label);
                    wrap.appendChild(input);
                });
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

    <h1>目次登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="content_list.php">目次一覧</a>
        <a href="../issues.php">公開：雑誌号一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <?php if ((int)$issue_id > 0): ?>
                <a href="content_list.php?issue_id=<?= h($issue_id) ?>">この雑誌号の目次一覧を開く</a>
                <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="content_add.php">

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
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>掲載順</label>
                <input type="number" name="sort_order" value="<?= h($sort_order) ?>" placeholder="自動採番">
                <div class="help">
                    未入力でOKです。登録後、開始ページ順で自動採番します。<br>
                    開始ページ未入力の目次は、開始ページありの後ろに並びます。
                </div>
            </div>

            <div class="form-row">
                <label>話数</label>
                <input type="text" name="episode_no" value="<?= h($episode_no) ?>" placeholder="例：1">
                <div class="help">
                    表示形式は作品側の話数フォーマットで設定します。
                </div>
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
            <div class="help">このタイトルが作品マスタにも自動登録されます。</div>
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
                        <input type="checkbox" name="is_one_shot" value="1" <?= ((int)$is_one_shot === 1) ? 'checked' : '' ?>>
                        読切
                    </label>

                    <label class="check-label">
                        <input type="checkbox" name="is_guest_publication" value="1" <?= ((int)$is_guest_publication === 1) ? 'checked' : '' ?>>
                        出張掲載
                    </label>
                </div>
                <div class="help">
                    未チェックの場合は「連載」として登録します。読切と出張掲載は同時選択できません。
                </div>
            </div>

            <div class="form-row">
                <label>連載状態フラグ</label>
                <div class="check-row">
                    <label class="check-label">
                        <input type="checkbox" name="is_new_series" value="1" <?= ((int)$is_new_series === 1) ? 'checked' : '' ?>>
                        新連載
                    </label>

                    <label class="check-label">
                        <input type="checkbox" name="is_final_episode" value="1" <?= ((int)$is_final_episode === 1) ? 'checked' : '' ?>>
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
                <?php foreach ($creator_rows as $index => $creator_row): ?>
                    <div class="creator-row">
                        <label class="creator-visible">
                            <input type="hidden" name="creators[<?= h($index) ?>][is_visible]" value="0">
                            <input type="checkbox" name="creators[<?= h($index) ?>][is_visible]" value="1" <?= (int)($creator_row['is_visible'] ?? 1) === 1 ? 'checked' : '' ?>> 表示
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
                        <?php require __DIR__ . '/content_creator_snapshot_fields.php'; ?>
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
            <div class="help">作者名が空欄の行は保存されません。役割が空欄の場合は author として保存します。</div>
            <div class="help">表示名は人物マスターと異なる掲載表記がある場合に入力します。肩書・所属は別々に入力し、所属に外側の括弧は付けません。手入力した人物・役割を優先し、作品の標準人物は不足分だけ取り込みます。</div>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：巻頭カラー"><?= h($note) ?></textarea>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
