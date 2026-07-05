<?php
// admin/site_stats_snapshot_cron.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

date_default_timezone_set('Asia/Tokyo');

function get_site_stats_columns(PDO $pdo): array {
    $stmt = $pdo->query("SHOW COLUMNS FROM site_stats_daily");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $columns[$row['Field']] = true;
        }
    }

    return $columns;
}

function require_site_stats_columns(array $columns): void {
    $required = ['snapshot_at', 'magazine_count', 'issue_count', 'content_count', 'creator_count'];

    foreach ($required as $column) {
        if (empty($columns[$column])) {
            throw new Exception('site_stats_daily に必要なカラムがありません: ' . $column);
        }
    }
}

function get_current_site_stats(PDO $pdo): array {
    return [
        'snapshot_at' => date('Y-m-d 00:00:00'),
        'magazine_count' => (int)$pdo->query("
            SELECT COUNT(DISTINCT m.id)
            FROM magazines m
            JOIN magazine_issues mi ON mi.magazine_id = m.id
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ")->fetchColumn(),
        'issue_count' => (int)$pdo->query("
            SELECT COUNT(DISTINCT mi.id)
            FROM magazine_issues mi
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ")->fetchColumn(),
        'content_count' => (int)$pdo->query("
            SELECT COUNT(DISTINCT w.id)
            FROM works w
            JOIN issue_contents ic ON ic.work_id = w.id
            JOIN magazine_issues mi ON mi.id = ic.issue_id
            WHERE mi.status = 'approved'
        ")->fetchColumn(),
        'creator_count' => (int)$pdo->query("
            SELECT COUNT(DISTINCT c.id)
            FROM creators c
            JOIN content_creators cc ON cc.creator_id = c.id
            JOIN issue_contents ic ON ic.id = cc.content_id
            JOIN magazine_issues mi ON mi.id = ic.issue_id
            WHERE mi.status = 'approved'
              AND cc.is_visible = 1
        ")->fetchColumn(),
    ];
}

function save_site_stats_snapshot(PDO $pdo, array $stats): string {
    $snapshot_at = (new DateTime((string)$stats['snapshot_at']))->format('Y-m-d 00:00:00');

    $stmt = $pdo->prepare("
        SELECT id
        FROM site_stats_daily
        WHERE snapshot_at = :snapshot_at
        LIMIT 1
    ");
    $stmt->execute([
        ':snapshot_at' => $snapshot_at
    ]);
    $existing_id = (int)($stmt->fetchColumn() ?: 0);

    if ($existing_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE site_stats_daily
            SET
                magazine_count = :magazine_count,
                issue_count = :issue_count,
                content_count = :content_count,
                creator_count = :creator_count
            WHERE id = :id
        ");
        $stmt->execute([
            ':magazine_count' => (int)$stats['magazine_count'],
            ':issue_count' => (int)$stats['issue_count'],
            ':content_count' => (int)$stats['content_count'],
            ':creator_count' => (int)$stats['creator_count'],
            ':id' => $existing_id,
        ]);
        return 'updated';
    }

    $stmt = $pdo->prepare("
        INSERT INTO site_stats_daily (
            snapshot_at,
            magazine_count,
            issue_count,
            content_count,
            creator_count
        ) VALUES (
            :snapshot_at,
            :magazine_count,
            :issue_count,
            :content_count,
            :creator_count
        )
    ");
    $stmt->execute([
        ':snapshot_at' => $snapshot_at,
        ':magazine_count' => (int)$stats['magazine_count'],
        ':issue_count' => (int)$stats['issue_count'],
        ':content_count' => (int)$stats['content_count'],
        ':creator_count' => (int)$stats['creator_count'],
    ]);

    return 'inserted';
}

try {
    require_site_stats_columns(get_site_stats_columns($pdo));
    $stats = get_current_site_stats($pdo);
    $result = save_site_stats_snapshot($pdo, $stats);

    echo "site_stats_daily snapshot {$result}: " . $stats['snapshot_at'] . "\n";
    echo "magazines: " . $stats['magazine_count'] . "\n";
    echo "issues: " . $stats['issue_count'] . "\n";
    echo "works: " . $stats['content_count'] . "\n";
    echo "creators: " . $stats['creator_count'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "site_stats_daily snapshot failed: " . $e->getMessage() . "\n");
    exit(1);
}
