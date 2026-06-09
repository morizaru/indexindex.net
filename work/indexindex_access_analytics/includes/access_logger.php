<?php
// INDEXINDEX アクセスログ記録
// 設置例：公開側PHPの先頭付近で以下を読み込む
// require_once __DIR__ . '/includes/access_logger.php';
// ii_access_log('ページタイトル');

if (!function_exists('ii_access_log')) {
    function ii_access_log($pageTitle = null) {
        // 解析対象を /indexindex/ 配下に限定
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pathOnly = parse_url($requestUri, PHP_URL_PATH) ?: '';

        if (strpos($pathOnly, '/indexindex/') !== 0) {
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

        $sessionId = $_COOKIE['ii_access_sid'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $sessionId)) {
            $sessionId = hash('sha256', $salt . '|session|' . $ip . '|' . $ua . '|' . bin2hex(random_bytes(16)));
            if (!headers_sent()) {
                setcookie('ii_access_sid', $sessionId, [
                    'expires' => time() + 1800,
                    'path' => '/indexindex/',
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        } else {
            if (!headers_sent()) {
                setcookie('ii_access_sid', $sessionId, [
                    'expires' => time() + 1800,
                    'path' => '/indexindex/',
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

        // 既存config.phpの場所が違う場合はここへ追加してください
        $candidates = [
            __DIR__ . '/../includes/config.php',
            __DIR__ . '/../config.php',
            __DIR__ . '/../../config.php',
            __DIR__ . '/../admin/config.php',
            __DIR__ . '/../admin/app/config.php',
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
        return (bool)preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|line-poker|discordbot|twitterbot|google-inspectiontool/i', $ua);
    }
}
