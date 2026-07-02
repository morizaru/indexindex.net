<?php
// mediamix.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('メディアミックス一覧');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mediamix_type_labels() {
    return [
        'tv_anime' => 'TVアニメ',
        'anime_movie' => 'アニメ映画',
        'web_anime' => 'Webアニメ',
        'tv_drama' => 'TVドラマ',
        'live_action_movie' => '実写映画',
        'web_drama' => 'WEBドラマ',
        'stage' => '舞台',
        'ova' => 'OVA',
        'game' => 'ゲーム',
        'other' => 'その他',
    ];
}

function mediamix_type_label($type) {
    $labels = mediamix_type_labels();
    return $labels[$type] ?? $type;
}

function mediamix_url($type, $year) {
    $query = [];
    if ($type !== '') {
        $query['type'] = $type;
    }
    if ($year !== '') {
        $query['year'] = $year;
    }
    $query_string = http_build_query($query);
    return 'mediamix.php' . ($query_string !== '' ? '?' . $query_string : '');
}

$media_types = mediamix_type_labels();
$type = trim((string)($_GET['type'] ?? ''));
$current_year = date('Y');
$year = array_key_exists('year', $_GET) ? trim((string)$_GET['year']) : $current_year;

if (!isset($media_types[$type])) {
    $type = '';
}

if ($year !== '' && !preg_match('/^[0-9]{4}$/', $year)) {
    $year = $current_year;
}

$stmt = $pdo->query("
    SELECT DISTINCT YEAR(start_date) AS start_year
    FROM work_mediamix
    WHERE status = 'active'
      AND start_date IS NOT NULL
    ORDER BY start_year DESC
");
$years = $stmt->fetchAll();

$where = ["wm.status = 'active'"];
$params = [];

if ($type !== '') {
    $where[] = "wm.media_type = :type";
    $params[':type'] = $type;
}

if ($year !== '') {
    $where[] = "YEAR(wm.start_date) = :year";
    $params[':year'] = (int)$year;
}

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
        wm.note,
        wm.source_url,
        w.title AS work_title
    FROM work_mediamix wm
    JOIN works w ON wm.work_id = w.id
    WHERE " . implode(' AND ', $where) . "
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
    <title>メディアミックス - INDEXINDEX</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 24px;
            background: #f5f5f5;
            color: #222;
            line-height: 1.6;
        }
        .container {
            max-width: 1148px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-sizing: border-box;
        }
        h1 { margin-top: 0; }
        a { color: #0066cc; font-weight: bold; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .tab-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 16px 0;
        }
        .tab-nav a,
        .tab-nav span {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }
        .tab-nav .active {
            background: #333;
            border-color: #333;
            color: #fff;
        }
        .filter-form {
            margin: 16px 0;
            padding: 14px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
        }
        .filter-form select,
        .filter-form button {
            padding: 9px 12px;
            font-size: 16px;
        }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 10px; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #eee; text-align: left; }
        .small { color: #666; font-size: 13px; line-height: 1.5; }
        .empty { color: #666; padding: 16px; background: #fafafa; border: 1px solid #e5e5e5; border-radius: 6px; }
        @media (max-width: 800px) {
            body { padding: 8px; }
            .container { padding: 12px; }
            table, thead, tbody, tr, th, td { display: block; }
            thead { display: none; }
            tr { border: 1px solid #ddd; margin-bottom: 12px; padding: 8px; }
            td { border: none; padding: 4px 0; }
            td::before { font-weight: bold; display: inline-block; width: 120px; color: #555; }
            td.media-type::before { content: "放送形態"; }
            td.work-title::before { content: "作品名"; }
            td.media-title::before { content: "タイトル"; }
            td.start-date::before { content: "開始日"; }
            td.end-date::before { content: "終了日"; }
            td.broadcaster::before { content: "放送局等"; }
            td.company::before { content: "制作会社"; }
            td.note-cell::before { content: "備考"; }
            td.source-url::before { content: "出典"; }
        }
    </style>
</head>
<body id="top">
<?php
$current_page = 'mediamix';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <h1>メディアミックス</h1>

    <div class="tab-nav">
        <a href="<?= h(mediamix_url('', $year)) ?>" class="<?= $type === '' ? 'active' : '' ?>">すべて</a>
        <?php foreach ($media_types as $key => $label): ?>
            <a href="<?= h(mediamix_url($key, $year)) ?>" class="<?= $type === $key ? 'active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="mediamix.php" class="filter-form">
        <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php endif; ?>
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
        <button type="submit">絞り込み</button>
        <a href="<?= h(mediamix_url($type, '')) ?>">年リセット</a>
    </form>

    <?php if (empty($rows)): ?>
        <p class="empty">メディアミックス情報はありません。</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>放送形態</th>
                    <th>作品名</th>
                    <th>タイトル</th>
                    <th>開始日</th>
                    <th>終了日</th>
                    <th>broadcaster</th>
                    <th>company</th>
                    <th>note</th>
                    <th>source_url</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="media-type"><?= h(mediamix_type_label($row['media_type'])) ?></td>
                    <td class="work-title"><a href="work.php?id=<?= h($row['work_id']) ?>"><?= h($row['work_title']) ?></a></td>
                    <td class="media-title"><?= h($row['title']) ?></td>
                    <td class="start-date"><?= h($row['start_date']) ?></td>
                    <td class="end-date"><?= h($row['end_date']) ?></td>
                    <td class="broadcaster"><?= h($row['broadcaster']) ?></td>
                    <td class="company"><?= h($row['company']) ?></td>
                    <td class="note-cell"><?= h($row['note']) ?></td>
                    <td class="source-url">
                        <?php if (!empty($row['source_url'])): ?>
                            <a href="<?= h($row['source_url']) ?>" target="_blank" rel="noopener">source</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
