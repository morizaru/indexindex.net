<?php
// admin/creator_edit.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_work_title_by_id(PDO $pdo, $work_id) {
    $stmt = $pdo->prepare("
        SELECT title
        FROM works
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $work_id
    ]);

    return $stmt->fetchColumn();
}

$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $creator_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($creator_id <= 0) {
    exit('人物IDが指定されていません。');
}

$message = '';
$error = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '人物を保存しました。';
}

// 現在データ取得
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.name_kana,
        c.name_search,
        c.debut_work_id,
        w.title AS debut_work_title,
        c.created_at
    FROM creators c
    LEFT JOIN works w ON c.debut_work_id = w.id
    WHERE c.id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $creator_id
]);
$creator = $stmt->fetch();

if (!$creator) {
    exit('指定された人物が見つかりません。');
}

$name = $creator['name'];
$name_kana = $creator['name_kana'] ?? '';
$name_search = $creator['name_search'] ?? '';
$debut_work_id = $creator['debut_work_id'] ?? '';
$debut_work_title = $creator['debut_work_title'] ?? '';

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $name_kana = trim($_POST['name_kana'] ?? '');
    $name_search = trim($_POST['name_search'] ?? '');
    $debut_work_id_raw = trim($_POST['debut_work_id'] ?? '');
    $debut_work_id = $debut_work_id_raw;
    $debut_work_title = '';

    if ($name === '') {
        $error = '人物名を入力してください。';
    } elseif (mb_strlen($name_search, 'UTF-8') > 255) {
        $error = '検索用表記ゆれは255文字以内で入力してください。';
    } elseif ($debut_work_id_raw !== '' && !preg_match('/^[0-9]+$/', $debut_work_id_raw)) {
        $error = 'デビュー作IDは数値で入力してください。';
    } else {
        try {
            $debut_work_id_value = null;
            if ($debut_work_id_raw !== '') {
                $debut_work_id_value = (int)$debut_work_id_raw;
                $debut_work_title = get_work_title_by_id($pdo, $debut_work_id_value);

                if ($debut_work_title === false) {
                    $error = '指定されたデビュー作IDの作品が見つかりません。';
                }
            }

            // 重複チェック
            if ($error === '') {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM creators
                    WHERE name = :name
                      AND id <> :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':id' => $creator_id
                ]);
                $exists = $stmt->fetch();

                if ($exists) {
                    $error = '同じ名前の人物がすでに登録されています。';
                }
            }

            if ($error === '') {
                $stmt = $pdo->prepare("
                    UPDATE creators
                    SET
                        name = :name,
                        name_kana = :name_kana,
                        name_search = :name_search,
                        debut_work_id = :debut_work_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':name_kana' => ($name_kana !== '') ? $name_kana : null,
                    ':name_search' => ($name_search !== '') ? $name_search : null,
                    ':debut_work_id' => $debut_work_id_value,
                    ':id' => $creator_id
                ]);

                header('Location: creator_edit.php?id=' . $creator_id . '&saved=1');
                exit;
            }
        } catch (Exception $e) {
            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 使用中の目次一覧
$stmt = $pdo->prepare("
    SELECT
        ic.id AS content_id,
        ic.issue_id,
        ic.title_main,
        ic.episode_no,
        ic.episode_title,
        ic.sort_order,
        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name,
        COALESCE(r.name, cc.role) AS role_name
    FROM content_creators cc
    JOIN issue_contents ic ON cc.content_id = ic.id
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN roles r ON cc.role_id = r.id
    WHERE cc.creator_id = :creator_id
    ORDER BY
        mi.issue_year ASC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+$' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END ASC,
        mi.id ASC,
        ic.sort_order ASC
");
$stmt->execute([
    ':creator_id' => $creator_id
]);
$contents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT DISTINCT
        w.id,
        w.title,
        w.title_sub
    FROM content_creators cc
    JOIN issue_contents ic ON cc.content_id = ic.id
    JOIN works w ON ic.work_id = w.id
    WHERE cc.creator_id = :creator_id
    ORDER BY w.title ASC, w.title_sub ASC, w.id ASC
");
$stmt->execute([
    ':creator_id' => $creator_id
]);
$work_options = $stmt->fetchAll();

if ($debut_work_id !== '') {
    $has_debut_work_option = false;
    foreach ($work_options as $work_option) {
        if ((int)$work_option['id'] === (int)$debut_work_id) {
            $has_debut_work_option = true;
            break;
        }
    }

    if (!$has_debut_work_option && $debut_work_title !== '' && $debut_work_title !== false) {
        array_unshift($work_options, [
            'id' => $debut_work_id,
            'title' => $debut_work_title,
            'title_sub' => '',
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>人物編集 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 1000px;
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

        .work-select-tools {
            display: grid;
            gap: 6px;
            max-width: 520px;
        }

        .role {
            width: 100px;
        }

        .order {
            width: 70px;
            text-align: center;
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
                width: 100px;
                color: #555;
            }

            td.issue::before { content: "雑誌・号"; }
            td.order::before { content: "掲載順"; }
            td.title::before { content: "作品"; }
            td.role::before { content: "役割"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>人物編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="creators.php">人物一覧</a>
        <a href="creator_add.php">人物登録</a>
        <a href="../creator.php?creator_id=<?= h($creator_id) ?>" target="_blank">公開ページ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="creators.php">人物一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="info">
        ID：<?= h($creator['id']) ?><br>
        作成日時：<?= h($creator['created_at']) ?>
    </div>

    <form method="post" action="creator_edit.php?id=<?= h($creator_id) ?>">

        <input type="hidden" name="id" value="<?= h($creator_id) ?>">

        <div class="form-row">
            <label>
                人物名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：ちばてつや">
        </div>

        <div class="form-row">
            <label>人物名かな</label>
            <input type="text" name="name_kana" value="<?= h($name_kana) ?>" placeholder="例：ちばてつや / チバテツヤ">
            <div class="help">任意。検索・並び替え用に使えます。</div>
        </div>

        <div class="form-row">
            <label>検索用表記ゆれ</label>
            <input type="text" name="name_search" value="<?= h($name_search) ?>" placeholder="例：髙橋よしひろ / 渡邉まさこ 渡邊まさこ">
            <div class="help">任意。表示名とは別に、検索で拾いたい表記をスペース区切りで入力します。</div>
        </div>

        <div class="form-row">
            <label>デビュー作</label>
            <div class="work-select-tools">
                <input type="search" class="work-search-input" placeholder="この人物に紐づく作品名で絞り込み">
                <select name="debut_work_id" class="work-select">
                    <option value="">未登録</option>
                    <?php foreach ($work_options as $work_option): ?>
                        <?php
                            $work_label = (string)$work_option['title'];
                            if (!empty($work_option['title_sub'])) {
                                $work_label .= ' / ' . (string)$work_option['title_sub'];
                            }
                        ?>
                        <option
                            value="<?= h($work_option['id']) ?>"
                            data-search="<?= h($work_option['id'] . ' ' . $work_label) ?>"
                            <?= ((string)$debut_work_id === (string)$work_option['id']) ? 'selected' : '' ?>
                        >
                            <?= h($work_label) ?>（ID: <?= h($work_option['id']) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="help">この人物に紐づく作品から選択できます。未登録にする場合は「未登録」を選んでください。</div>
            <div class="small">
                現在のデビュー作：
                <?php if (trim((string)$debut_work_id) === ''): ?>
                    未登録
                <?php elseif ($debut_work_title !== '' && $debut_work_title !== false): ?>
                    <?= h($debut_work_title) ?>
                <?php else: ?>
                    作品が見つかりません
                <?php endif; ?>
            </div>
        </div>

        <button type="submit">更新する</button>

    </form>

    <h2>この人物が使われている目次</h2>

    <?php if (empty($contents)): ?>

        <p class="empty">この人物に紐づく目次データはありません。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>雑誌・号</th>
                    <th class="order">掲載順</th>
                    <th>作品</th>
                    <th class="role">役割</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
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

                    <td class="title">
                        <?= h($row['title_main']) ?>
                        <?php if (!empty($row['episode_no']) || !empty($row['episode_title'])): ?>
                            <div class="small">
                                <?= h($row['episode_no'] ?? '') ?>
                                <?php if (!empty($row['episode_title'])): ?>
                                    / <?= h($row['episode_title']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="role"><?= h($row['role_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
<script>
document.querySelectorAll('.work-select-tools').forEach((wrap) => {
    const input = wrap.querySelector('.work-search-input');
    const select = wrap.querySelector('.work-select');
    const options = Array.from(select.options);

    if (!input || !select) {
        return;
    }

    input.addEventListener('input', () => {
        const keyword = input.value.trim().toLowerCase();

        options.forEach((option) => {
            if (option.value === '') {
                option.hidden = false;
                option.disabled = false;
                option.style.display = '';
                return;
            }

            const isHidden = keyword !== '' && !option.dataset.search.toLowerCase().includes(keyword);
            option.hidden = isHidden;
            option.disabled = isHidden;
            option.style.display = isHidden ? 'none' : '';
        });
    });
});
</script>
</body>
</html>
