<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (
        defined('DB_HOST') &&
        defined('DB_NAME') &&
        defined('DB_USER') &&
        defined('DB_PASS')
    ) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        exit('PDO接続情報が見つかりません。');
    }
}

function redirect_to_issues(int $magazine_id = 0, string $delete_result = ''): void
{
    $query = [];

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($delete_result !== '') {
        $query['delete_result'] = $delete_result;
    }

    $query_string = http_build_query($query);
    header('Location: issues.php' . ($query_string !== '' ? '?' . $query_string : ''));
    exit;
}

$issue_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirect_magazine_id = 0;

if ($issue_id <= 0) {
    redirect_to_issues(0, 'invalid');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            magazine_id
        FROM magazine_issues
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $issue_id,
    ]);
    $issue = $stmt->fetch();

    if (!$issue) {
        redirect_to_issues(0, 'not_found');
    }

    $magazine_id = (int)$issue['magazine_id'];
    $redirect_magazine_id = $magazine_id;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM issue_contents
        WHERE issue_id = :issue_id
    ");
    $stmt->execute([
        ':issue_id' => $issue_id,
    ]);
    $row = $stmt->fetch();

    if ((int)($row['cnt'] ?? 0) > 0) {
        redirect_to_issues($magazine_id, 'has_contents');
    }

    $stmt = $pdo->prepare("
        DELETE FROM magazine_issues
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $issue_id,
    ]);

    redirect_to_issues($magazine_id, 'deleted');
} catch (Exception $e) {
    redirect_to_issues($redirect_magazine_id, 'error');
}
