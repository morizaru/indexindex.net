<?php
// admin/ajax_publishers.php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

$q = trim((string)($_GET['q'] ?? ''));

$where = [
    "p.name IS NOT NULL",
    "p.name <> ''",
];
$params = [];

if ($q !== '') {
    $where[] = "p.name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

$stmt = $pdo->prepare("
    SELECT DISTINCT p.name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    WHERE " . implode("\n      AND ", $where) . "
    ORDER BY p.name ASC
    LIMIT 20
");
$stmt->execute($params);

$items = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') {
        $items[] = [
            'name' => $name,
        ];
    }
}

echo json_encode($items, JSON_UNESCAPED_UNICODE);
