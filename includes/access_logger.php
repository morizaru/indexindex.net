<?php
// INDEXINDEX アクセスログ記録
// 設置例：公開側PHPの先頭付近で以下を読み込む
//
// INDEXINDEX配下のページ:
// require_once __DIR__ . '/app/config.php';
// $GLOBALS['pdo'] = $pdo;
//
// require_once __DIR__ . '/includes/access_logger.php';
// ii_access_log('ページタイトル');
//
// morizaru.net/index.php の場合:
// require_once __DIR__ . '/indexindex/includes/access_logger.php';
// ii_access_log('トップページ');
//
// 仕様：
// ・通常アクセスは access_logs に保存
// ・botアクセスは access_logs には保存しない
// ・botアクセス数だけ access_bot_daily に日別・ページ別・bot別で集計保存
// ・/indexindex/ 配下に加えて、morizaru.net/index.php も記録対象

if (!function_exists('ii_access_log')) {
    function ii_access_log($pageTitle = null) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pathOnly = parse_url($requestUri, PHP_URL_PATH) ?: '';

        // 解析対象
        // 1. /indexindex/ 配下
        // 2. morizaru.net/index.php
        // 3. morizaru.net/ でトップページが表示される場合
        $isIndexindexPage = (strpos($pathOnly, '/indexindex/') === 0);
        $isRootIndexPage = ($pathOnly === '/index.php' || $pathOnly === '/');

        if (!$isIndexindexPage && !$isRootIndexPage) {
            return;
        }

        // 管理画面・静的ファイルは除外
        if (strpos($pathOnly, '/indexindex/admin/') === 0) {
            return;
        }

        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|map|woff|woff2|ttf|eot)$/i', $pathOnly)) {
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            return;
        }

        $pdo = ii_access_get_pdo();
        if (!$pdo) {
            return;
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = ii_access_get_client_ip();
        $salt = defined('II_ACCESS_LOG_SALT') ? II_ACCESS_LOG_SALT : 'indexindex_access_log_salt_change_me';

        $ipHash = hash('sha256', $salt . '|ip|' . $ip);
        $visitorHash = hash('sha256', $salt . '|visitor|' . $ip . '|' . $ua);

        if (ii_access_is_blocked_ip_hash($ipHash)) {
            ii_access_count_bot($pdo, $pathOnly, $ua, 'Blocked IP Hash');
            return;
        }

        $sessionId = $_COOKIE['ii_access_sid'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $sessionId)) {
            $sessionId = hash('sha256', $salt . '|session|' . $ip . '|' . $ua . '|' . bin2hex(random_bytes(16)));

            if (!headers_sent()) {
                setcookie('ii_access_sid', $sessionId, [
                    'expires' => time() + 1800,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        } else {
            if (!headers_sent()) {
                setcookie('ii_access_sid', $sessionId, [
                    'expires' => time() + 1800,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }

        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $refHost = null;
        if ($referrer) {
            $refHost = parse_url($referrer, PHP_URL_HOST);
            if ($refHost !== null) {
                $refHost = mb_substr($refHost, 0, 255);
            }
        }

        $browser = ii_access_detect_browser($ua);
        $os = ii_access_detect_os($ua);
        $device = ii_access_detect_device($ua);
        $isBot = ii_access_is_bot($ua) ? 1 : 0;

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'morizaru.net';
        $fullUrl = $scheme . '://' . $host . $requestUri;
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        if (ii_access_is_attack_request($queryString, $requestUri, $fullUrl)) {
            ii_access_count_bot($pdo, $pathOnly, $ua, 'Attack / SQL Injection');
            return;
        }

        // botは生ログに保存せず、日別集計だけ保存する
        if ($isBot === 1) {
            ii_access_count_bot($pdo, $pathOnly, $ua);
            return;
        }

        if (ii_access_is_distributed_behavior_bot($pdo, $pathOnly, $referrer)) {
            ii_access_count_bot($pdo, $pathOnly, $ua, 'Behavior Bot / Distributed');
            return;
        }

        $statusCode = http_response_code();
        if (!$statusCode || $statusCode < 100) {
            $statusCode = null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO access_logs (
                    access_time, site_key, path, query_string, full_url, page_title,
                    method, status_code, referrer, referrer_host,
                    user_agent, device_type, browser_name, os_name,
                    ip_hash, visitor_hash, visitor_session_id, is_bot
                ) VALUES (
                    NOW(), 'indexindex', :path, :query_string, :full_url, :page_title,
                    :method, :status_code, :referrer, :referrer_host,
                    :user_agent, :device_type, :browser_name, :os_name,
                    :ip_hash, :visitor_hash, :visitor_session_id, :is_bot
                )
            ");

            $stmt->execute([
                ':path' => mb_substr($pathOnly, 0, 500),
                ':query_string' => $_SERVER['QUERY_STRING'] ?? null,
                ':full_url' => $fullUrl,
                ':page_title' => $pageTitle ? mb_substr($pageTitle, 0, 255) : null,
                ':method' => $method,
                ':status_code' => $statusCode,
                ':referrer' => $referrer,
                ':referrer_host' => $refHost,
                ':user_agent' => $ua,
                ':device_type' => $device,
                ':browser_name' => $browser,
                ':os_name' => $os,
                ':ip_hash' => $ipHash,
                ':visitor_hash' => $visitorHash,
                ':visitor_session_id' => $sessionId,
                ':is_bot' => $isBot,
            ]);
        } catch (Throwable $e) {
            // 公開ページを止めないため、ログ記録失敗は無視
            return;
        }
    }

    function ii_access_get_pdo() {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // 公開ページ側で $GLOBALS['pdo'] を入れている場合はそれを優先
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            return $pdo;
        }

        // /indexindex/includes/access_logger.php から見て
        // /indexindex/app/config.php を読み込む
        $configFile = __DIR__ . '/../app/config.php';

        if (!is_file($configFile)) {
            return null;
        }

        require_once $configFile;

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            return $pdo;
        }

        if (isset($pdo) && $pdo instanceof PDO) {
            $GLOBALS['pdo'] = $pdo;
            return $pdo;
        }

        return null;
    }

    function ii_access_get_client_ip() {
        // 通常はREMOTE_ADDRでOK。Cloudflare等を使う場合はHTTP_CF_CONNECTING_IPを優先。
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    function ii_access_detect_device($ua) {
        $uaLower = strtolower($ua);

        if (preg_match('/ipad|tablet|kindle|silk|playbook|nexus 7|nexus 9|sm-t|xoom/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|mobile/i', $uaLower)) {
            return 'smartphone';
        }

        if (preg_match('/windows|macintosh|linux|x11/i', $ua)) {
            return 'pc';
        }

        return 'other';
    }

    function ii_access_detect_browser($ua) {
        if (stripos($ua, 'Edg/') !== false) return 'Edge';
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
        if (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) return 'Chrome';
        if (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome/') === false) return 'Safari';
        if (stripos($ua, 'Firefox/') !== false) return 'Firefox';
        if (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident/') !== false) return 'Internet Explorer';
        return 'Other';
    }

    function ii_access_detect_os($ua) {
        if (stripos($ua, 'Windows NT') !== false) return 'Windows';
        if (stripos($ua, 'Mac OS X') !== false) return 'macOS';
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
        if (stripos($ua, 'Android') !== false) return 'Android';
        if (stripos($ua, 'Linux') !== false) return 'Linux';
        return 'Other';
    }

    function ii_access_is_bot($ua) {
        if ($ua === '') return false;

        return (bool)preg_match(
            '/bot|crawl|spider|slurp|bingpreview|meta-externalagent|facebookexternalhit|facebot|line-poker|discordbot|twitterbot|google-inspectiontool|petalbot|bytespider|semrushbot|ahrefsbot|mj12bot|dotbot|yandexbot/i',
            $ua
        );
    }

    function ii_access_is_blocked_ip_hash(string $ipHash): bool {
        // 手動調査により自動巡回系と判断した匿名IPハッシュ
        $blockedPrefixes = [
            'c2ccff14ad22a264',
            '15fa45b8403dd3f0',
            'd6b9feadbb427e51',
            '989bbc0d8f4f1def',
        ];

        foreach ($blockedPrefixes as $prefix) {
            if (strpos($ipHash, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    function ii_access_is_attack_request(string $queryString, string $requestUri, string $fullUrl): bool {
        $targets = [
            $queryString,
            $requestUri,
            $fullUrl,
            rawurldecode($queryString),
            rawurldecode($requestUri),
            rawurldecode($fullUrl),
        ];

        $pattern = '/(?:\bunion\b|\bunion\s+all\s+select\b|\bselect\b|\bextractvalue\b|\bsleep\s*\(|\bbenchmark\s*\(|\bcast\s*\(|\bchr\s*\(|\bconcat\s*\(|\binformation_schema\b|\border\s+by\b|\bor\s+1\s*=\s*1\b|\band\s+1\s*=\s*1\b|--|\/\*|%27|%22)/i';

        foreach ($targets as $target) {
            if ($target !== '' && preg_match($pattern, $target)) {
                return true;
            }
        }

        return false;
    }

    function ii_access_is_distributed_behavior_bot(PDO $pdo, string $path, ?string $referrer): bool {
        if ($path !== '/indexindex/work_graph.php') {
            return false;
        }

        if (trim((string)$referrer) !== '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS pv,
                    SUM(CASE WHEN referrer IS NULL OR referrer = '' THEN 1 ELSE 0 END) AS empty_referrer_count,
                    COUNT(DISTINCT ip_hash) AS distinct_ip_count,
                    COUNT(DISTINCT user_agent) AS distinct_user_agent_count
                FROM access_logs
                WHERE access_time >= (NOW() - INTERVAL 30 MINUTE)
                  AND path = :path
                  AND is_bot = 0
            ");
            $stmt->execute([
                ':path' => $path,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $pv = (int)($row['pv'] ?? 0);
            if ($pv < 300) {
                return false;
            }

            $emptyReferrerCount = (int)($row['empty_referrer_count'] ?? 0);
            $distinctIpCount = (int)($row['distinct_ip_count'] ?? 0);
            $distinctUserAgentCount = (int)($row['distinct_user_agent_count'] ?? 0);

            if (($emptyReferrerCount / $pv) < 0.9) {
                return false;
            }

            if ($distinctIpCount < ($pv * 0.5)) {
                return false;
            }

            if ($distinctUserAgentCount <= 5) {
                return true;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM access_logs
                WHERE access_time >= (NOW() - INTERVAL 30 MINUTE)
                  AND path = :path
                  AND is_bot = 0
                GROUP BY user_agent
                ORDER BY cnt DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':path' => $path,
            ]);
            $uaRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $topUserAgentCount = (int)($uaRow['cnt'] ?? 0);

            return $topUserAgentCount >= ($pv * 0.5);
        } catch (Throwable $e) {
            // 公開ページを止めないため、行動パターン判定失敗は通常アクセスとして扱う
            return false;
        }
    }

    function ii_access_count_bot(PDO $pdo, string $path, string $ua, ?string $botName = null): void {
        if ($botName === null) {
            $botName = ii_access_detect_bot_name($ua);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO access_bot_daily (
                    access_date,
                    path,
                    bot_name,
                    count
                ) VALUES (
                    CURDATE(),
                    :path,
                    :bot_name,
                    1
                )
                ON DUPLICATE KEY UPDATE
                    count = count + 1,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                ':path' => mb_substr($path, 0, 500),
                ':bot_name' => $botName,
            ]);
        } catch (Throwable $e) {
            // 公開ページを止めないため、bot集計失敗は無視
            return;
        }
    }

    function ii_access_detect_bot_name(string $ua): string {
        if (stripos($ua, 'Googlebot') !== false) return 'Googlebot';
        if (stripos($ua, 'Google-InspectionTool') !== false) return 'Google Inspection';
        if (stripos($ua, 'Bingbot') !== false) return 'Bingbot';
        if (stripos($ua, 'Slurp') !== false) return 'Yahoo Slurp';
        if (stripos($ua, 'meta-externalagent') !== false) return 'Meta External Agent';
        if (stripos($ua, 'facebookexternalhit') !== false) return 'Facebook';
        if (stripos($ua, 'Facebot') !== false) return 'Facebook';
        if (stripos($ua, 'Twitterbot') !== false) return 'Twitterbot';
        if (stripos($ua, 'Discordbot') !== false) return 'Discordbot';
        if (stripos($ua, 'PetalBot') !== false) return 'PetalBot';
        if (stripos($ua, 'Bytespider') !== false) return 'Bytespider';
        if (stripos($ua, 'SemrushBot') !== false) return 'SemrushBot';
        if (stripos($ua, 'AhrefsBot') !== false) return 'AhrefsBot';
        if (stripos($ua, 'MJ12bot') !== false) return 'MJ12bot';
        if (stripos($ua, 'DotBot') !== false) return 'DotBot';
        if (stripos($ua, 'YandexBot') !== false) return 'YandexBot';
        if (stripos($ua, 'bot') !== false) return 'Other Bot';
        if (stripos($ua, 'crawl') !== false) return 'Crawler';
        if (stripos($ua, 'spider') !== false) return 'Spider';

        return 'Bot';
    }
}
