<?php
// access_analytics.php
// INDEXINDEX アクセス解析 管理画面
// 設置場所例: /indexindex/admin/access_analytics.php

// 追加内容：
// ・時間別アクセスグラフ
// ・曜日別アクセスグラフ
// ・デバイス別 棒グラフ
// ・ページ別アクセス 棒グラフ
// ・流入元別 棒グラフ
// ・botアクセス表示
// ・月別集計テーブル表示

// 前提テーブル：
// access_logs
// access_bot_daily
// access_monthly_summary

declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'DB接続に失敗しました。../app/config.php で $pdo が作成されているか確認してください。';
    exit;
}

date_default_timezone_set('Asia/Tokyo');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-29 days'));

$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}

$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$params = [
    ':from' => $fromDt,
    ':to' => $toDt,
];

$dateParams = [
    ':from_date' => $from,
    ':to_date' => $to,
];

$summary = ii_fetch_one($pdo, "
    SELECT
        COUNT(*) AS page_views,
        COUNT(DISTINCT visitor_session_id) AS visitors,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
", $params);

$dailyRows = ii_fetch_all($pdo, "
    SELECT
        DATE(access_time) AS access_date,
        COUNT(*) AS page_views,
        COUNT(DISTINCT visitor_session_id) AS visitors,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY DATE(access_time)
    ORDER BY access_date
", $params);

$hourRowsRaw = ii_fetch_all($pdo, "
    SELECT
        HOUR(access_time) AS access_hour,
        COUNT(*) AS page_views,
        COUNT(DISTINCT visitor_session_id) AS visitors,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY HOUR(access_time)
    ORDER BY access_hour
", $params);

$weekdayRowsRaw = ii_fetch_all($pdo, "
    SELECT
        WEEKDAY(access_time) AS weekday_no,
        COUNT(*) AS page_views,
        COUNT(DISTINCT visitor_session_id) AS visitors,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY WEEKDAY(access_time)
    ORDER BY weekday_no
", $params);

$deviceRows = ii_fetch_all($pdo, "
    SELECT device_type, COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY device_type
    ORDER BY cnt DESC
", $params);

$pageRows = ii_fetch_all($pdo, "
    SELECT
        path,
        COUNT(*) AS page_views,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY path
    ORDER BY page_views DESC
    LIMIT 50
", $params);

$sourceRows = ii_fetch_all($pdo, "
    SELECT
        CASE
            WHEN referrer_host IS NULL OR referrer_host = '' THEN 'direct'
            WHEN referrer_host LIKE '%morizaru.net%' THEN 'internal'
            ELSE referrer_host
        END AS source_name,
        COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY source_name
    ORDER BY cnt DESC
    LIMIT 50
", $params);

$refRows = ii_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(referrer, ''), 'direct') AS referrer,
        COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY referrer
    ORDER BY cnt DESC
    LIMIT 50
", $params);

$ipRows = ii_fetch_all($pdo, "
    SELECT
        ip_hash,
        COUNT(*) AS cnt,
        COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY ip_hash
    ORDER BY cnt DESC
    LIMIT 50
", $params);

$browserRows = ii_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(browser_name, ''), 'Other') AS browser_name,
        COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY browser_name
    ORDER BY cnt DESC
", $params);

$hasBotTable = ii_table_exists($pdo, 'access_bot_daily');
$botCount = ['cnt' => 0];
$botDailyRows = [];
$botNameRows = [];
$botPageRows = [];
$botDetailRows = [];

if ($hasBotTable) {
    $botCount = ii_fetch_one($pdo, "
        SELECT COALESCE(SUM(count), 0) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
    ", $dateParams);

    $botDailyRows = ii_fetch_all($pdo, "
        SELECT
            access_date,
            SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY access_date
        ORDER BY access_date
    ", $dateParams);

    $botNameRows = ii_fetch_all($pdo, "
        SELECT
            bot_name,
            SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY bot_name
        ORDER BY cnt DESC
        LIMIT 50
    ", $dateParams);

    $botPageRows = ii_fetch_all($pdo, "
        SELECT
            path,
            SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY path
        ORDER BY cnt DESC
        LIMIT 50
    ", $dateParams);

    $botDetailRows = ii_fetch_all($pdo, "
        SELECT
            access_date,
            path,
            bot_name,
            count
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        ORDER BY access_date DESC, count DESC
        LIMIT 100
    ", $dateParams);
}

$hasMonthlyTable = ii_table_exists($pdo, 'access_monthly_summary');
$monthlyRows = [];

if ($hasMonthlyTable) {
    $monthlyRows = ii_fetch_all($pdo, "
        SELECT
            summary_month,
            page_views,
            visitors,
            uu,
            bot_count,
            updated_at
        FROM access_monthly_summary
        WHERE summary_month BETWEEN DATE_FORMAT(:from_date, '%Y-%m-01') AND DATE_FORMAT(:to_date, '%Y-%m-01')
        ORDER BY summary_month DESC
    ", $dateParams);
}

$dailyLabels = array_column($dailyRows, 'access_date');
$dailyPv = array_map('intval', array_column($dailyRows, 'page_views'));
$dailyVisitors = array_map('intval', array_column($dailyRows, 'visitors'));
$dailyUu = array_map('intval', array_column($dailyRows, 'uu'));

$hourMap = [];
foreach ($hourRowsRaw as $row) {
    $hourMap[(int)$row['access_hour']] = (int)$row['page_views'];
}
$hourLabels = [];
$hourPv = [];
for ($i = 0; $i < 24; $i++) {
    $hourLabels[] = sprintf('%02d時', $i);
    $hourPv[] = $hourMap[$i] ?? 0;
}

$weekdayLabelsBase = ['月', '火', '水', '木', '金', '土', '日'];
$weekdayMap = [];
foreach ($weekdayRowsRaw as $row) {
    $weekdayMap[(int)$row['weekday_no']] = (int)$row['page_views'];
}
$weekdayLabels = [];
$weekdayPv = [];
for ($i = 0; $i < 7; $i++) {
    $weekdayLabels[] = $weekdayLabelsBase[$i];
    $weekdayPv[] = $weekdayMap[$i] ?? 0;
}

$deviceChartLabels = [];
$deviceChartData = [];
foreach ($deviceRows as $row) {
    $deviceChartLabels[] = ii_device_label($row['device_type']);
    $deviceChartData[] = (int)$row['cnt'];
}

$pageChartLabels = [];
$pageChartData = [];
foreach (array_slice($pageRows, 0, 10) as $row) {
    $pageChartLabels[] = ii_short_label($row['path'], 32);
    $pageChartData[] = (int)$row['page_views'];
}

$sourceChartLabels = [];
$sourceChartData = [];
foreach (array_slice($sourceRows, 0, 10) as $row) {
    $sourceChartLabels[] = ii_short_label($row['source_name'], 32);
    $sourceChartData[] = (int)$row['cnt'];
}

$botDailyLabels = array_column($botDailyRows, 'access_date');
$botDailyCounts = array_map('intval', array_column($botDailyRows, 'cnt'));

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ii_fetch_one(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function ii_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ii_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
    $stmt->execute([':table_name' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function ii_short_label($value, int $length = 32): string {
    $value = (string)$value;
    if (mb_strlen($value, 'UTF-8') <= $length) {
        return $value;
    }
    return mb_substr($value, 0, $length, 'UTF-8') . '…';
}

function ii_device_label($device): string {
    switch ($device) {
        case 'pc': return 'PC';
        case 'smartphone': return 'スマホ';
        case 'tablet': return 'タブレット';
        default: return 'その他';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>INDEXINDEX アクセス解析</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f4f6;
            color: #111827;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }
        .page { max-width: 1180px; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 16px; font-size: 28px; }
        h2 { margin: 0 0 12px; font-size: 20px; }
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        input[type="date"] {
            padding: 9px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
        }
        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            background: #111827;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .summary-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px;
        }
        .summary-box .label { color: #6b7280; font-size: 13px; }
        .summary-box .num { font-size: 30px; font-weight: 800; }
        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .chart-wrap { width: 100%; overflow-x: auto; }
        canvas {
            width: 100%;
            min-width: 720px;
            height: 320px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }
        canvas.short-chart { height: 260px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align: left; background: #f9fafb; font-weight: 800; }
        .right { text-align: right; white-space: nowrap; }
        .small { color: #6b7280; font-size: 12px; }
        .mono { font-family: Consolas, Monaco, monospace; word-break: break-all; }
        .note {
            padding: 10px 12px;
            border-radius: 10px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            margin-bottom: 12px;
        }
        @media (max-width: 800px) {
            .page { padding: 12px; }
            .summary, .grid2 { grid-template-columns: 1fr; }
            h1 { font-size: 22px; }
            canvas { min-width: 680px; }
        }
    </style>
</head>
<body>
<div class="page">
    <h1>INDEXINDEX アクセス解析</h1>

    <div class="card">
        <form class="filter" method="get">
            <div>
                <label>アクセス解析表示期間 FROM</label>
                <input type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div>
                <label>アクセス解析表示期間 TO</label>
                <input type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div>
                <button type="submit">表示</button>
            </div>
            <div class="small">
                表示期間：<?= h($from) ?> 〜 <?= h($to) ?> / 通常アクセスはbot除外 / bot数は日別集計 / 対象：/indexindex/ 配下
            </div>
        </form>
    </div>

    <div class="card">
        <h2>アクセス状況</h2>
        <div class="summary">
            <div class="summary-box">
                <div class="label">訪問者数</div>
                <div class="num"><?= number_format((int)($summary['visitors'] ?? 0)) ?></div>
                <div class="small">30分以内の同一訪問を1件として集計</div>
            </div>
            <div class="summary-box">
                <div class="label">ページ閲覧数</div>
                <div class="num"><?= number_format((int)($summary['page_views'] ?? 0)) ?></div>
                <div class="small">PV</div>
            </div>
            <div class="summary-box">
                <div class="label">UU数</div>
                <div class="num"><?= number_format((int)($summary['uu'] ?? 0)) ?></div>
                <div class="small">IP＋UAの匿名ハッシュ単位</div>
            </div>
            <div class="summary-box">
                <div class="label">botアクセス</div>
                <div class="num"><?= number_format((int)($botCount['cnt'] ?? 0)) ?></div>
                <div class="small">access_bot_daily の集計値</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>アクセス状況 折れ線グラフ</h2>
        <div class="chart-wrap">
            <canvas id="lineChart" width="1000" height="320"></canvas>
        </div>
        <div class="small">PV / 訪問者数 / UU数の日別推移</div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>時間別アクセスグラフ</h2>
            <div class="chart-wrap">
                <canvas class="short-chart" id="hourChart" width="1000" height="260"></canvas>
            </div>
            <div class="small">指定期間内の時間帯別PV</div>
        </div>

        <div class="card">
            <h2>曜日別アクセスグラフ</h2>
            <div class="chart-wrap">
                <canvas class="short-chart" id="weekdayChart" width="1000" height="260"></canvas>
            </div>
            <div class="small">指定期間内の曜日別PV</div>
        </div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>デバイス別 棒グラフ</h2>
            <div class="chart-wrap">
                <canvas class="short-chart" id="deviceBarChart" width="1000" height="260"></canvas>
            </div>
        </div>

        <div class="card">
            <h2>流入元別 棒グラフ</h2>
            <div class="chart-wrap">
                <canvas class="short-chart" id="sourceBarChart" width="1000" height="260"></canvas>
            </div>
            <div class="small">上位10件</div>
        </div>
    </div>

    <div class="card">
        <h2>ページ別アクセス 棒グラフ</h2>
        <div class="chart-wrap">
            <canvas id="pageBarChart" width="1000" height="320"></canvas>
        </div>
        <div class="small">上位10ページ</div>
    </div>

    <div class="card">
        <h2>botアクセス 日別グラフ</h2>
        <?php if (!$hasBotTable): ?>
            <div class="note">access_bot_daily テーブルがありません。先に access_bot_daily.sql を実行してください。</div>
        <?php endif; ?>
        <div class="chart-wrap">
            <canvas class="short-chart" id="botChart" width="1000" height="260"></canvas>
        </div>
        <div class="small">botアクセスは access_logs には保存せず、access_bot_daily の日別集計を表示します。</div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>bot別アクセス数</h2>
            <table>
                <thead><tr><th>bot</th><th class="right">件数</th></tr></thead>
                <tbody>
                <?php foreach ($botNameRows as $row): ?>
                    <tr>
                        <td><?= h($row['bot_name']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$botNameRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>botページ別アクセス数</h2>
            <table>
                <thead><tr><th>ページ</th><th class="right">件数</th></tr></thead>
                <tbody>
                <?php foreach ($botPageRows as $row): ?>
                    <tr>
                        <td class="mono"><?= h($row['path']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$botPageRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>月別集計テーブル</h2>
        <?php if (!$hasMonthlyTable): ?>
            <div class="note">access_monthly_summary テーブルがありません。先に access_monthly_summary.sql を実行してください。</div>
        <?php endif; ?>
        <table>
            <thead>
            <tr>
                <th>月</th>
                <th class="right">PV</th>
                <th class="right">訪問者数</th>
                <th class="right">UU</th>
                <th class="right">bot</th>
                <th>更新日時</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($monthlyRows as $row): ?>
                <tr>
                    <td><?= h(substr((string)$row['summary_month'], 0, 7)) ?></td>
                    <td class="right"><?= number_format((int)$row['page_views']) ?></td>
                    <td class="right"><?= number_format((int)$row['visitors']) ?></td>
                    <td class="right"><?= number_format((int)$row['uu']) ?></td>
                    <td class="right"><?= number_format((int)$row['bot_count']) ?></td>
                    <td><?= h($row['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$monthlyRows): ?><tr><td colspan="6">データなし</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="small">月別集計は access_monthly_summary_refresh.sql を実行すると更新されます。</div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>アクセスデバイス</h2>
            <table>
                <thead><tr><th>デバイス</th><th class="right">件数</th></tr></thead>
                <tbody>
                <?php foreach ($deviceRows as $row): ?>
                    <tr>
                        <td><?= h(ii_device_label($row['device_type'])) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deviceRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>ブラウザ別</h2>
            <table>
                <thead><tr><th>ブラウザ</th><th class="right">件数</th></tr></thead>
                <tbody>
                <?php foreach ($browserRows as $row): ?>
                    <tr>
                        <td><?= h($row['browser_name']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$browserRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>ページ別アクセス状況</h2>
        <table>
            <thead>
            <tr><th>ページ</th><th class="right">PV</th><th class="right">UU</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['path']) ?></td>
                    <td class="right"><?= number_format((int)$row['page_views']) ?></td>
                    <td class="right"><?= number_format((int)$row['uu']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?><tr><td colspan="3">データなし</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>アクセス元（流入元）</h2>
            <table>
                <thead><tr><th>流入元</th><th class="right">件数</th></tr></thead>
                <tbody>
                <?php foreach ($sourceRows as $row): ?>
                    <tr>
                        <td class="mono"><?= h($row['source_name']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sourceRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>IPアドレス別</h2>
            <table>
                <thead><tr><th>IP匿名ハッシュ</th><th class="right">PV</th><th class="right">UU</th></tr></thead>
                <tbody>
                <?php foreach ($ipRows as $row): ?>
                    <tr>
                        <td class="mono"><?= h(mb_substr($row['ip_hash'], 0, 16)) ?>...</td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                        <td class="right"><?= number_format((int)$row['uu']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ipRows): ?><tr><td colspan="3">データなし</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="small">個人情報保護のため、IPは平文保存せずハッシュで保存します。</div>
        </div>
    </div>

    <div class="card">
        <h2>その他の解析：リンク元</h2>
        <table>
            <thead><tr><th>リンク元URL</th><th class="right">件数</th></tr></thead>
            <tbody>
            <?php foreach ($refRows as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['referrer']) ?></td>
                    <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>botアクセス詳細</h2>
        <table>
            <thead>
            <tr><th>日付</th><th>ページ</th><th>bot</th><th class="right">件数</th></tr>
            </thead>
            <tbody>
            <?php foreach ($botDetailRows as $row): ?>
                <tr>
                    <td><?= h($row['access_date']) ?></td>
                    <td class="mono"><?= h($row['path']) ?></td>
                    <td><?= h($row['bot_name']) ?></td>
                    <td class="right"><?= number_format((int)$row['count']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$botDetailRows): ?><tr><td colspan="4">データなし</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const chartLabels = <?= json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const pvData = <?= json_encode($dailyPv, JSON_UNESCAPED_UNICODE) ?>;
const visitorData = <?= json_encode($dailyVisitors, JSON_UNESCAPED_UNICODE) ?>;
const uuData = <?= json_encode($dailyUu, JSON_UNESCAPED_UNICODE) ?>;

const hourLabels = <?= json_encode($hourLabels, JSON_UNESCAPED_UNICODE) ?>;
const hourPv = <?= json_encode($hourPv, JSON_UNESCAPED_UNICODE) ?>;

const weekdayLabels = <?= json_encode($weekdayLabels, JSON_UNESCAPED_UNICODE) ?>;
const weekdayPv = <?= json_encode($weekdayPv, JSON_UNESCAPED_UNICODE) ?>;

const deviceLabels = <?= json_encode($deviceChartLabels, JSON_UNESCAPED_UNICODE) ?>;
const deviceData = <?= json_encode($deviceChartData, JSON_UNESCAPED_UNICODE) ?>;

const pageLabels = <?= json_encode($pageChartLabels, JSON_UNESCAPED_UNICODE) ?>;
const pageData = <?= json_encode($pageChartData, JSON_UNESCAPED_UNICODE) ?>;

const sourceLabels = <?= json_encode($sourceChartLabels, JSON_UNESCAPED_UNICODE) ?>;
const sourceData = <?= json_encode($sourceChartData, JSON_UNESCAPED_UNICODE) ?>;

const botLabels = <?= json_encode($botDailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const botData = <?= json_encode($botDailyCounts, JSON_UNESCAPED_UNICODE) ?>;

drawLineChart('lineChart', chartLabels, [
    {name: 'PV', data: pvData, dash: [], width: 3},
    {name: '訪問者', data: visitorData, dash: [6, 4], width: 2},
    {name: 'UU', data: uuData, dash: [2, 4], width: 2}
]);

drawBarChart('hourChart', hourLabels, hourPv, 'PV');
drawBarChart('weekdayChart', weekdayLabels, weekdayPv, 'PV');
drawBarChart('deviceBarChart', deviceLabels, deviceData, '件数');
drawBarChart('pageBarChart', pageLabels, pageData, 'PV');
drawBarChart('sourceBarChart', sourceLabels, sourceData, '件数');
drawLineChart('botChart', botLabels, [
    {name: 'bot', data: botData, dash: [], width: 3}
]);

function drawLineChart(canvasId, labels, series) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    const padL = 60;
    const padR = 20;
    const padT = 30;
    const padB = 60;

    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px sans-serif';

    const all = series.flatMap(s => s.data);
    const maxVal = Math.max(1, ...all);
    const plotW = width - padL - padR;
    const plotH = height - padT - padB;

    drawGrid(ctx, width, height, padL, padR, padT, padB, maxVal);

    series.forEach((s, idx) => {
        ctx.beginPath();
        ctx.setLineDash(s.dash || []);
        ctx.strokeStyle = idx === 0 ? '#111827' : (idx === 1 ? '#4b5563' : '#9ca3af');
        ctx.lineWidth = s.width || 2;

        s.data.forEach((v, i) => {
            const x = padL + (labels.length <= 1 ? 0 : plotW * i / (labels.length - 1));
            const y = padT + plotH - (plotH * v / maxVal);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.setLineDash([]);
    });

    let legendX = padL;
    series.forEach((s, idx) => {
        ctx.fillStyle = idx === 0 ? '#111827' : (idx === 1 ? '#4b5563' : '#9ca3af');
        ctx.fillText(s.name, legendX, 18);
        legendX += 70;
    });

    drawXAxisLabels(ctx, labels, width, height, padL, padR, 8);
}

function drawBarChart(canvasId, labels, data, legend) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    const padL = 60;
    const padR = 20;
    const padT = 30;
    const padB = 70;

    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px sans-serif';

    const maxVal = Math.max(1, ...data);
    const plotW = width - padL - padR;
    const plotH = height - padT - padB;

    drawGrid(ctx, width, height, padL, padR, padT, padB, maxVal);

    const gap = 8;
    const barW = labels.length > 0 ? Math.max(8, (plotW - gap * (labels.length - 1)) / labels.length) : 0;

    ctx.fillStyle = '#111827';
    data.forEach((v, i) => {
        const x = padL + i * (barW + gap);
        const h = plotH * v / maxVal;
        const y = padT + plotH - h;
        ctx.fillRect(x, y, barW, h);
    });

    ctx.fillStyle = '#111827';
    ctx.fillText(legend, padL, 18);

    drawXAxisLabels(ctx, labels, width, height, padL, padR, 10);
}

function drawGrid(ctx, width, height, padL, padR, padT, padB, maxVal) {
    const plotH = height - padT - padB;
    ctx.strokeStyle = '#e5e7eb';
    ctx.lineWidth = 1;

    for (let i = 0; i <= 5; i++) {
        const y = padT + plotH - (plotH * i / 5);
        ctx.beginPath();
        ctx.moveTo(padL, y);
        ctx.lineTo(width - padR, y);
        ctx.stroke();

        const val = Math.round(maxVal * i / 5);
        ctx.fillStyle = '#6b7280';
        ctx.fillText(String(val), 8, y + 4);
    }
}

function drawXAxisLabels(ctx, labels, width, height, padL, padR, maxTicks) {
    const plotW = width - padL - padR;
    const step = Math.max(1, Math.ceil(labels.length / maxTicks));
    ctx.fillStyle = '#6b7280';

    labels.forEach((label, i) => {
        if (i % step !== 0 && i !== labels.length - 1) return;
        const x = padL + (labels.length <= 1 ? 0 : plotW * i / Math.max(1, labels.length - 1));
        ctx.save();
        ctx.translate(x, height - 16);
        ctx.rotate(-Math.PI / 5);
        ctx.fillText(String(label), 0, 0);
        ctx.restore();
    });
}
</script>
</body>
</html>
