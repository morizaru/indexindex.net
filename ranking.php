<?php
// ranking.php
// INDEXINDEX 公開アクセスランキング

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/ranking_cache.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('DB接続エラー: app/config.php で $pdo が作成されていません。');
}

$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('アクセスランキング');

date_default_timezone_set('Asia/Tokyo');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        throw new PDOException('Ranking query failed');
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function access_period_condition(?string $from, ?string $to): string {
    if ($from === null || $to === null) {
        return '';
    }

    return ' AND al.access_time BETWEEN :from AND :to';
}

function access_period_params(?string $from, ?string $to): array {
    if ($from === null || $to === null) {
        return [];
    }

    return [
        ':from' => $from . ' 00:00:00',
        ':to' => $to . ' 23:59:59',
    ];
}

function build_magazine_ranking_sql(int $limit, string $period_condition): string {
    return "
        SELECT
            m.id,
            m.name AS name,
            p.name AS publisher_name,
            COUNT(*) AS pv,
            COUNT(DISTINCT r.visitor_hash) AS uu
        FROM (
            SELECT
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'magazine_id=', -1), '&', 1) AS UNSIGNED) AS magazine_id,
                al.visitor_hash
            FROM access_logs al
            WHERE al.site_key = 'indexindex'
              AND al.is_bot = 0
              {$period_condition}
              AND al.path = '/indexindex/issues.php'
              AND al.query_string REGEXP '(^|&)magazine_id=[0-9]+'

            UNION ALL

            SELECT
                mi.magazine_id,
                al.visitor_hash
            FROM access_logs al
            JOIN magazine_issues mi
              ON mi.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'id=', -1), '&', 1) AS UNSIGNED)
            WHERE al.site_key = 'indexindex'
              AND al.is_bot = 0
              {$period_condition}
              AND al.path = '/indexindex/issue.php'
              AND al.query_string REGEXP '(^|&)id=[0-9]+'
        ) r
        JOIN magazines m ON m.id = r.magazine_id
        LEFT JOIN publishers p ON p.id = m.publisher_id
        GROUP BY m.id, m.name, p.name
        ORDER BY pv DESC, uu DESC, m.name ASC
        LIMIT {$limit}
    ";
}

function build_creator_ranking_sql(int $limit, string $period_condition): string {
    return "
        SELECT
            c.id,
            c.name,
            COUNT(*) AS pv,
            COUNT(DISTINCT al.visitor_hash) AS uu
        FROM access_logs al
        JOIN creators c
          ON c.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'creator_id=', -1), '&', 1) AS UNSIGNED)
        WHERE al.site_key = 'indexindex'
          AND al.is_bot = 0
          {$period_condition}
          AND al.path = '/indexindex/creator.php'
          AND al.query_string REGEXP '(^|&)creator_id=[0-9]+'
        GROUP BY c.id, c.name
        ORDER BY pv DESC, uu DESC, c.name ASC
        LIMIT {$limit}
    ";
}

function build_work_ranking_sql(int $limit, string $period_condition): string {
    return "
        SELECT
            w.id,
            w.title AS name,
            COUNT(*) AS pv,
            COUNT(DISTINCT al.visitor_hash) AS uu
        FROM access_logs al
        JOIN works w
          ON w.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.query_string, 'work_id=', -1), '&', 1) AS UNSIGNED)
        WHERE al.site_key = 'indexindex'
          AND al.is_bot = 0
          {$period_condition}
          AND al.path IN ('/indexindex/work.php', '/indexindex/work_graph.php')
          AND al.query_string REGEXP '(^|&)work_id=[0-9]+'
        GROUP BY w.id, w.title
        ORDER BY pv DESC, uu DESC, w.title ASC
        LIMIT {$limit}
    ";
}

function fetch_ranking(PDO $pdo, string $type, ?string $from, ?string $to, int $limit): array {
    $condition = access_period_condition($from, $to);
    $params = access_period_params($from, $to);

    if ($type === 'works') {
        return fetch_all($pdo, build_work_ranking_sql($limit, $condition), $params);
    }

    if ($type === 'magazines') {
        return fetch_all($pdo, build_magazine_ranking_sql($limit, $condition), $params);
    }

    return fetch_all($pdo, build_creator_ranking_sql($limit, $condition), $params);
}

