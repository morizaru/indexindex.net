<?php
// admin/content_suggest.php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function content_suggest_json(array $rows): void
{
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

function content_suggest_escape_like(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]);
}

$type = (string)($_GET['type'] ?? '');
$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;
$q = mb_convert_kana(trim((string)($_GET['q'] ?? '')), 'as', 'UTF-8');

if (!in_array($type, ['title', 'creator'], true) || $issue_id <= 0 || $q === '') {
    content_suggest_json([]);
}

try {
    $stmt = $pdo->prepare("
        SELECT magazine_id
        FROM magazine_issues
        WHERE id = :issue_id
        LIMIT 1
    ");
    $stmt->execute([
        ':issue_id' => $issue_id,
    ]);
    $issue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$issue || empty($issue['magazine_id'])) {
        content_suggest_json([]);
    }

    $magazine_id = (int)$issue['magazine_id'];
    $like = '%' . content_suggest_escape_like($q) . '%';
    $prefix_like = content_suggest_escape_like($q) . '%';

    if ($type === 'title') {
        $stmt = $pdo->prepare("
            SELECT
                ic.title_main AS value,
                MIN(CASE WHEN ic.title_main LIKE :prefix_like ESCAPE '\\\\' THEN 0 ELSE 1 END) AS match_rank
            FROM issue_contents ic
            JOIN magazine_issues mi ON ic.issue_id = mi.id
            WHERE mi.magazine_id = :magazine_id
              AND ic.title_main IS NOT NULL
              AND ic.title_main <> ''
              AND ic.title_main LIKE :like_value ESCAPE '\\\\'
            GROUP BY ic.title_main
            ORDER BY match_rank ASC, value ASC
            LIMIT 20
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT
                c.name AS value,
                MIN(CASE WHEN c.name LIKE :prefix_like ESCAPE '\\\\' THEN 0 ELSE 1 END) AS match_rank
            FROM creators c
            JOIN content_creators cc ON c.id = cc.creator_id
            JOIN issue_contents ic ON cc.content_id = ic.id
            JOIN magazine_issues mi ON ic.issue_id = mi.id
            WHERE mi.magazine_id = :magazine_id
              AND c.name IS NOT NULL
              AND c.name <> ''
              AND c.name LIKE :like_value ESCAPE '\\\\'
            GROUP BY c.name
            ORDER BY match_rank ASC, value ASC
            LIMIT 20
        ");
    }

    $stmt->execute([
        ':magazine_id' => $magazine_id,
        ':like_value' => $like,
        ':prefix_like' => $prefix_like,
    ]);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['value'] ?? ''));
        if ($value !== '') {
            $results[] = ['value' => $value];
        }
    }

    content_suggest_json($results);
} catch (Throwable $e) {
    content_suggest_json([]);
}
