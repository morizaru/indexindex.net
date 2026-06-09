<?php
// admin/ajax_magazines_by_publisher.php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

$q = trim((string)($_GET['q'] ?? ''));
$publisher = trim((string)($_GET['publisher'] ?? ''));

$where = [
    "m.name IS NOT NULL",
    "m.name <> ''",
];
$params = [];

if ($q !== '') {
    $where[] = "m.name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

if ($publisher !== '') {
    $where[] = "p.name = :publisher";
    $params[':publisher'] = $publisher;
}

$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.name AS magazine_name,
        p.name AS publisher
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    WHERE " . implode("\n      AND ", $where) . "
    ORDER BY m.name ASC, p.name ASC
    LIMIT 20
");
$stmt->execute($params);

$items = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $items[] = [
        'id' => (int)$row['id'],
        'magazine_name' => (string)($row['magazine_name'] ?? ''),
        'publisher' => (string)($row['publisher'] ?? ''),
    ];
}

echo json_encode($items, JSON_UNESCAPED_UNICODE);
