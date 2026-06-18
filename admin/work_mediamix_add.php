<?php
// admin/work_mediamix_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mediamix_type_labels() {
    return [
        'tv_anime' => 'TVアニメ',
        'anime_movie' => 'アニメ映画',
        'live_action_movie' => '実写映画',
        'tv_drama' => 'TVドラマ',
        'stage' => '舞台',
        'ova' => 'OVA',
        'web_anime' => 'Webアニメ',
        'game' => 'ゲーム',
        'other' => 'その他',
    ];
}

function works_search_columns($pdo) {
    $columns = ['title'];
    $stmt = $pdo->query("SHOW COLUMNS FROM works");
    foreach ($stmt->fetchAll() as $row) {
        $field = $row['Field'] ?? '';
        if (in_array($field, ['title_kana', 'title_search'], true)) {
            $columns[] = $field;
        }
    }
    return array_values(array_unique($columns));
}

function search_works($pdo, $keyword) {
    $keyword = trim((string)$keyword);
    if ($keyword === '') {
        return [];
    }

    $likes = [];
    foreach (works_search_columns($pdo) as $column) {
        $likes[] = "{$column} LIKE :keyword";
    }

    $stmt = $pdo->prepare("
        SELECT id, title
        FROM works
        WHERE " . implode(' OR ', $likes) . "
        ORDER BY title ASC, id ASC
        LIMIT 30
    ");
    $stmt->execute([':keyword' => '%' . $keyword . '%']);
    return $stmt->fetchAll();
}

$media_types = mediamix_type_labels();
$message = '';
$error = '';
$search_q = trim((string)($_GET['work_q'] ?? ($_POST['work_q'] ?? '')));
$work_results = search_works($pdo, $search_q);

$work_id = 0;
$selected_work_title = '';
$media_type = 'tv_anime';
$title = '';
$start_date = '';
$end_date = '';
$company = '';
$broadcaster = '';
$note = '';
$source_url = '';
$status = 'active';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_id = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;
    $media_type = trim((string)($_POST['media_type'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));
    $company = trim((string)($_POST['company'] ?? ''));
    $broadcaster = trim((string)($_POST['broadcaster'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $source_url = trim((string)($_POST['source_url'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'active'));

    $stmt = $pdo->prepare("SELECT title FROM works WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $work_id]);
    $selected_work = $stmt->fetch();
    $selected_work_title = $selected_work['title'] ?? '';

    if (!$selected_work) {
        $error = '作品を選択してください。';
    } elseif (!isset($media_types[$media_type])) {
        $error = '放送形態が不正です。';
    } elseif ($title === '') {
        $error = 'タイトルを入力してください。';
    } elseif (!in_array($status, ['active', 'hidden'], true)) {
        $error = '状態が不正です。';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO work_mediamix (
                    work_id, media_type, title, start_date, end_date, company, broadcaster, note, source_url, status
                ) VALUES (
                    :work_id, :media_type, :title, :start_date, :end_date, :company, :broadcaster, :note, :source_url, :status
                )
            ");
            $stmt->execute([
                ':work_id' => $work_id,
                ':media_type' => $media_type,
                ':title' => $title,
                ':start_date' => $start_date !== '' ? $start_date : null,
                ':end_date' => $end_date !== '' ? $end_date : null,
                ':company' => $company !== '' ? $company : null,
                ':broadcaster' => $broadcaster !== '' ? $broadcaster : null,
                ':note' => $note !== '' ? $note : null,
                ':source_url' => $source_url !== '' ? $source_url : null,
                ':status' => $status,
            ]);
            $message = 'メディアミックス情報を登録しました。ID: ' . (int)$pdo->lastInsertId();

            $work_id = 0;
            $selected_work_title = '';
            $media_type = 'tv_anime';
            $title = '';
            $start_date = '';
            $end_date = '';
            $company = '';
            $broadcaster = '';
            $note = '';
            $source_url = '';
            $status = 'active';
        } catch (Exception $e) {
            $error = '登録エラー: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>メディアミックス登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>.container { max-width: 950px; }</style>
</head>
<body>
<div class="container">
    <h1>メディアミックス登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="work_mediamix_list.php">メディアミックス一覧</a>
    </div>

    <?php if ($message !== ''): ?><div class="message"><?= h($message) ?><br><a href="work_mediamix_list.php">一覧へ</a></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <form method="get" action="work_mediamix_add.php" class="filter-form">
        <div class="filter-row">
            <div class="filter-item filter-item-wide">
                <label for="work_q">作品タイトル検索</label>
                <input type="text" id="work_q" name="work_q" value="<?= h($search_q) ?>" placeholder="作品名で検索">
            </div>
            <div class="filter-actions"><button type="submit">検索</button></div>
        </div>
    </form>

    <?php if ($search_q !== ''): ?>
        <table>
            <thead><tr><th>work_id</th><th>作品名</th><th>選択</th></tr></thead>
            <tbody>
            <?php foreach ($work_results as $row): ?>
                <tr>
                    <td><?= h($row['id']) ?></td>
                    <td><?= h($row['title']) ?></td>
                    <td><button type="button" class="select-work" data-id="<?= h($row['id']) ?>" data-title="<?= h($row['title']) ?>">この作品を選択</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($work_results)): ?><p>該当作品はありません。</p><?php endif; ?>
    <?php endif; ?>

    <form method="post" action="work_mediamix_add.php">
        <input type="hidden" name="work_q" value="<?= h($search_q) ?>">
        <input type="hidden" id="work_id" name="work_id" value="<?= h($work_id) ?>">

        <div class="info">
            選択中の作品:
            <span id="selected_work_title"><?= $selected_work_title !== '' ? h($selected_work_title) : '未選択' ?></span><br>
            work_id: <span id="selected_work_id"><?= $work_id > 0 ? h($work_id) : '-' ?></span>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>放送形態 <span class="required">必須</span></label>
                <select name="media_type">
                    <?php foreach ($media_types as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $media_type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>状態</label>
                <select name="status">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>active</option>
                    <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>hidden</option>
                </select>
            </div>
        </div>

        <div class="form-row"><label>タイトル <span class="required">必須</span></label><input type="text" name="title" value="<?= h($title) ?>"></div>
        <div class="form-grid">
            <div class="form-row"><label>開始日</label><input type="date" name="start_date" value="<?= h($start_date) ?>"></div>
            <div class="form-row"><label>終了日</label><input type="date" name="end_date" value="<?= h($end_date) ?>"></div>
        </div>
        <div class="form-row"><label>放送局・配信・公開元</label><input type="text" name="broadcaster" value="<?= h($broadcaster) ?>"></div>
        <div class="form-row"><label>制作会社</label><input type="text" name="company" value="<?= h($company) ?>"></div>
        <div class="form-row"><label>備考</label><textarea name="note"><?= h($note) ?></textarea></div>
        <div class="form-row"><label>source_url</label><input type="text" name="source_url" value="<?= h($source_url) ?>"></div>

        <button type="submit">登録する</button>
    </form>
</div>

<script>
document.querySelectorAll('.select-work').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('work_id').value = button.dataset.id;
        document.getElementById('selected_work_id').textContent = button.dataset.id;
        document.getElementById('selected_work_title').textContent = button.dataset.title;
    });
});
</script>
</body>
</html>