function fetch_ranking_safe(PDO $pdo, string $type, ?string $from, ?string $to, int $limit, bool &$allSucceeded): array {
    try {
        return fetch_ranking($pdo, $type, $from, $to, $limit);
    } catch (PDOException $e) {
        $allSucceeded = false;
        error_log('ranking.php ranking query failed: ' . $e->getMessage());
        return [];
    }
}

function rank_class(int $rank): string {
    if ($rank === 1) return 'rank rank-1';
    if ($rank === 2) return 'rank rank-2';
    if ($rank === 3) return 'rank rank-3';
    return 'rank';
}

function build_rank_map(array $rows): array {
    $map = [];
    foreach ($rows as $index => $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $index + 1;
        }
    }

    return $map;
}

function ranking_trend(array $rank_maps, string $type, string $period_key, array $row, int $current_rank): array {
    if ($period_key === 'total') {
        return ['mark' => '', 'class' => ''];
    }

    $id = (int)($row['id'] ?? 0);
    $previous_rank = $rank_maps[$type][$period_key][$id] ?? null;

    if ($id <= 0 || $previous_rank === null || $previous_rank === $current_rank) {
        return ['mark' => '-', 'class' => 'rank-same'];
    }

    if ($previous_rank > $current_rank) {
        return ['mark' => '▲', 'class' => 'rank-up'];
    }

    return ['mark' => '▼', 'class' => 'rank-down'];
}

function ranking_link(string $type, array $row): string {
    $id = (int)($row['id'] ?? 0);

    if ($type === 'works') {
        return 'work.php?work_id=' . $id;
    }

    if ($type === 'magazines') {
        return 'issues.php?magazine_id=' . $id;
    }

    return 'creator.php?creator_id=' . $id;
}

function ranking_sub_text(string $type, array $row): string {
    if ($type === 'works') {
        return '作品ID：' . (int)($row['id'] ?? 0);
    }

    if ($type === 'magazines') {
        $publisher = trim((string)($row['publisher_name'] ?? ''));
        return $publisher !== '' ? '出版社：' . $publisher : '雑誌ID：' . (int)($row['id'] ?? 0);
    }

    return '人物ID：' . (int)($row['id'] ?? 0);
}

// One Tokyo timestamp for both query periods and the cache key, including midnight.
$rankingDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
$today = $rankingDate->format('Y-m-d');
$monthStart = $rankingDate->format('Y-m-01');
$yesterday = $rankingDate->modify('-1 day')->format('Y-m-d');
$previousMonthBase = $rankingDate->modify('first day of previous month');
$previousMonthStart = $previousMonthBase->format('Y-m-01');
$previousMonthEnd = $previousMonthBase->format('Y-m-t');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, [10, 20, 50], true)) {
    $limit = 10;
}

$periods = [
    'total' => [
        'label' => 'TOTAL',
        'from' => null,
        'to' => null,
        'note' => '全期間',
    ],
    'month' => [
        'label' => '今月',
        'from' => $monthStart,
        'to' => $today,
        'note' => $monthStart . ' 〜 ' . $today,
    ],
    'today' => [
        'label' => '今日',
        'from' => $today,
        'to' => $today,
        'note' => $today,
    ],
];

$categories = [
    'works' => [
        'label' => '作品',
        'heading' => '作品アクセスランキング',
    ],
    'magazines' => [
        'label' => '雑誌',
        'heading' => '雑誌アクセスランキング',
    ],
    'creators' => [
        'label' => '人物',
        'heading' => '人物アクセスランキング',
    ],
];

