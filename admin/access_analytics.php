<?php
// access_analytics.php
// INDEXINDEX アクセス解析 管理画面
// 設置場所例: /indexindex/admin/access_analytics.php

declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'DB接続に失敗しました。../app/config.php で $pdo が作成されているか確認してください。';
    exit;
}

date_default_timezone_set('Asia/Tokyo');

$today = date('Y-m-d');
$defaultFrom = $today;

$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? $today;
$botUaName = $_GET['bot_ua_name'] ?? 'Other Bot';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}
if (!is_string($botUaName) || $botUaName === '' || mb_strlen($botUaName) > 80 || preg_match('/[\x00-\x1F\x7F]/', $botUaName)) {
    $botUaName = 'Other Bot';
}

if ($from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$params = [
    ':from' => $fromDt,
    ':to' => $toDt,
];

try {
    $periodFromDate = new DateTimeImmutable($from);
    $periodToDate = new DateTimeImmutable($to);
    $periodDays = max(1, $periodFromDate->diff($periodToDate)->days + 1);
} catch (Throwable $e) {
    $periodDays = 1;
}

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

function ii_daily_average($value, int $periodDays): string {
    if ($periodDays <= 0) {
        $periodDays = 1;
    }
    $average = ((int)$value) / $periodDays;
    return $average >= 10 ? number_format($average, 1) : number_format($average, 2);
}

function ii_percentage($value, $total): string {
    $total = (int)$total;
    if ($total <= 0) {
        return '0.0%';
    }
    return number_format(((int)$value) * 100 / $total, 1) . '%';
}

function ii_device_label($deviceType): string {
    $deviceType = (string)$deviceType;
    $map = [
        'pc' => 'PC',
        'sp' => 'スマホ',
        'smartphone' => 'スマホ',
        'tablet' => 'タブレット',
        'other' => 'その他',
        '' => '不明',
    ];
    return $map[$deviceType] ?? $deviceType;
}

function ii_bot_display_name($botName): string {
    $botName = (string)$botName;
    $map = [
        'Attack / SQL Injection' => 'Attack / SQL Injection',
        'Blocked IP Hash' => 'Blocked IP Hash',
        'Behavior Bot / Distributed' => 'Behavior Bot / Distributed',
        'Behavior Bot / Distributed Work' => 'Behavior Bot / Distributed Work',
        'Googlebot' => 'Googlebot',
        'Bingbot' => 'Bingbot',
    ];
    return $map[$botName] ?? ($botName !== '' ? $botName : 'その他');
}

function ii_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
        $stmt->execute([':table_name' => $tableName]);
        return ((int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ii_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name");
        $stmt->execute([':table_name' => $tableName, ':column_name' => $columnName]);
        return ((int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ii_get_monthly_rows(PDO $pdo): array {
    if (
        ii_table_exists($pdo, 'access_monthly_summary') &&
        ii_column_exists($pdo, 'access_monthly_summary', 'month_start') &&
        ii_column_exists($pdo, 'access_monthly_summary', 'page_views') &&
        ii_column_exists($pdo, 'access_monthly_summary', 'visitors') &&
        ii_column_exists($pdo, 'access_monthly_summary', 'uu') &&
        ii_column_exists($pdo, 'access_monthly_summary', 'bot_count')
    ) {
        try {
            return ii_fetch_all($pdo, "
                SELECT month_start, page_views, visitors, uu, bot_count
                FROM access_monthly_summary
                ORDER BY month_start DESC
                LIMIT 24
            ");
        } catch (Throwable $e) {
        }
    }

    if (ii_table_exists($pdo, 'access_bot_daily')) {
        try {
            return ii_fetch_all($pdo, "
                SELECT
                    month_start,
                    SUM(page_views) AS page_views,
                    SUM(visitors) AS visitors,
                    SUM(uu) AS uu,
                    SUM(bot_count) AS bot_count
                FROM (
                    SELECT
                        DATE_FORMAT(access_time, '%Y-%m-01') AS month_start,
                        COUNT(*) AS page_views,
                        COUNT(DISTINCT visitor_session_id) AS visitors,
                        COUNT(DISTINCT visitor_hash) AS uu,
                        0 AS bot_count
                    FROM access_logs
                    WHERE site_key = 'indexindex'
                      AND is_bot = 0
                    GROUP BY DATE_FORMAT(access_time, '%Y-%m-01')

                    UNION ALL

                    SELECT
                        DATE_FORMAT(access_date, '%Y-%m-01') AS month_start,
                        0 AS page_views,
                        0 AS visitors,
                        0 AS uu,
                        SUM(count) AS bot_count
                    FROM access_bot_daily
                    GROUP BY DATE_FORMAT(access_date, '%Y-%m-01')
                ) t
                GROUP BY month_start
                ORDER BY month_start DESC
                LIMIT 24
            ");
        } catch (Throwable $e) {
            return [];
        }
    }

    try {
        return ii_fetch_all($pdo, "
            SELECT
                DATE_FORMAT(access_time, '%Y-%m-01') AS month_start,
                COUNT(*) AS page_views,
                COUNT(DISTINCT visitor_session_id) AS visitors,
                COUNT(DISTINCT visitor_hash) AS uu,
                0 AS bot_count
            FROM access_logs
            WHERE site_key = 'indexindex'
              AND is_bot = 0
            GROUP BY DATE_FORMAT(access_time, '%Y-%m-01')
            ORDER BY month_start DESC
            LIMIT 24
        ");
    } catch (Throwable $e) {
        return [];
    }
}

$summary = ii_fetch_one($pdo, "
    SELECT COUNT(*) AS page_views, COUNT(DISTINCT visitor_session_id) AS visitors, COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
", $params);

$dailyRows = ii_fetch_all($pdo, "
    SELECT DATE(access_time) AS access_date, COUNT(*) AS page_views, COUNT(DISTINCT visitor_session_id) AS visitors, COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY DATE(access_time)
    ORDER BY access_date
", $params);

$deviceRows = ii_fetch_all($pdo, "
    SELECT COALESCE(NULLIF(device_type, ''), 'other') AS device_type, COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY device_type
    ORDER BY cnt DESC
", $params);

$pageRows = ii_fetch_all($pdo, "
    SELECT path, COUNT(*) AS page_views, COUNT(DISTINCT visitor_hash) AS uu
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

$hourRawRows = ii_fetch_all($pdo, "
    SELECT HOUR(access_time) AS access_hour, COUNT(*) AS page_views
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY HOUR(access_time)
    ORDER BY access_hour
", $params);

$hourMap = [];
foreach ($hourRawRows as $row) {
    $hourMap[(int)$row['access_hour']] = (int)$row['page_views'];
}
$hourRows = [];
for ($i = 0; $i <= 23; $i++) {
    $hourRows[] = ['label' => sprintf('%02d時', $i), 'cnt' => $hourMap[$i] ?? 0];
}

$weekdayRawRows = ii_fetch_all($pdo, "
    SELECT DAYOFWEEK(access_time) AS weekday_no, COUNT(*) AS page_views
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY DAYOFWEEK(access_time)
    ORDER BY weekday_no
", $params);
$weekdayLabelsMap = [1 => '日', 2 => '月', 3 => '火', 4 => '水', 5 => '木', 6 => '金', 7 => '土'];
$weekdayMap = [];
foreach ($weekdayRawRows as $row) {
    $weekdayMap[(int)$row['weekday_no']] = (int)$row['page_views'];
}
$weekdayRows = [];
for ($i = 1; $i <= 7; $i++) {
    $weekdayRows[] = ['label' => $weekdayLabelsMap[$i], 'cnt' => $weekdayMap[$i] ?? 0];
}

$refRows = ii_fetch_all($pdo, "
    SELECT COALESCE(NULLIF(referrer, ''), 'direct') AS referrer, COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY referrer
    ORDER BY cnt DESC
    LIMIT 50
", $params);

$ipRows = ii_fetch_all($pdo, "
    SELECT ip_hash, COUNT(*) AS cnt, COUNT(DISTINCT visitor_hash) AS uu
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY ip_hash
    ORDER BY cnt DESC
    LIMIT 50
", $params);

$browserRows = ii_fetch_all($pdo, "
    SELECT COALESCE(NULLIF(browser_name, ''), 'Other') AS browser_name, COUNT(*) AS cnt
    FROM access_logs
    WHERE site_key = 'indexindex'
      AND access_time BETWEEN :from AND :to
      AND is_bot = 0
    GROUP BY browser_name
    ORDER BY cnt DESC
", $params);

$hasBotTable = ii_table_exists($pdo, 'access_bot_daily');
$hasBotUaTable = ii_table_exists($pdo, 'access_bot_user_agents_daily');
$botParams = [':from_date' => $from, ':to_date' => $to];
$botCount = ['cnt' => 0];
$botDailyRows = [];
$botNameRows = [];
$botPageRows = [];
$botDetailRows = [];
$botProtectionRows = [];
$recentBotProtectionRows = [];
$behaviorBotPageRows = [];
$recentBotNameRows = [];
$botBreakdownRows = [];
$botUaNameRows = [];
$botUaRows = [];

if ($hasBotTable) {
    $botCount = ii_fetch_one($pdo, "
        SELECT COALESCE(SUM(count), 0) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
    ", $botParams);

    $botDailyRows = ii_fetch_all($pdo, "
        SELECT access_date, SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY access_date
        ORDER BY access_date
    ", $botParams);

    $botNameRows = ii_fetch_all($pdo, "
        SELECT
            CASE
                WHEN bot_name IN ('Attack / SQL Injection', 'Blocked IP Hash', 'Behavior Bot / Distributed', 'Behavior Bot / Distributed Work', 'Googlebot', 'Bingbot')
                THEN bot_name
                ELSE 'その他'
            END AS bot_name,
            SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY
            CASE
                WHEN bot_name IN ('Attack / SQL Injection', 'Blocked IP Hash', 'Behavior Bot / Distributed', 'Behavior Bot / Distributed Work', 'Googlebot', 'Bingbot')
                THEN bot_name
                ELSE 'その他'
            END
        ORDER BY cnt DESC
        LIMIT 50
    ", $botParams);

    $botPageRows = ii_fetch_all($pdo, "
        SELECT path, SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY path
        ORDER BY cnt DESC
        LIMIT 50
    ", $botParams);

    $botDetailRows = ii_fetch_all($pdo, "
        SELECT access_date, path, bot_name, count
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        ORDER BY access_date DESC, count DESC
        LIMIT 100
    ", $botParams);

    $botProtectionRows = ii_fetch_all($pdo, "
        SELECT bot_name, SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY bot_name
        ORDER BY cnt DESC
    ", $botParams);

    $recentBotProtectionRows = ii_fetch_all($pdo, "
        SELECT
            access_date,
            SUM(CASE WHEN bot_name = 'Attack / SQL Injection' THEN count ELSE 0 END) AS attack_count,
            SUM(CASE WHEN bot_name = 'Blocked IP Hash' THEN count ELSE 0 END) AS blocked_ip_count,
            SUM(CASE WHEN bot_name = 'Behavior Bot / Distributed' THEN count ELSE 0 END) AS behavior_count,
            SUM(CASE WHEN bot_name = 'Behavior Bot / Distributed Work' THEN count ELSE 0 END) AS behavior_work_count,
            SUM(count) AS bot_total
        FROM access_bot_daily
        WHERE access_date >= (CURDATE() - INTERVAL 6 DAY)
          AND access_date <= CURDATE()
        GROUP BY access_date
        ORDER BY access_date DESC
    ");

    $behaviorBotPageRows = ii_fetch_all($pdo, "
        SELECT path, SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
          AND bot_name IN ('Behavior Bot / Distributed', 'Behavior Bot / Distributed Work')
        GROUP BY path
        ORDER BY cnt DESC
        LIMIT 50
    ", $botParams);

    $botBreakdownRows = ii_fetch_all($pdo, "
        SELECT bot_name, SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date BETWEEN :from_date AND :to_date
        GROUP BY bot_name
        ORDER BY cnt DESC
        LIMIT 50
    ", $botParams);

    $recentBotNameRows = ii_fetch_all($pdo, "
        SELECT
            access_date,
            bot_name,
            SUM(count) AS cnt
        FROM access_bot_daily
        WHERE access_date >= (CURDATE() - INTERVAL 6 DAY)
        GROUP BY access_date, bot_name
        ORDER BY access_date DESC, cnt DESC
        LIMIT 200
    ");
}

if ($hasBotUaTable) {
    try {
        $botUaNameRows = ii_fetch_all($pdo, "
            SELECT bot_name, SUM(count) AS cnt
            FROM access_bot_user_agents_daily
            WHERE access_date BETWEEN :from_date AND :to_date
            GROUP BY bot_name
            ORDER BY cnt DESC
        ", $botParams);
    } catch (Throwable $e) {
        $botUaNameRows = [];
    }

    try {
        $botUaRows = ii_fetch_all($pdo, "
            SELECT
                bot_name,
                COALESCE(NULLIF(user_agent, ''), '(empty)') AS user_agent,
                SUM(count) AS cnt
            FROM access_bot_user_agents_daily
            WHERE access_date BETWEEN :from_date AND :to_date
              AND bot_name = :bot_name
            GROUP BY bot_name, user_agent_hash, user_agent
            ORDER BY cnt DESC
            LIMIT 50
        ", $botParams + [':bot_name' => $botUaName]);
    } catch (Throwable $e) {
        $botUaRows = [];
    }
}

$botUaSelectedInOptions = false;
foreach ($botUaNameRows as $row) {
    if ((string)($row['bot_name'] ?? '') === $botUaName) {
        $botUaSelectedInOptions = true;
        break;
    }
}

$botCountMap = [];
foreach ($botNameRows as $row) {
    $botCountMap[(string)($row['bot_name'] ?? '')] = (int)($row['cnt'] ?? 0);
}
$attackBotCount = $botCountMap['Attack / SQL Injection'] ?? 0;
$blockedIpBotCount = $botCountMap['Blocked IP Hash'] ?? 0;
$behaviorBotCount = $botCountMap['Behavior Bot / Distributed'] ?? 0;
$behaviorWorkBotCount = $botCountMap['Behavior Bot / Distributed Work'] ?? 0;
$botTotalCount = (int)($botCount['cnt'] ?? 0);

$distributedCandidateRows = ii_fetch_all($pdo, "
    SELECT
        path,
        COUNT(*) AS pv,
        COUNT(DISTINCT ip_hash) AS ip_count,
        COUNT(DISTINCT visitor_hash) AS visitor_count,
        COUNT(DISTINCT visitor_session_id) AS session_count,
        COUNT(DISTINCT user_agent) AS ua_count,
        SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS no_referrer_count
    FROM access_logs
    WHERE access_time >= CURDATE()
      AND is_bot = 0
    GROUP BY path
    ORDER BY pv DESC
    LIMIT 20
");

$normalBotLikeUaRows = ii_fetch_all($pdo, "
    SELECT
        DATE(access_time) AS access_date,
        user_agent,
        COUNT(*) AS pv,
        COUNT(DISTINCT ip_hash) AS ip_count,
        COUNT(DISTINCT visitor_hash) AS uu,
        COUNT(DISTINCT visitor_session_id) AS session_count,
        SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS no_referrer_count,
        MIN(access_time) AS first_access,
        MAX(access_time) AS last_access
    FROM access_logs
    WHERE access_time >= CURDATE() - INTERVAL 6 DAY
      AND is_bot = 0
      AND (
        user_agent LIKE '%bot%'
        OR user_agent LIKE '%crawl%'
        OR user_agent LIKE '%spider%'
        OR user_agent LIKE '%slurp%'
        OR user_agent LIKE '%facebookexternalhit%'
        OR user_agent LIKE '%meta-externalagent%'
        OR user_agent LIKE '%ChatGPT-User%'
        OR user_agent LIKE '%OAI-SearchBot%'
        OR user_agent LIKE '%DuckDuckBot%'
        OR user_agent LIKE '%coccocbot%'
        OR user_agent LIKE '%ShapBot%'
      )
    GROUP BY DATE(access_time), user_agent
    ORDER BY access_date DESC, pv DESC
    LIMIT 100
");

$normalBehaviorCandidateRows = ii_fetch_all($pdo, "
    SELECT
        DATE(access_time) AS access_date,
        path,
        COUNT(*) AS pv,
        COUNT(DISTINCT ip_hash) AS ip_count,
        COUNT(DISTINCT visitor_hash) AS uu,
        COUNT(DISTINCT visitor_session_id) AS session_count,
        COUNT(DISTINCT user_agent) AS ua_count,
        SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS no_referrer_count,
        MIN(access_time) AS first_access,
        MAX(access_time) AS last_access
    FROM access_logs
    WHERE access_time >= CURDATE() - INTERVAL 6 DAY
      AND is_bot = 0
    GROUP BY DATE(access_time), path
    HAVING pv >= 100
       AND ip_count >= pv * 0.7
       AND session_count >= pv * 0.7
       AND uu >= pv * 0.7
       AND no_referrer_count >= pv * 0.7
       AND ua_count <= 10
    ORDER BY access_date DESC, pv DESC
    LIMIT 100
");

$normalWorkCandidateRows = ii_fetch_all($pdo, "
    SELECT
        DATE(access_time) AS access_date,
        SUBSTRING_INDEX(SUBSTRING_INDEX(query_string, 'work_id=', -1), '&', 1) AS work_id,
        path,
        COUNT(*) AS pv,
        COUNT(DISTINCT ip_hash) AS ip_count,
        COUNT(DISTINCT visitor_hash) AS uu,
        COUNT(DISTINCT visitor_session_id) AS session_count,
        COUNT(DISTINCT user_agent) AS ua_count,
        SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS no_referrer_count,
        MIN(access_time) AS first_access,
        MAX(access_time) AS last_access
    FROM access_logs
    WHERE access_time >= CURDATE() - INTERVAL 6 DAY
      AND is_bot = 0
      AND path IN ('/indexindex/work.php', '/indexindex/work_graph.php')
      AND query_string LIKE '%work_id=%'
    GROUP BY DATE(access_time), work_id, path
    HAVING pv >= 100
       AND ip_count >= pv * 0.7
       AND session_count >= pv * 0.7
       AND uu >= pv * 0.7
       AND no_referrer_count >= pv * 0.7
       AND ua_count <= 10
    ORDER BY access_date DESC, pv DESC
    LIMIT 100
");

$normalNoReferrerRows = ii_fetch_all($pdo, "
    SELECT
        DATE(access_time) AS access_date,
        path,
        COUNT(*) AS pv,
        SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS no_referrer_count,
        COUNT(DISTINCT ip_hash) AS ip_count,
        COUNT(DISTINCT visitor_hash) AS uu,
        COUNT(DISTINCT visitor_session_id) AS session_count,
        COUNT(DISTINCT user_agent) AS ua_count,
        MIN(access_time) AS first_access,
        MAX(access_time) AS last_access
    FROM access_logs
    WHERE access_time >= CURDATE() - INTERVAL 6 DAY
      AND is_bot = 0
    GROUP BY DATE(access_time), path
    HAVING pv >= 100
       AND no_referrer_count >= pv * 0.8
    ORDER BY access_date DESC, no_referrer_count DESC
    LIMIT 100
");

$monthlyRows = ii_get_monthly_rows($pdo);

$dailyLabels = array_column($dailyRows, 'access_date');
$dailyPv = array_map('intval', array_column($dailyRows, 'page_views'));
$dailyVisitors = array_map('intval', array_column($dailyRows, 'visitors'));
$dailyUu = array_map('intval', array_column($dailyRows, 'uu'));
$botDailyLabels = array_column($botDailyRows, 'access_date');
$botDailyCounts = array_map('intval', array_column($botDailyRows, 'cnt'));
$hourLabels = array_column($hourRows, 'label');
$hourCounts = array_map('intval', array_column($hourRows, 'cnt'));
$weekdayLabels = array_column($weekdayRows, 'label');
$weekdayCounts = array_map('intval', array_column($weekdayRows, 'cnt'));
$deviceLabels = array_map('ii_device_label', array_column($deviceRows, 'device_type'));
$deviceCounts = array_map('intval', array_column($deviceRows, 'cnt'));
$pageChartLabels = array_column(array_slice($pageRows, 0, 15), 'path');
$pageChartCounts = array_map('intval', array_column(array_slice($pageRows, 0, 15), 'page_views'));
$sourceChartLabels = array_column(array_slice($sourceRows, 0, 15), 'source_name');
$sourceChartCounts = array_map('intval', array_column(array_slice($sourceRows, 0, 15), 'cnt'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>INDEXINDEX アクセス解析</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #111827; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; line-height: 1.6; }
        .page { max-width: 1180px; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 16px; font-size: 28px; }
        h2 { margin: 0 0 12px; font-size: 20px; }
        .card { background: #fff; border-radius: 14px; padding: 18px; margin-bottom: 18px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        input[type="date"], select { padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
        button { border: 0; border-radius: 8px; padding: 10px 16px; background: #111827; color: #fff; font-weight: 700; cursor: pointer; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .summary-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; }
        .summary-box .label { color: #6b7280; font-size: 13px; }
        .summary-box .num { font-size: 30px; font-weight: 800; }
        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .chart-wrap { width: 100%; overflow-x: auto; }
        canvas { width: 100%; min-width: 720px; height: 320px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align: left; background: #f9fafb; font-weight: 800; }
        .right { text-align: right; white-space: nowrap; }
        .small { color: #6b7280; font-size: 12px; }
        .mono { font-family: Consolas, Monaco, monospace; word-break: break-all; }
        @media (max-width: 800px) { .page { padding: 12px; } .summary, .grid2 { grid-template-columns: 1fr; } h1 { font-size: 22px; } }
    </style>
</head>
<body>
<div class="page">
    <h1>INDEXINDEX アクセス解析</h1>

    <div class="card">
        <form class="filter" method="get">
            <div><label>アクセス解析表示期間 FROM</label><input type="date" name="from" value="<?= h($from) ?>"></div>
            <div><label>アクセス解析表示期間 TO</label><input type="date" name="to" value="<?= h($to) ?>"></div>
            <div><label>bot User-Agent内訳</label><select name="bot_ua_name"><option value="Other Bot"<?= $botUaName === 'Other Bot' ? ' selected' : '' ?>>Other Bot</option><?php foreach ($botUaNameRows as $row): $optionBotName = (string)($row['bot_name'] ?? ''); if ($optionBotName === '' || $optionBotName === 'Other Bot') continue; ?><option value="<?= h($optionBotName) ?>"<?= $botUaName === $optionBotName ? ' selected' : '' ?>><?= h(ii_bot_display_name($optionBotName)) ?> (<?= number_format((int)($row['cnt'] ?? 0)) ?>)</option><?php endforeach; ?><?php if ($botUaName !== 'Other Bot' && !$botUaSelectedInOptions): ?><option value="<?= h($botUaName) ?>" selected><?= h(ii_bot_display_name($botUaName)) ?></option><?php endif; ?></select></div>
            <div><button type="submit">表示</button></div>
            <div class="small">表示期間：<?= h($from) ?> 〜 <?= h($to) ?> / 通常アクセスはbot除外 / bot数は日別集計 / 対象：/indexindex/ 配下</div>
        </form>
    </div>

    <div class="card">
        <h2>アクセス状況</h2>
        <div class="summary">
            <div class="summary-box"><div class="label">訪問者数</div><div class="num"><?= number_format((int)($summary['visitors'] ?? 0)) ?></div><div class="small">平均：<?= h(ii_daily_average($summary['visitors'] ?? 0, $periodDays)) ?>/日（<?= h($periodDays) ?>日間）</div><div class="small">30分以内の同一訪問を1件として集計</div></div>
            <div class="summary-box"><div class="label">通常PV</div><div class="num"><?= number_format((int)($summary['page_views'] ?? 0)) ?></div><div class="small">平均：<?= h(ii_daily_average($summary['page_views'] ?? 0, $periodDays)) ?>/日（<?= h($periodDays) ?>日間）</div><div class="small">access_logs / is_bot = 0</div></div>
            <div class="summary-box"><div class="label">UU数</div><div class="num"><?= number_format((int)($summary['uu'] ?? 0)) ?></div><div class="small">平均：<?= h(ii_daily_average($summary['uu'] ?? 0, $periodDays)) ?>/日（<?= h($periodDays) ?>日間）</div><div class="small">IP＋UAの匿名ハッシュ単位</div></div>
            <div class="summary-box"><div class="label">botアクセス合計</div><div class="num"><?= number_format((int)($botCount['cnt'] ?? 0)) ?></div><div class="small">平均：<?= h(ii_daily_average($botCount['cnt'] ?? 0, $periodDays)) ?>/日（<?= h($periodDays) ?>日間）</div><div class="small">access_bot_daily の集計値</div></div>
            <div class="summary-box"><div class="label">Attack / SQL Injection</div><div class="num"><?= number_format($attackBotCount) ?></div><div class="small">SQL Injection対策で除外</div></div>
            <div class="summary-box"><div class="label">Blocked IP Hash</div><div class="num"><?= number_format($blockedIpBotCount) ?></div><div class="small">匿名IPハッシュ除外</div></div>
            <div class="summary-box"><div class="label">Behavior Bot / Distributed</div><div class="num"><?= number_format($behaviorBotCount) ?></div><div class="small">分散bot行動判定で除外</div></div>
            <div class="summary-box"><div class="label">Behavior Bot / Distributed Work</div><div class="num"><?= number_format($behaviorWorkBotCount) ?></div><div class="small">作品ID単位の分散bot判定で除外</div></div>
        </div>
    </div>

    <div class="card"><h2>アクセス状況 折れ線グラフ</h2><div class="chart-wrap"><canvas id="lineChart" width="1000" height="320"></canvas></div><div class="small">PV / 訪問者数 / UU数の日別推移</div></div>
    <div class="card"><h2>botアクセス 日別グラフ</h2><div class="chart-wrap"><canvas id="botChart" width="1000" height="260"></canvas></div><div class="small">botアクセスは access_logs には保存せず、access_bot_daily の日別集計を表示します。</div></div>

    <div class="grid2">
        <div class="card"><h2>時間別アクセスグラフ</h2><div class="chart-wrap"><canvas id="hourChart" width="1000" height="620"></canvas></div><div class="small">通常アクセスのPVを0時〜23時で集計します。</div></div>
        <div class="card"><h2>曜日別アクセスグラフ</h2><div class="chart-wrap"><canvas id="weekdayChart" width="1000" height="320"></canvas></div><div class="small">通常アクセスのPVを曜日別に集計します。</div></div>
    </div>

    <div class="grid2">
        <div class="card"><h2>デバイス別 棒グラフ</h2><div class="chart-wrap"><canvas id="deviceChart" width="1000" height="300"></canvas></div><div class="small">PC / スマホ / タブレット / その他を横棒で表示します。</div></div>
        <div class="card"><h2>流入元別 棒グラフ</h2><div class="chart-wrap"><canvas id="sourceChart" width="1000" height="520"></canvas></div><div class="small">上位15件まで表示します。</div></div>
    </div>

    <div class="card"><h2>ページ別アクセス 棒グラフ</h2><div class="chart-wrap"><canvas id="pageChart" width="1000" height="560"></canvas></div><div class="small">上位15ページまで表示します。</div></div>

    <div class="grid2">
        <div class="card"><h2>bot別アクセス数</h2><table><thead><tr><th>bot</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($botNameRows as $row): ?><tr><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$botNameRows): ?><tr><td colspan="2">データなし<?= $hasBotTable ? '' : '（access_bot_daily テーブルなし）' ?></td></tr><?php endif; ?></tbody></table></div>
        <div class="card"><h2>botページ別アクセス数</h2><table><thead><tr><th>ページ</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($botPageRows as $row): ?><tr><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$botPageRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table></div>
    </div>

    <div class="grid2">
        <div class="card"><h2>対策bot内訳</h2><table><thead><tr><th>bot分類</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($botProtectionRows as $row): ?><tr><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$botProtectionRows): ?><tr><td colspan="2">データなし<?= $hasBotTable ? '' : '（access_bot_daily テーブルなし）' ?></td></tr><?php endif; ?></tbody></table><div class="small">表示期間内の access_bot_daily を bot_name 別に集計します。</div></div>
        <div class="card"><h2>Behavior Bot / Distributed ページ別</h2><table><thead><tr><th>ページ</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($behaviorBotPageRows as $row): ?><tr><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$behaviorBotPageRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table><div class="small">分散bot判定、作品ID単位の分散bot判定に該当したページ別件数です。</div></div>
    </div>

    <div class="grid2">
        <div class="card"><h2>bot合計の内訳</h2><table><thead><tr><th>bot_name</th><th class="right">件数</th><th class="right">割合</th></tr></thead><tbody><?php foreach ($botBreakdownRows as $row): ?><tr><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td><td class="right"><?= h(ii_percentage($row['cnt'] ?? 0, $botTotalCount)) ?></td></tr><?php endforeach; ?><?php if (!$botBreakdownRows): ?><tr><td colspan="3">データなし<?= $hasBotTable ? '' : '（access_bot_daily テーブルなし）' ?></td></tr><?php endif; ?></tbody></table><div class="small">表示期間内のbot合計が、どの bot_name で集計されているかを表示します。</div></div>
        <div class="card"><h2>分散bot判定メモ</h2><?php if ($behaviorBotCount > 0): ?><p>Behavior Bot / Distributed が表示期間内に発生しています。</p><?php else: ?><p>Behavior Bot / Distributed は表示期間内ではまだ発生していません。</p><p class="small">User-Agentで Googlebot / Bingbot / Other Bot / Crawler / Bot と判定されたアクセスは、Behavior判定より先に既存bot名で access_bot_daily に集計されます。分散bot候補が通常ログ側に残っているかは下の「通常ログ側 分散bot候補」で確認してください。</p><?php endif; ?></div>
    </div>

    <div class="card"><h2>Other Bot User-Agent内訳</h2><?php if (!$hasBotUaTable): ?><p>User-Agent内訳テーブル未作成</p><?php else: ?><table><thead><tr><th>bot_name</th><th>User-Agent</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($botUaRows as $row): ?><tr><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="mono"><?= h($row['user_agent'] ?? '') ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$botUaRows): ?><tr><td colspan="3">データなし</td></tr><?php endif; ?></tbody></table><div class="small">表示期間内の <?= h(ii_bot_display_name($botUaName)) ?> を User-Agent 別に上位50件表示します。上部の選択欄で GPTBot / Amazonbot / Meta External Agent なども確認できます。</div><?php endif; ?></div>

    <div class="card"><h2>直近のbot対策状況</h2><table><thead><tr><th>日付</th><th class="right">Attack / SQL Injection</th><th class="right">Blocked IP Hash</th><th class="right">Behavior Bot / Distributed</th><th class="right">Behavior Bot / Distributed Work</th><th class="right">bot合計</th></tr></thead><tbody><?php foreach ($recentBotProtectionRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="right"><?= number_format((int)$row['attack_count']) ?></td><td class="right"><?= number_format((int)$row['blocked_ip_count']) ?></td><td class="right"><?= number_format((int)$row['behavior_count']) ?></td><td class="right"><?= number_format((int)$row['behavior_work_count']) ?></td><td class="right"><?= number_format((int)$row['bot_total']) ?></td></tr><?php endforeach; ?><?php if (!$recentBotProtectionRows): ?><tr><td colspan="6">データなし<?= $hasBotTable ? '' : '（access_bot_daily テーブルなし）' ?></td></tr><?php endif; ?></tbody></table><div class="small">直近7日間の対策系bot分類とbot合計です。</div></div>

    <div class="card"><h2>直近7日 bot_name別内訳</h2><table><thead><tr><th>日付</th><th>bot_name</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($recentBotNameRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$recentBotNameRows): ?><tr><td colspan="3">データなし<?= $hasBotTable ? '' : '（access_bot_daily テーブルなし）' ?></td></tr><?php endif; ?></tbody></table><div class="small">直近7日間の access_bot_daily を日付・bot_name 別に表示します。</div></div>

    <div class="card"><h2>Normal Log Bot-like User-Agent</h2><table><thead><tr><th>Date</th><th>User-Agent</th><th class="right">PV</th><th class="right">IP</th><th class="right">UU</th><th class="right">Session</th><th class="right">No ref</th><th>First</th><th>Last</th></tr></thead><tbody><?php foreach ($normalBotLikeUaRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="mono"><?= h($row['user_agent'] ?? '') ?></td><td class="right"><?= number_format((int)$row['pv']) ?></td><td class="right"><?= number_format((int)$row['ip_count']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td><td class="right"><?= number_format((int)$row['session_count']) ?></td><td class="right"><?= number_format((int)$row['no_referrer_count']) ?></td><td class="mono"><?= h($row['first_access']) ?></td><td class="mono"><?= h($row['last_access']) ?></td></tr><?php endforeach; ?><?php if (!$normalBotLikeUaRows): ?><tr><td colspan="9">No data</td></tr><?php endif; ?></tbody></table><div class="small">Last 7 days, access_logs / is_bot = 0. Bot-like keywords remaining here should be checked before adding UPDATE rules.</div></div>

    <div class="card"><h2>Normal Log Behavior Candidates</h2><table><thead><tr><th>Date</th><th>Path</th><th class="right">PV</th><th class="right">IP</th><th class="right">UU</th><th class="right">Session</th><th class="right">UA</th><th class="right">No ref</th><th class="right">No ref %</th><th>First</th><th>Last</th></tr></thead><tbody><?php foreach ($normalBehaviorCandidateRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['pv']) ?></td><td class="right"><?= number_format((int)$row['ip_count']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td><td class="right"><?= number_format((int)$row['session_count']) ?></td><td class="right"><?= number_format((int)$row['ua_count']) ?></td><td class="right"><?= number_format((int)$row['no_referrer_count']) ?></td><td class="right"><?= h(ii_percentage($row['no_referrer_count'] ?? 0, $row['pv'] ?? 0)) ?></td><td class="mono"><?= h($row['first_access']) ?></td><td class="mono"><?= h($row['last_access']) ?></td></tr><?php endforeach; ?><?php if (!$normalBehaviorCandidateRows): ?><tr><td colspan="11">No data</td></tr><?php endif; ?></tbody></table><div class="small">Last 7 days. PV, IP, UU, session are close; UA is small; referrer is mostly empty.</div></div>

    <div class="card"><h2>Normal Log Work ID Candidates</h2><table><thead><tr><th>Date</th><th>work_id</th><th>Path</th><th class="right">PV</th><th class="right">IP</th><th class="right">UU</th><th class="right">Session</th><th class="right">UA</th><th class="right">No ref</th><th class="right">No ref %</th><th>First</th><th>Last</th></tr></thead><tbody><?php foreach ($normalWorkCandidateRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="mono"><?= h($row['work_id']) ?></td><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['pv']) ?></td><td class="right"><?= number_format((int)$row['ip_count']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td><td class="right"><?= number_format((int)$row['session_count']) ?></td><td class="right"><?= number_format((int)$row['ua_count']) ?></td><td class="right"><?= number_format((int)$row['no_referrer_count']) ?></td><td class="right"><?= h(ii_percentage($row['no_referrer_count'] ?? 0, $row['pv'] ?? 0)) ?></td><td class="mono"><?= h($row['first_access']) ?></td><td class="mono"><?= h($row['last_access']) ?></td></tr><?php endforeach; ?><?php if (!$normalWorkCandidateRows): ?><tr><td colspan="12">No data</td></tr><?php endif; ?></tbody></table><div class="small">Last 7 days, /indexindex/work.php and /indexindex/work_graph.php by work_id. Use this before ranking correction.</div></div>

    <div class="card"><h2>Normal Log No-referrer Spike</h2><table><thead><tr><th>Date</th><th>Path</th><th class="right">PV</th><th class="right">No ref</th><th class="right">No ref %</th><th class="right">IP</th><th class="right">UU</th><th class="right">Session</th><th class="right">UA</th><th>First</th><th>Last</th></tr></thead><tbody><?php foreach ($normalNoReferrerRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['pv']) ?></td><td class="right"><?= number_format((int)$row['no_referrer_count']) ?></td><td class="right"><?= h(ii_percentage($row['no_referrer_count'] ?? 0, $row['pv'] ?? 0)) ?></td><td class="right"><?= number_format((int)$row['ip_count']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td><td class="right"><?= number_format((int)$row['session_count']) ?></td><td class="right"><?= number_format((int)$row['ua_count']) ?></td><td class="mono"><?= h($row['first_access']) ?></td><td class="mono"><?= h($row['last_access']) ?></td></tr><?php endforeach; ?><?php if (!$normalNoReferrerRows): ?><tr><td colspan="11">No data</td></tr><?php endif; ?></tbody></table><div class="small">Last 7 days, access_logs / is_bot = 0, PV >= 100 and no-referrer ratio >= 80%.</div></div>

    <div class="card"><h2>通常ログ側 分散bot候補</h2><table><thead><tr><th>ページ</th><th class="right">PV</th><th class="right">IP数</th><th class="right">訪問者数</th><th class="right">セッション数</th><th class="right">UA数</th><th class="right">referrer空</th><th class="right">referrer空率</th></tr></thead><tbody><?php foreach ($distributedCandidateRows as $row): ?><tr><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['pv']) ?></td><td class="right"><?= number_format((int)$row['ip_count']) ?></td><td class="right"><?= number_format((int)$row['visitor_count']) ?></td><td class="right"><?= number_format((int)$row['session_count']) ?></td><td class="right"><?= number_format((int)$row['ua_count']) ?></td><td class="right"><?= number_format((int)$row['no_referrer_count']) ?></td><td class="right"><?= h(ii_percentage($row['no_referrer_count'] ?? 0, $row['pv'] ?? 0)) ?></td></tr><?php endforeach; ?><?php if (!$distributedCandidateRows): ?><tr><td colspan="8">データなし</td></tr><?php endif; ?></tbody></table><div class="small">今日の通常ログ（is_bot = 0）に残っている高PVページを表示します。/indexindex/work_graph.php のPV・IP数・セッション数・referrer空率が高い場合は、Behavior Bot / Distributed の判定条件見直し候補です。</div></div>

    <div class="card"><h2>botアクセス詳細</h2><table><thead><tr><th>日付</th><th>ページ</th><th>bot</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($botDetailRows as $row): ?><tr><td><?= h($row['access_date']) ?></td><td class="mono"><?= h($row['path']) ?></td><td><?= h(ii_bot_display_name($row['bot_name'] ?? '')) ?></td><td class="right"><?= number_format((int)$row['count']) ?></td></tr><?php endforeach; ?><?php if (!$botDetailRows): ?><tr><td colspan="4">データなし</td></tr><?php endif; ?></tbody></table></div>

    <div class="grid2">
        <div class="card"><h2>アクセスデバイス</h2><table><thead><tr><th>デバイス</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($deviceRows as $row): ?><tr><td><?= h(ii_device_label($row['device_type'])) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$deviceRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table></div>
        <div class="card"><h2>ブラウザ別</h2><table><thead><tr><th>ブラウザ</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($browserRows as $row): ?><tr><td><?= h($row['browser_name']) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$browserRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table></div>
    </div>

    <div class="card"><h2>ページ別アクセス状況</h2><table><thead><tr><th>ページ</th><th class="right">PV</th><th class="right">UU</th></tr></thead><tbody><?php foreach ($pageRows as $row): ?><tr><td class="mono"><?= h($row['path']) ?></td><td class="right"><?= number_format((int)$row['page_views']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td></tr><?php endforeach; ?><?php if (!$pageRows): ?><tr><td colspan="3">データなし</td></tr><?php endif; ?></tbody></table></div>

    <div class="grid2">
        <div class="card"><h2>アクセス元（流入元）</h2><table><thead><tr><th>流入元</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($sourceRows as $row): ?><tr><td class="mono"><?= h($row['source_name']) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$sourceRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table></div>
        <div class="card"><h2>IPアドレス別</h2><table><thead><tr><th>IP匿名ハッシュ</th><th class="right">PV</th><th class="right">UU</th></tr></thead><tbody><?php foreach ($ipRows as $row): ?><tr><td class="mono"><?= h(mb_substr((string)$row['ip_hash'], 0, 16)) ?>...</td><td class="right"><?= number_format((int)$row['cnt']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td></tr><?php endforeach; ?><?php if (!$ipRows): ?><tr><td colspan="3">データなし</td></tr><?php endif; ?></tbody></table><div class="small">個人情報保護のため、IPは平文保存せずハッシュで保存します。</div></div>
    </div>

    <div class="card"><h2>その他の解析：リンク元</h2><table><thead><tr><th>リンク元URL</th><th class="right">件数</th></tr></thead><tbody><?php foreach ($refRows as $row): ?><tr><td class="mono"><?= h($row['referrer']) ?></td><td class="right"><?= number_format((int)$row['cnt']) ?></td></tr><?php endforeach; ?><?php if (!$refRows): ?><tr><td colspan="2">データなし</td></tr><?php endif; ?></tbody></table></div>

    <div class="card"><h2>月別集計</h2><table><thead><tr><th>月</th><th class="right">PV</th><th class="right">訪問者数</th><th class="right">UU</th><th class="right">bot</th></tr></thead><tbody><?php foreach ($monthlyRows as $row): ?><tr><td><?= h(substr((string)$row['month_start'], 0, 7)) ?></td><td class="right"><?= number_format((int)$row['page_views']) ?></td><td class="right"><?= number_format((int)$row['visitors']) ?></td><td class="right"><?= number_format((int)$row['uu']) ?></td><td class="right"><?= number_format((int)$row['bot_count']) ?></td></tr><?php endforeach; ?><?php if (!$monthlyRows): ?><tr><td colspan="5">データなし</td></tr><?php endif; ?></tbody></table></div>
</div>

<script>
const chartLabels = <?= json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const pvData = <?= json_encode($dailyPv, JSON_UNESCAPED_UNICODE) ?>;
const visitorData = <?= json_encode($dailyVisitors, JSON_UNESCAPED_UNICODE) ?>;
const uuData = <?= json_encode($dailyUu, JSON_UNESCAPED_UNICODE) ?>;
const botLabels = <?= json_encode($botDailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const botData = <?= json_encode($botDailyCounts, JSON_UNESCAPED_UNICODE) ?>;
const hourLabels = <?= json_encode($hourLabels, JSON_UNESCAPED_UNICODE) ?>;
const hourCounts = <?= json_encode($hourCounts, JSON_UNESCAPED_UNICODE) ?>;
const weekdayLabels = <?= json_encode($weekdayLabels, JSON_UNESCAPED_UNICODE) ?>;
const weekdayCounts = <?= json_encode($weekdayCounts, JSON_UNESCAPED_UNICODE) ?>;
const deviceLabels = <?= json_encode($deviceLabels, JSON_UNESCAPED_UNICODE) ?>;
const deviceCounts = <?= json_encode($deviceCounts, JSON_UNESCAPED_UNICODE) ?>;
const pageChartLabels = <?= json_encode($pageChartLabels, JSON_UNESCAPED_UNICODE) ?>;
const pageChartCounts = <?= json_encode($pageChartCounts, JSON_UNESCAPED_UNICODE) ?>;
const sourceChartLabels = <?= json_encode($sourceChartLabels, JSON_UNESCAPED_UNICODE) ?>;
const sourceChartCounts = <?= json_encode($sourceChartCounts, JSON_UNESCAPED_UNICODE) ?>;

drawLineChart('lineChart', chartLabels, [
    {label: 'PV', data: pvData},
    {label: '訪問者', data: visitorData},
    {label: 'UU', data: uuData}
]);
drawLineChart('botChart', botLabels, [{label: 'bot', data: botData}]);
drawHorizontalBarChart('hourChart', hourLabels, hourCounts, {leftPad: 80});
drawHorizontalBarChart('weekdayChart', weekdayLabels, weekdayCounts, {leftPad: 80});
drawHorizontalBarChart('deviceChart', deviceLabels, deviceCounts, {leftPad: 140});
drawHorizontalBarChart('pageChart', pageChartLabels, pageChartCounts, {leftPad: 300, maxLabelLength: 36});
drawHorizontalBarChart('sourceChart', sourceChartLabels, sourceChartCounts, {leftPad: 260, maxLabelLength: 32});

function drawLineChart(canvasId, labels, series) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    const padL = 60;
    const padR = 20;
    const padT = 28;
    const padB = 55;
    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px sans-serif';
    const allValues = series.flatMap(s => s.data);
    const maxVal = Math.max(1, ...allValues);
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
        ctx.fillStyle = '#6b7280';
        ctx.fillText(String(Math.round(maxVal * i / 5)), 8, y + 4);
    }

    const colors = ['#111827', '#4b5563', '#9ca3af'];
    series.forEach((s, idx) => {
        ctx.beginPath();
        ctx.strokeStyle = colors[idx % colors.length];
        ctx.lineWidth = idx === 0 ? 3 : 2;
        s.data.forEach((v, i) => {
            const x = padL + (labels.length <= 1 ? 0 : plotW * i / (labels.length - 1));
            const y = padT + plotH - (plotH * v / maxVal);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.fillStyle = colors[idx % colors.length];
        ctx.fillText(s.label, padL + idx * 70, 16);
    });

    ctx.fillStyle = '#6b7280';
    const step = Math.max(1, Math.ceil(labels.length / 8));
    labels.forEach((label, i) => {
        if (i % step !== 0 && i !== labels.length - 1) return;
        const x = padL + (labels.length <= 1 ? 0 : plotW * i / (labels.length - 1));
        ctx.save();
        ctx.translate(x, height - 14);
        ctx.rotate(-Math.PI / 5);
        ctx.fillText(String(label).substring(5), 0, 0);
        ctx.restore();
    });
}

function drawHorizontalBarChart(canvasId, labels, values, options = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    const leftPad = options.leftPad || 140;
    const rightPad = 70;
    const topPad = 24;
    const rowH = 24;
    const maxLabelLength = options.maxLabelLength || 999;
    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px sans-serif';

    if (!labels.length) {
        ctx.fillStyle = '#6b7280';
        ctx.fillText('データなし', 20, 40);
        return;
    }

    const maxVal = Math.max(1, ...values);
    labels.forEach((label, i) => {
        const y = topPad + i * rowH;
        if (y > height - rowH) return;
        let text = String(label);
        if (text.length > maxLabelLength) text = text.substring(0, maxLabelLength) + '…';
        ctx.fillStyle = '#374151';
        ctx.fillText(text, 10, y + 14);
        const barW = (width - leftPad - rightPad) * (values[i] / maxVal);
        ctx.fillStyle = '#111827';
        ctx.fillRect(leftPad, y + 3, barW, 14);
        ctx.fillStyle = '#6b7280';
        ctx.fillText(String(values[i]), leftPad + barW + 8, y + 14);
    });
}
</script>
</body>
</html>
