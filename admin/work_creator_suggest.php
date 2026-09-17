<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/work_creators.php';
$q = trim((string)($_GET['q'] ?? ''));
try {
    $editor = new WorkCreatorEditor($pdo);
    echo json_encode($q === '' ? [] : $editor->search($q), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => '人物候補を取得できませんでした。'], JSON_UNESCAPED_UNICODE);
}
