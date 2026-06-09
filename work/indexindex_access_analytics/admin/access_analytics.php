<?php
// INDEXINDEX アクセス解析 管理画面
// 設置場所例: /indexindex/admin/access_analytics.php

declare(strict_types=1);

$pdo = ii_admin_access_get_pdo();
if (!$pdo) {
    http_response_code(500);
    echo 'DB接続に失敗しました。config.php の場所、または DB_HOST / DB_NAME / DB_USER / DB_PASS を確認してください。';
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

$botCount = ii_fetch_one($pdo, "
    SELECT COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 1
", $params);

$dailyLabels = array_column($dailyRows, 'access_date');
$dailyPv = array_map('intval', array_column($dailyRows, 'page_views'));
$dailyVisitors = array_map('intval', array_column($dailyRows, 'visitors'));
$dailyUu = array_map('intval', array_column($dailyRows, 'uu'));

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

function ii_admin_access_get_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $candidates = [
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../../config.php',
        __DIR__ . '/config.php',
        __DIR__ . '/app/config.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            break;
        }
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
        return $pdo;
    }

    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        $pdo = $GLOBALS['db'];
        return $pdo;
    }

    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>INDEXINDEX アクセス解析</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: #f3f4f6;
            color: #111827;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }
        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 28px;
        }
        h2 {
            margin: 0 0 12px;
            font-size: 20px;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .filter {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 4px;
        }
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
        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .summary-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px;
        }
        .summary-box .label {
            color: #6b7280;
            font-size: 13px;
        }
        .summary-box .num {
            font-size: 30px;
            font-weight: 800;
        }
        .grid2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .chart-wrap {
            width: 100%;
            overflow-x: auto;
        }
        canvas {
            width: 100%;
            min-width: 720px;
            height: 320px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 9px 10px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        th {
            text-align: left;
            background: #f9fafb;
            font-weight: 800;
        }
        .right {
            text-align: right;
            white-space: nowrap;
        }
        .small {
            color: #6b7280;
            font-size: 12px;
        }
        .mono {
            font-family: Consolas, Monaco, monospace;
            word-break: break-all;
        }
        @media (max-width: 800px) {
            .page {
                padding: 12px;
            }
            .summary, .grid2 {
                grid-template-columns: 1fr;
            }
            h1 {
                font-size: 22px;
            }
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
                表示期間：<?= h($from) ?> 〜 <?= h($to) ?> / bot除外 / 対象：/indexindex/ 配下
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
                <div class="small">集計からは除外</div>
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
            <h2>アクセスデバイス</h2>
            <table>
                <thead>
                <tr><th>デバイス</th><th class="right">件数</th></tr>
                </thead>
                <tbody>
                <?php foreach ($deviceRows as $row): ?>
                    <tr>
                        <td><?= h(ii_device_label($row['device_type'])) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deviceRows): ?>
                    <tr><td colspan="2">データなし</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>ブラウザ別</h2>
            <table>
                <thead>
                <tr><th>ブラウザ</th><th class="right">件数</th></tr>
                </thead>
                <tbody>
                <?php foreach ($browserRows as $row): ?>
                    <tr>
                        <td><?= h($row['browser_name']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$browserRows): ?>
                    <tr><td colspan="2">データなし</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>ページ別アクセス状況</h2>
        <table>
            <thead>
            <tr>
                <th>ページ</th>
                <th class="right">PV</th>
                <th class="right">UU</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['path']) ?></td>
                    <td class="right"><?= number_format((int)$row['page_views']) ?></td>
                    <td class="right"><?= number_format((int)$row['uu']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?>
                <tr><td colspan="3">データなし</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="grid2">
        <div class="card">
            <h2>アクセス元（流入元）</h2>
            <table>
                <thead>
                <tr><th>流入元</th><th class="right">件数</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sourceRows as $row): ?>
                    <tr>
                        <td class="mono"><?= h($row['source_name']) ?></td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sourceRows): ?>
                    <tr><td colspan="2">データなし</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>IPアドレス別</h2>
            <table>
                <thead>
                <tr><th>IP匿名ハッシュ</th><th class="right">PV</th><th class="right">UU</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ipRows as $row): ?>
                    <tr>
                        <td class="mono"><?= h(mb_substr($row['ip_hash'], 0, 16)) ?>...</td>
                        <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                        <td class="right"><?= number_format((int)$row['uu']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ipRows): ?>
                    <tr><td colspan="3">データなし</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <div class="small">個人情報保護のため、IPは平文保存せずハッシュで保存します。</div>
        </div>
    </div>

    <div class="card">
        <h2>その他の解析：リンク元</h2>
        <table>
            <thead>
            <tr><th>リンク元URL</th><th class="right">件数</th></tr>
            </thead>
            <tbody>
            <?php foreach ($refRows as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['referrer']) ?></td>
                    <td class="right"><?= number_format((int)$row['cnt']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refRows): ?>
                <tr><td colspan="2">データなし</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const chartLabels = <?= json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const pvData = <?= json_encode($dailyPv, JSON_UNESCAPED_UNICODE) ?>;
const visitorData = <?= json_encode($dailyVisitors, JSON_UNESCAPED_UNICODE) ?>;
const uuData = <?= json_encode($dailyUu, JSON_UNESCAPED_UNICODE) ?>;

drawLineChart();

function drawLineChart() {
    const canvas = document.getElementById('lineChart');
    const ctx = canvas.getContext('2d');

    const width = canvas.width;
    const height = canvas.height;
    const padL = 55;
    const padR = 20;
    const padT = 28;
    const padB = 55;

    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px sans-serif';

    const all = pvData.concat(visitorData, uuData);
    const maxVal = Math.max(1, ...all);
    const plotW = width - padL - padR;
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

    const series = [
        {name: 'PV', data: pvData, dash: []},
        {name: '訪問者', data: visitorData, dash: [6, 4]},
        {name: 'UU', data: uuData, dash: [2, 4]}
    ];

    series.forEach((s, idx) => {
        ctx.beginPath();
        ctx.setLineDash(s.dash);
        ctx.strokeStyle = idx === 0 ? '#111827' : (idx === 1 ? '#4b5563' : '#9ca3af');
        ctx.lineWidth = idx === 0 ? 3 : 2;

        s.data.forEach((v, i) => {
            const x = padL + (chartLabels.length <= 1 ? 0 : plotW * i / (chartLabels.length - 1));
            const y = padT + plotH - (plotH * v / maxVal);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.setLineDash([]);
    });

    ctx.fillStyle = '#111827';
    ctx.fillText('PV', padL, 16);
    ctx.fillStyle = '#4b5563';
    ctx.fillText('訪問者', padL + 45, 16);
    ctx.fillStyle = '#9ca3af';
    ctx.fillText('UU', padL + 110, 16);

    ctx.fillStyle = '#6b7280';
    const step = Math.max(1, Math.ceil(chartLabels.length / 8));
    chartLabels.forEach((label, i) => {
        if (i % step !== 0 && i !== chartLabels.length - 1) return;
        const x = padL + (chartLabels.length <= 1 ? 0 : plotW * i / (chartLabels.length - 1));
        ctx.save();
        ctx.translate(x, height - 14);
        ctx.rotate(-Math.PI / 5);
        ctx.fillText(label.substring(5), 0, 0);
        ctx.restore();
    });
}
</script>
</body>
</html>
<?php
function ii_device_label($device): string {
    switch ($device) {
        case 'pc': return 'PC';
        case 'smartphone': return 'スマホ';
        case 'tablet': return 'タブレット';
        default: return 'その他';
    }
}