$cacheKey = ranking_cache_key(__DIR__, $rankingDate, $limit);
$rankingData = ranking_cache_remember(dirname(__DIR__) . '/indexindex_cache', $cacheKey, $limit,
    function () use ($pdo, $categories, $periods, $previousMonthStart, $previousMonthEnd, $yesterday, $limit) {
        $rankings = [];
        $comparisonRankings = [];
        $allSucceeded = true;
        foreach ($categories as $type => $category) {
            foreach ($periods as $periodKey => $period) {
                $rankings[$type][$periodKey] = fetch_ranking_safe($pdo, $type, $period['from'], $period['to'], $limit, $allSucceeded);
            }
            $comparisonRankings[$type]['month'] = fetch_ranking_safe($pdo, $type, $previousMonthStart, $previousMonthEnd, $limit, $allSucceeded);
            $comparisonRankings[$type]['today'] = fetch_ranking_safe($pdo, $type, $yesterday, $yesterday, $limit, $allSucceeded);
        }
        return ['data' => ['rankings' => $rankings, 'comparisonRankings' => $comparisonRankings], 'cacheable' => $allSucceeded];
    }
);
$rankings = $rankingData['rankings'];
$comparisonRankings = $rankingData['comparisonRankings'];
$rankMaps = [];
foreach ($categories as $type => $category) {
    $rankMaps[$type]['month'] = build_rank_map($comparisonRankings[$type]['month']);
    $rankMaps[$type]['today'] = build_rank_map($comparisonRankings[$type]['today']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アクセスランキング - INDEXINDEX</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1148px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-sizing: border-box;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 8px;
        }

        h2 {
            margin: 0 0 14px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }

        h3 {
            margin: 0 0 4px;
            font-size: 18px;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .lead {
            color: #666;
            margin: 0 0 18px;
            line-height: 1.7;
        }

        .filter-form {
            margin: 20px 0 24px;
            padding: 16px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 130px auto;
            gap: 14px;
            align-items: end;
        }

        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .form-row select {
            width: 100%;
            box-sizing: border-box;
            padding: 9px 10px;
            font-size: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
        }

        .button-row button,
        .button-row a {
            display: inline-block;
            box-sizing: border-box;
            padding: 10px 16px;
            font-size: 15px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            height: 40px;
            line-height: 18px;
            font-weight: bold;
        }

        .button-row a {
            background: #777;
            border-color: #777;
            margin-left: 6px;
        }

        .ranking-section {
            margin-top: 30px;
            padding-top: 4px;
        }

        .ranking-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
        }

        .ranking-panel {
            min-width: 0;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            overflow: hidden;
        }

        .ranking-panel-head {
            padding: 12px 14px;
            background: #f7f7f7;
            border-bottom: 1px solid #e0e0e0;
        }

        .period-note {
            color: #777;
            font-size: 12px;
        }

        .ranking-list {
            display: grid;
        }

        .ranking-item {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 10px;
            align-items: start;
            padding: 12px 14px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }

        .ranking-item:nth-child(odd) {
            background: #fcfcfc;
        }

        .ranking-item:last-child {
            border-bottom: 0;
        }

        .rank-cell {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .rank {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #666;
            color: #fff;
            font-weight: 900;
            font-size: 15px;
        }

        .rank-1 { background: #b8860b; }
        .rank-2 { background: #6b7280; }
        .rank-3 { background: #a16207; }

        .rank-change {
            min-width: 28px;
            text-align: center;
            font-size: 13px;
            font-weight: 900;
            line-height: 1;
        }

        .rank-up {
            color: #c2410c;
            font-weight: 900;
        }

        .rank-down {
            color: #2563eb;
            font-weight: 900;
        }

        .rank-same {
            color: #777;
            font-weight: 700;
        }

        .title {
            min-width: 0;
        }

        .title-main {
            font-size: 15px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .title-sub {
            color: #666;
            font-size: 12px;
            margin-top: 3px;
        }

        .metrics {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .metric {
            white-space: nowrap;
        }

        .metric-num {
            font-size: 16px;
            font-weight: 900;
        }

        .metric-label {
            color: #777;
            font-size: 11px;
            margin-left: 3px;
        }

        .empty {
            color: #666;
            padding: 14px;
            background: #fafafa;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.6;
        }

        .top-button {
            position: fixed;
            right: 22px;
            bottom: 22px;
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #333, #111);
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 0.04em;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
            z-index: 1000;
        }

        .top-button.is-visible {
            opacity: 0.92;
            transform: translateY(0);
            pointer-events: auto;
        }

        .top-button:hover {
            opacity: 1;
            text-decoration: none;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3);
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            color: #888;
            font-size: 13px;
        }

        .footer a {
            color: #666;
            text-decoration: none;
            margin: 0 6px;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 980px) {
            .ranking-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .filter-grid {
                display: block;
            }

            .form-row {
                margin-bottom: 12px;
            }

            .button-row button,
            .button-row a {
                width: 100%;
                margin: 4px 0;
                text-align: center;
            }

            .top-button {
                right: 14px;
                bottom: 14px;
                width: 48px;
                height: 48px;
                font-size: 13px;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <link rel="stylesheet" href="assets/css/common.css">
</head>
<body id="top">

<?php
$current_page = 'ranking';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <h1>アクセスランキング</h1>
    <p class="lead">
        INDEXINDEX内でよく見られている作品・雑誌・人物のランキングです。<br>
        通常アクセスのみを集計し、botアクセスは除外しています。
    </p>

    <form method="get" action="ranking.php" class="filter-form">
        <div class="filter-grid">
            <div class="form-row">
                <label>表示件数</label>
                <select name="limit">
                    <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10件</option>
                    <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20件</option>
                    <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50件</option>
                </select>
            </div>

            <div class="form-row button-row">
                <button type="submit">表示する</button>
                <a href="ranking.php">リセット</a>
            </div>
        </div>
    </form>

    <div class="small">
        TOTAL：全期間 / 今月：<?= h($monthStart) ?> 〜 <?= h($today) ?> / 今日：<?= h($today) ?>
    </div>

    <?php foreach ($categories as $type => $category): ?>
        <section class="ranking-section">
            <h2><?= h($category['heading']) ?></h2>

            <div class="ranking-grid">
                <?php foreach ($periods as $periodKey => $period): ?>
                    <?php $rows = $rankings[$type][$periodKey] ?? []; ?>
                    <div class="ranking-panel">
                        <div class="ranking-panel-head">
                            <h3><?= h($period['label']) ?></h3>
                            <div class="period-note"><?= h($period['note']) ?></div>
                        </div>

                        <?php if (empty($rows)): ?>
                            <div class="empty">ランキングデータがありません。</div>
                        <?php else: ?>
                            <div class="ranking-list">
                                <?php foreach ($rows as $index => $row): ?>
                                    <?php
                                        $rank = $index + 1;
                                        $trend = ranking_trend($rankMaps, $type, $periodKey, $row, $rank);
                                    ?>
                                    <div class="ranking-item">
                                        <div class="rank-cell">
                                            <div class="<?= h(rank_class($rank)) ?>"><?= h($rank) ?></div>
                                            <?php if ($periodKey !== 'total'): ?>
                                                <div class="rank-change <?= h($trend['class']) ?>"><?= h($trend['mark']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="title">
                                            <div class="title-main">
                                                <a href="<?= h(ranking_link($type, $row)) ?>">
                                                    <?= h($row['name']) ?>
                                                </a>
                                            </div>
                                            <div class="title-sub"><?= h(ranking_sub_text($type, $row)) ?></div>
                                            <div class="metrics">
                                                <div class="metric">
                                                    <span class="metric-num"><?= number_format((int)$row['pv']) ?></span>
                                                    <span class="metric-label">PV</span>
                                                </div>
                                                <div class="metric">
                                                    <span class="metric-num"><?= number_format((int)$row['uu']) ?></span>
                                                    <span class="metric-label">UU</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<a href="#top" class="top-button" id="topButton" aria-label="TOPへ戻る">TOP</a>

<script>
const topButton = document.getElementById('topButton');

function toggleTopButton() {
    if (!topButton) {
        return;
    }

    if (window.scrollY > 240) {
        topButton.classList.add('is-visible');
    } else {
        topButton.classList.remove('is-visible');
    }
}

window.addEventListener('scroll', toggleTopButton, { passive: true });
toggleTopButton();
</script>

</body>
</html>
