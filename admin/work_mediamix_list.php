<?php
// admin/work_mediamix_list.php

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
        'web_drama' => 'WEBドラマ',
        'stage' => '舞台',
        'ova' => 'OVA',
        'web_anime' => 'Webアニメ',
        'game' => 'ゲーム',
        'other' => 'その他',
    ];
}

function mediamix_type_label($type) {
    $labels = mediamix_type_labels();
    return $labels[$type] ?? $type;
}

$media_types = mediamix_type_labels();
$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));

if (!isset($media_types[$type])) {
    $type = '';
}

if ($year !== '' && !preg_match('/^[0-9]{4}$/', $year)) {
    $year = '';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "w.title LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

if ($type !== '') {
    $where[] = "wm.media_type = :type";
    $params[':type'] = $type;
}

if ($year !== '') {
    $where[] = "YEAR(wm.start_date) = :year";
    $params[':year'] = (int)$year;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->query("
    SELECT DISTINCT YEAR(start_date) AS start_year
    FROM work_mediamix
    WHERE start_date IS NOT NULL
    ORDER BY start_year DESC
");
$years = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        wm.id,
        wm.work_id,
        wm.media_type,
        wm.title,
        wm.start_date,
        wm.end_date,
        wm.company,
        wm.broadcaster,
        wm.status,
        w.title AS work_title
    FROM work_mediamix wm
    JOIN works w ON wm.work_id = w.id
    {$where_sql}
    ORDER BY
        CASE WHEN wm.start_date IS NULL THEN 1 ELSE 0 END ASC,
        wm.start_date DESC,
        wm.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>メディアミックス一覧 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .container { max-width: 1200px; }
        .status-hidden { color: #777; }
    </style>
</head>
<body>
<div class="container">
    <h1>メディアミックス一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="work_mediamix_add.php">メディアミックス登録</a>
        <a href="../mediamix.php" target="_blank">公開ページ</a>
    </div>

    <form method="get" action="work_mediamix_list.php" class="filter-form">
        <div class="filter-row">
            <div class="filter-item filter-item-wide">
                <label for="q">作品名検索</label>
                <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="作品名">
            </div>
            <div class="filter-item">
                <label for="type">放送形態</label>
                <select id="type" name="type">
                    <option value="">すべて</option>
                    <?php foreach ($media_types as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item filter-item-year">
                <label for="year">開始年</label>
                <select id="year" name="year">
                    <option value="">すべて</option>
                    <?php foreach ($years as $row): ?>
                        <?php if (empty($row['start_year'])) continue; ?>
                        <option value="<?= h($row['start_year']) ?>" <?= $year === (string)$row['start_year'] ? 'selected' : '' ?>>
                            <?= h($row['start_year']) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit">検索</button>
                <a class="button-link" href="work_mediamix_list.php">リセット</a>
            </div>
        </div>
    </form>

    <div class="count"><?= h(count($rows)) ?>件</div>

    <?php if (empty($rows)): ?>
        <p>メディアミックス情報はありません。</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>作品名</th>
                    <th>放送形態</th>
                    <th>タイトル</th>
                    <th>開始日</th>
                    <th>終了日</th>
                    <th>放送局・配信・公開元</th>
                    <th>制作会社</th>
                    <th>状態</th>
                    <th class="actions">編集</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['id']) ?></td>
                    <td>
                        <a href="../work.php?work_id=<?= h($row['work_id']) ?>" target="_blank"><?= h($row['work_title']) ?></a>
                        <div class="small">work_id: <?= h($row['work_id']) ?></div>
                    </td>
                    <td><?= h(mediamix_type_label($row['media_type'])) ?></td>
                    <td><?= h($row['title']) ?></td>
                    <td><?= h($row['start_date']) ?></td>
                    <td><?= h($row['end_date']) ?></td>
                    <td><?= h($row['broadcaster']) ?></td>
                    <td><?= h($row['company']) ?></td>
                    <td class="<?= $row['status'] === 'hidden' ? 'status-hidden' : '' ?>"><?= h($row['status']) ?></td>
                    <td class="actions"><a class="button-link" href="work_mediamix_edit.php?id=<?= h($row['id']) ?>">編集</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
