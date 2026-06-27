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
                w.id,
                w.title AS value,
                MIN(CASE WHEN w.title LIKE :prefix_like ESCAPE '\\\\' THEN 0 ELSE 1 END) AS match_rank
            FROM works w
            JOIN issue_contents ic ON ic.work_id = w.id
            JOIN magazine_issues mi ON mi.id = ic.issue_id
            WHERE mi.magazine_id = :magazine_id
              AND w.title IS NOT NULL
              AND w.title <> ''
              AND w.title LIKE :like_value ESCAPE '\\\\'
            GROUP BY w.id, w.title
            ORDER BY match_rank ASC, w.title ASC
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

    $params = [
        ':like_value' => $like,
        ':prefix_like' => $prefix_like,
    ];

    if ($type === 'title' || $type === 'creator') {
        $params[':magazine_id'] = $magazine_id;
    }

    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['value'] ?? ''));
        if ($value !== '') {
            $result = ['value' => $value];
            if (isset($row['id'])) {
                $result['work_id'] = (int)$row['id'];
            }
            $results[] = $result;
        }
    }

    if ($type === 'title' && empty($results)) {
        $stmt = $pdo->prepare("
            SELECT
                w.id,
                w.title AS value,
                CASE WHEN w.title LIKE :prefix_like ESCAPE '\\\\' THEN 0 ELSE 1 END AS match_rank
            FROM works w
            WHERE w.title IS NOT NULL
              AND w.title <> ''
              AND w.title LIKE :like_value ESCAPE '\\\\'
            ORDER BY match_rank ASC, w.title ASC
            LIMIT 20
        ");
        $stmt->execute([
            ':like_value' => $like,
            ':prefix_like' => $prefix_like,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value !== '') {
                $results[] = [
                    'value' => $value,
                    'label' => '全作品：' . $value,
                    'work_id' => (int)$row['id'],
                ];
            }
        }
    }

    content_suggest_json($results);
} catch (Throwable $e) {
    content_suggest_json([]);
}
