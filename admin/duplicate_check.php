<?php
// admin/duplicate_check.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_name_for_duplicate_check($value): string {
    $value = trim((string)$value);
    $value = str_replace("\xE3\x80\x80", ' ', $value);

    if (function_exists('mb_convert_kana')) {
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');
    }

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    $value = preg_replace('/[\s\x{00B7}\x{30FB}\x{FF65}\.\x{FF0E}\x{3002}\x{3001},\x{FF0C}\-\x{2010}-\x{2015}\x{30FC}\x{FF70}\(\)\x{FF08}\x{FF09}\[\]\x{FF3B}\x{FF3D}\{\}\x{FF5B}\x{FF5D}\x{3008}\x{3009}\x{300A}\x{300B}\x{300C}\x{300D}\x{300E}\x{300F}\/\x{FF0F}\\\\:\x{FF1A};\x{FF1B}\'"\x{2019}\x{201D}]/u', '', $value);
    $value = preg_replace('/[[:cntrl:]]/u', '', $value);

    return $value ?? '';
}

function normalized_prefix(string $value, int $length = 3): string {
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    return substr($value, 0, $length);
}

function first_search_char(string $value): string {
    $value = normalize_name_for_duplicate_check($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 1, 'UTF-8');
    }

    return substr($value, 0, 1);
}

function should_include_candidate(string $label, string $normalized_label, string $q, string $normalized_q, string $mode): bool {
    if ($mode === 'all') {
        return true;
    }

    if ($q === '') {
        return false;
    }

    if ($mode === 'contains') {
        return stripos($label, $q) !== false;
    }

    if ($mode === 'normalized') {
        return $normalized_q !== '' && strpos($normalized_label, $normalized_q) !== false;
    }

    if ($mode === 'prefix') {
        $prefix_length = max(2, min(4, function_exists('mb_strlen') ? mb_strlen($normalized_q, 'UTF-8') : strlen($normalized_q)));
        return $normalized_q !== '' && normalized_prefix($normalized_label, $prefix_length) === normalized_prefix($normalized_q, $prefix_length);
    }

    return false;
}

function placeholders(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}

function fetch_creator_counts(PDO $pdo, array $ids): array {
    if (empty($ids)) {
        return [];
    }

    $sql = "
        SELECT
            c.id,
            COUNT(DISTINCT cc.content_id) AS content_count,
            COUNT(DISTINCT ic.work_id) AS work_count
        FROM creators c
        LEFT JOIN content_creators cc ON cc.creator_id = c.id
        LEFT JOIN issue_contents ic ON cc.content_id = ic.id
        WHERE c.id IN (" . placeholders($ids) . ")
        GROUP BY c.id
    ";

    $stmt = $pdo->prepare($sql);
    foreach (array_values($ids) as $index => $id) {
        $stmt->bindValue($index + 1, (int)$id, PDO::PARAM_INT);
    }
    $stmt->execute();

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int)$row['id']] = [
            'content_count' => (int)($row['content_count'] ?? 0),
            'work_count' => (int)($row['work_count'] ?? 0),
        ];
    }

    return $counts;
}

function fetch_work_counts(PDO $pdo, array $ids): array {
    if (empty($ids)) {
        return [];
    }

    $sql = "
        SELECT
            w.id,
            COUNT(DISTINCT ic.id) AS content_count,
            COUNT(DISTINCT cc.creator_id) AS creator_count
        FROM works w
        LEFT JOIN issue_contents ic ON ic.work_id = w.id
        LEFT JOIN content_creators cc ON cc.content_id = ic.id
        WHERE w.id IN (" . placeholders($ids) . ")
        GROUP BY w.id
    ";

    $stmt = $pdo->prepare($sql);
    foreach (array_values($ids) as $index => $id) {
        $stmt->bindValue($index + 1, (int)$id, PDO::PARAM_INT);
    }
    $stmt->execute();

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int)$row['id']] = [
            'content_count' => (int)($row['content_count'] ?? 0),
            'creator_count' => (int)($row['creator_count'] ?? 0),
        ];
    }

    return $counts;
}

function fetch_work_creator_candidates(PDO $pdo, string $q, string $mode, string $q_head, int $candidate_fetch_limit): array {
    $where_sql = '';
    $params = [];

    if ($q !== '' && $mode === 'contains') {
        $where_sql = "
            WHERE LOWER(COALESCE(NULLIF(w.title, ''), ic.title_main, '')) LIKE :q
               OR LOWER(COALESCE(w.title_sub, '')) LIKE :q
               OR LOWER(COALESCE(w.title_kana, '')) LIKE :q
               OR LOWER(COALESCE(ic.title_main, '')) LIKE :q
        ";
        $params[':q'] = '%' . strtolower($q) . '%';
    } elseif ($q_head !== '' && in_array($mode, ['normalized', 'prefix'], true)) {
        $where_sql = "
            WHERE LOWER(COALESCE(NULLIF(w.title, ''), ic.title_main, '')) LIKE :q_head
               OR LOWER(COALESCE(w.title_sub, '')) LIKE :q_head
               OR LOWER(COALESCE(w.title_kana, '')) LIKE :q_head
               OR LOWER(COALESCE(ic.title_main, '')) LIKE :q_head
        ";
        $params[':q_head'] = '%' . strtolower($q_head) . '%';
    }

    $sql = "
        SELECT
            ic.id,
            ic.id AS content_id,
            w.id AS work_id,
            COALESCE(NULLIF(w.title, ''), ic.title_main) AS title,
            w.title AS work_title,
            ic.title_main,
            w.title_sub,
            w.title_kana,
            w.publication_type,
            mi.issue_label,
            m.name AS magazine_name,
            GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ',') AS creator_ids,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ' / ') AS creator_names,
            1 AS content_count
        FROM issue_contents ic
        LEFT JOIN works w ON w.id = ic.work_id
        LEFT JOIN content_creators cc ON cc.content_id = ic.id AND cc.is_visible = 1
        LEFT JOIN creators c ON c.id = cc.creator_id
        LEFT JOIN magazine_issues mi ON mi.id = ic.issue_id
        LEFT JOIN magazines m ON m.id = mi.magazine_id
        {$where_sql}
        GROUP BY
            ic.id,
            ic.title_main,
            w.id,
            w.title,
            w.title_sub,
            w.title_kana,
            w.publication_type,
            mi.issue_label,
            m.name
        ORDER BY title ASC, work_id ASC, content_id ASC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $candidate_fetch_limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function build_duplicate_url(string $type, string $q, string $mode, int $limit, int $page = 1): string {
    $query = [
        'type' => $type,
        'q' => $q,
        'mode' => $mode,
        'limit' => $limit,
    ];

    if ($page > 1) {
        $query['page'] = $page;
    }

    return 'duplicate_check.php?' . http_build_query($query);
}

function sanitize_positive_ids($values): array {
    if (!is_array($values)) {
        return [];
    }

    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function sql_id_list(array $ids): string {
    return implode(', ', array_map('intval', $ids));
}

function build_merge_sql_output(string $type, array $merge_ids, int $target_id): array {
    $errors = [];

    if (!in_array($type, ['creators', 'works'], true)) {
        $errors[] = 'type が不正です。';
    }

    if ($target_id <= 0) {
        $errors[] = '統合先IDを指定してください。';
    }

    if (count($merge_ids) < 2) {
        $errors[] = '統合対象IDを2件以上選択してください。';
    }

    if ($target_id > 0 && !in_array($target_id, $merge_ids, true)) {
        $errors[] = '統合先IDは選択したIDの中から指定してください。';
    }

    $source_ids = array_values(array_filter($merge_ids, static function ($id) use ($target_id) {
        return (int)$id !== $target_id;
    }));

    if ($target_id > 0 && empty($source_ids)) {
        $errors[] = '統合先ID以外の統合元IDを1件以上選択してください。';
    }

    if (!empty($errors)) {
        return [
            'errors' => $errors,
            'precheck' => '',
            'merge' => '',
            'postcheck' => '',
        ];
    }

    $all_ids = sql_id_list($merge_ids);
    $source_id_list = sql_id_list($source_ids);

    if ($type === 'creators') {
        $precheck = "-- ========================================\n"
            . "-- 事前チェックSQL（人物統合）\n"
            . "-- ========================================\n\n"
            . "-- 統合対象の人物確認\n"
            . "SELECT *\n"
            . "FROM creators\n"
            . "WHERE id IN ({$all_ids});\n\n"
            . "-- 対象人物が紐づく目次件数確認\n"
            . "SELECT creator_id, COUNT(*) AS content_count\n"
            . "FROM content_creators\n"
            . "WHERE creator_id IN ({$all_ids})\n"
            . "GROUP BY creator_id\n"
            . "ORDER BY creator_id;\n\n"
            . "-- 現状で同一 content_id / creator_id が重複していないか確認\n"
            . "SELECT content_id, creator_id, COUNT(*) AS duplicate_count\n"
            . "FROM content_creators\n"
            . "WHERE creator_id IN ({$all_ids})\n"
            . "GROUP BY content_id, creator_id\n"
            . "HAVING COUNT(*) > 1;\n\n"
            . "-- 統合先へ寄せた後に同じ content_id で重複する可能性の確認\n"
            . "SELECT content_id, COUNT(*) AS duplicate_count\n"
            . "FROM content_creators\n"
            . "WHERE creator_id IN ({$all_ids})\n"
            . "GROUP BY content_id\n"
            . "HAVING COUNT(*) >= 2;\n";

        $merge = "-- ========================================\n"
            . "-- 統合SQL（人物統合）\n"
            . "-- ========================================\n\n"
            . "-- 注意：実行前に必ずバックアップしてください\n"
            . "-- 注意：この画面ではSQLを出力するだけで、実行はしません\n"
            . "-- 注意：事前チェックSQLで同一 content_id の重複可能性を確認してください\n\n"
            . "-- 統合元の creator_id を統合先IDへ付け替え\n"
            . "UPDATE content_creators\n"
            . "SET creator_id = {$target_id}\n"
            . "WHERE creator_id IN ({$source_id_list});\n\n"
            . "-- 要確認：同一 content_id に同じ creator_id が重複した場合は、内容を確認してから重複行を整理してください\n\n"
            . "-- content_creators の付け替え確認後に実行してください\n"
            . "-- 削除前に必ずバックアップしてください\n"
            . "DELETE FROM creators\n"
            . "WHERE id IN ({$source_id_list});\n";

        $postcheck = "-- ========================================\n"
            . "-- 統合後チェックSQL（人物統合）\n"
            . "-- ========================================\n\n"
            . "-- 統合先IDの紐づき件数確認\n"
            . "SELECT creator_id, COUNT(*) AS content_count\n"
            . "FROM content_creators\n"
            . "WHERE creator_id = {$target_id}\n"
            . "GROUP BY creator_id;\n\n"
            . "-- 統合元IDが残っていないか確認\n"
            . "SELECT *\n"
            . "FROM content_creators\n"
            . "WHERE creator_id IN ({$source_id_list});\n\n"
            . "SELECT *\n"
            . "FROM creators\n"
            . "WHERE id IN ({$source_id_list});\n";
    } else {
        $precheck = "-- ========================================\n"
            . "-- 事前チェックSQL（作品統合）\n"
            . "-- ========================================\n\n"
            . "-- 統合対象の作品確認\n"
            . "SELECT *\n"
            . "FROM works\n"
            . "WHERE id IN ({$all_ids});\n\n"
            . "-- 対象作品が紐づく目次件数確認\n"
            . "SELECT work_id, COUNT(*) AS content_count\n"
            . "FROM issue_contents\n"
            . "WHERE work_id IN ({$all_ids})\n"
            . "GROUP BY work_id\n"
            . "ORDER BY work_id;\n\n"
            . "-- original_content_id に対象IDが入っていないか確認\n"
            . "SELECT original_content_id, COUNT(*) AS ref_count\n"
            . "FROM issue_contents\n"
            . "WHERE original_content_id IN ({$all_ids})\n"
            . "GROUP BY original_content_id\n"
            . "ORDER BY original_content_id;\n";

        $merge = "-- ========================================\n"
            . "-- 統合SQL（作品統合）\n"
            . "-- ========================================\n\n"
            . "-- 注意：実行前に必ずバックアップしてください\n"
            . "-- 注意：この画面ではSQLを出力するだけで、実行はしません\n\n"
            . "-- 統合元の work_id を統合先IDへ付け替え\n"
            . "UPDATE issue_contents\n"
            . "SET work_id = {$target_id}\n"
            . "WHERE work_id IN ({$source_id_list});\n\n"
            . "-- original_content_id に作品IDが入っている場合の付け替え\n"
            . "UPDATE issue_contents\n"
            . "SET original_content_id = {$target_id}\n"
            . "WHERE original_content_id IN ({$source_id_list});\n\n"
            . "-- issue_contents の付け替え確認後に実行してください\n"
            . "-- 削除前に必ずバックアップしてください\n"
            . "DELETE FROM works\n"
            . "WHERE id IN ({$source_id_list});\n";

        $postcheck = "-- ========================================\n"
            . "-- 統合後チェックSQL（作品統合）\n"
            . "-- ========================================\n\n"
            . "-- 統合先IDの紐づき件数確認\n"
            . "SELECT work_id, COUNT(*) AS content_count\n"
            . "FROM issue_contents\n"
            . "WHERE work_id = {$target_id}\n"
            . "GROUP BY work_id;\n\n"
            . "-- 統合元IDが残っていないか確認\n"
            . "SELECT *\n"
            . "FROM issue_contents\n"
            . "WHERE work_id IN ({$source_id_list})\n"
            . "   OR original_content_id IN ({$source_id_list});\n\n"
            . "SELECT *\n"
            . "FROM works\n"
            . "WHERE id IN ({$source_id_list});\n";
    }

    return [
        'errors' => [],
        'precheck' => $precheck,
        'merge' => $merge,
        'postcheck' => $postcheck,
    ];
}

$allowed_types = ['creators', 'works', 'same_title_different_creators'];
$allowed_modes = ['contains', 'normalized', 'prefix', 'all'];
$allowed_limits = [50, 100, 200];

$type = (string)($_GET['type'] ?? 'creators');
if (!in_array($type, $allowed_types, true)) {
    $type = 'creators';
}

$q = trim((string)($_GET['q'] ?? ''));

$mode = (string)($_GET['mode'] ?? 'contains');
if (!in_array($mode, $allowed_modes, true)) {
    $mode = 'contains';
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if (!in_array($limit, $allowed_limits, true)) {
    $limit = 100;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($q === '' && $mode !== 'all') {
    $mode = 'all';
}

$normalized_q = normalize_name_for_duplicate_check($q);
$q_head = first_search_char($q);
$raw_candidates = [];
$results = [];
$groups = [];
$counts = [];
$candidate_fetch_limit = $type === 'same_title_different_creators' ? 50000 : ($type === 'works' ? 8000 : 3000);
$posted_merge_ids = [];
$posted_target_id = 0;
$merge_errors = [];
$sql_output = null;
$can_generate_merge_sql = in_array($type, ['creators', 'works'], true);

if ($can_generate_merge_sql && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['sql_action'] ?? '') === 'generate_merge_sql') {
    $posted_merge_ids = sanitize_positive_ids($_POST['merge_ids'] ?? []);
    $posted_target_id = (int)($_POST['target_id'] ?? 0);
    $sql_output = build_merge_sql_output($type, $posted_merge_ids, $posted_target_id);
    $merge_errors = $sql_output['errors'];
    if (!empty($merge_errors)) {
        $sql_output = null;
    }
}

if ($type === 'creators') {
    if ($q !== '' && $mode === 'contains') {
        $stmt = $pdo->prepare("
            SELECT id, name, name_kana
            FROM creators
            WHERE name LIKE :q OR name_kana LIKE :q
            ORDER BY name ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', min($candidate_fetch_limit, max(300, $limit * 5)), PDO::PARAM_INT);
        $stmt->execute();
    } elseif ($q_head !== '' && in_array($mode, ['normalized', 'prefix'], true)) {
        $stmt = $pdo->prepare("
            SELECT id, name, name_kana
            FROM creators
            WHERE name LIKE :q_head OR name_kana LIKE :q_head
            ORDER BY name ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':q_head', '%' . $q_head . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $candidate_fetch_limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, name_kana
            FROM creators
            ORDER BY name ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $candidate_fetch_limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $raw_candidates = $stmt->fetchAll();

    foreach ($raw_candidates as $row) {
        $label = (string)($row['name'] ?? '');
        $normalized = normalize_name_for_duplicate_check($label);
        $row['normalized_label'] = $normalized;

        if ($mode === 'all') {
            $key = $normalized !== '' ? $normalized : normalized_prefix($label, 3);
            if ($key !== '') {
                $groups[$key][] = $row;
            }
            continue;
        }

        if (should_include_candidate($label . ' ' . (string)($row['name_kana'] ?? ''), $normalized, $q, $normalized_q, $mode)) {
            $results[] = $row;
        }

        if (count($results) >= $limit) {
            break;
        }
    }
} elseif ($type === 'works') {
    if ($q !== '' && $mode === 'contains') {
        $stmt = $pdo->prepare("
            SELECT id, title, title_sub, title_kana, publication_type
            FROM works
            WHERE title LIKE :q OR title_sub LIKE :q OR title_kana LIKE :q
            ORDER BY title ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', min($candidate_fetch_limit, max(300, $limit * 5)), PDO::PARAM_INT);
        $stmt->execute();
    } elseif ($q_head !== '' && in_array($mode, ['normalized', 'prefix'], true)) {
        $stmt = $pdo->prepare("
            SELECT id, title, title_sub, title_kana, publication_type
            FROM works
            WHERE title LIKE :q_head OR title_sub LIKE :q_head OR title_kana LIKE :q_head
            ORDER BY title ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':q_head', '%' . $q_head . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $candidate_fetch_limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT id, title, title_sub, title_kana, publication_type
            FROM works
            ORDER BY title ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $candidate_fetch_limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $raw_candidates = $stmt->fetchAll();

    foreach ($raw_candidates as $row) {
        $label = (string)($row['title'] ?? '');
        $normalized = normalize_name_for_duplicate_check($label);
        $row['normalized_label'] = $normalized;

        if ($mode === 'all') {
            $key = $normalized !== '' ? $normalized : normalized_prefix($label, 3);
            if ($key !== '') {
                $groups[$key][] = $row;
            }
            continue;
        }

        $search_text = $label . ' ' . (string)($row['title_sub'] ?? '') . ' ' . (string)($row['title_kana'] ?? '');
        if (should_include_candidate($search_text, $normalized, $q, $normalized_q, $mode)) {
            $results[] = $row;
        }

        if (count($results) >= $limit) {
            break;
        }
    }
} else {
    $raw_candidates = fetch_work_creator_candidates($pdo, $q, $mode, $q_head, $candidate_fetch_limit);

    foreach ($raw_candidates as $row) {
        $label = (string)($row['title'] ?? '');
        $content_label = (string)($row['title_main'] ?? '');
        $normalized = normalize_name_for_duplicate_check($label);
        $normalized_content_label = normalize_name_for_duplicate_check($content_label);
        if ($normalized === '' && $normalized_content_label !== '') {
            $normalized = $normalized_content_label;
        }
        $row['normalized_label'] = $normalized;
        $row['normalized_content_label'] = $normalized_content_label;
        $row['title_variant_signature'] = normalize_name_for_duplicate_check($content_label) . "\n" . trim($content_label);
        $row['creator_signature'] = trim((string)($row['creator_ids'] ?? ''));

        if ($mode === 'all') {
            $key = $normalized !== '' ? $normalized : normalized_prefix($label, 3);
            if ($key !== '') {
                $groups[$key][] = $row;
            }
            continue;
        }

        $search_text = $label . ' ' . $content_label . ' ' . (string)($row['title_sub'] ?? '') . ' ' . (string)($row['title_kana'] ?? '');
        if (should_include_candidate($search_text, $normalized, $q, $normalized_q, $mode)) {
            $key = $normalized !== '' ? $normalized : normalized_prefix($label, 3);
            if ($key !== '') {
                $groups[$key][] = $row;
            }
        }
    }
}

if ($type === 'same_title_different_creators') {
    $filtered_groups = [];

    foreach ($groups as $key => $items) {
        if (count($items) < 2) {
            continue;
        }

        $creator_signatures = [];
        $work_signatures = [];
        foreach ($items as $item) {
            $creator_signatures[(string)($item['creator_signature'] ?? '')] = true;
            $work_signatures[(string)($item['work_id'] ?? '')] = true;
        }

        if (count($creator_signatures) < 2 && count($work_signatures) < 2) {
            continue;
        }

        $filtered_groups[$key] = $items;
        foreach ($items as $item) {
            $results[] = $item;
        }
    }

    $groups = $filtered_groups;
} elseif ($mode === 'all') {
    $filtered_groups = [];
    $item_count = 0;

    foreach ($groups as $key => $items) {
        if (count($items) < 2) {
            continue;
        }

        $filtered_groups[$key] = $items;
        foreach ($items as $item) {
            $results[] = $item;
            $item_count++;
            if ($item_count >= $limit) {
                break 2;
            }
        }
    }

    $groups = $filtered_groups;
}

$ids = array_values(array_unique(array_map(static function ($row) {
    return (int)$row['id'];
}, $results)));

$total_results = count($results);
$total_pages = max(1, (int)ceil($total_results / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
}
$display_offset = ($page - 1) * $limit;
$display_results = $type === 'same_title_different_creators'
    ? array_slice($results, $display_offset, $limit)
    : $results;
$display_ids = array_values(array_unique(array_map(static function ($row) {
    return (int)$row['id'];
}, $display_results)));

$counts = $type === 'creators' ? fetch_creator_counts($pdo, $ids) : fetch_work_counts($pdo, $ids);
$type_label = '作品名';
if ($type === 'creators') {
    $type_label = '人物名';
} elseif ($type === 'same_title_different_creators') {
    $type_label = '同名作品・作者違い';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>重複候補チェック - INDEXINDEX 管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .container {
            max-width: 1200px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>重複候補チェック</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="creators.php">人物一覧</a>
        <a href="works.php">作品一覧</a>
    </div>

    <p class="tool-description">
        人物名・作品名の表記揺れや重複候補を確認するためのデバッグ用TOOLです。表示のみで、データ更新は行いません。
    </p>

    <div class="debug-note">
        この画面には統合・削除などの実行機能はありません。候補を確認して、必要に応じて編集ページで個別に修正してください。
    </div>

    <div class="tab-nav">
        <a href="<?= h(build_duplicate_url('creators', $q, $mode, $limit)) ?>" class="<?= $type === 'creators' ? 'active' : '' ?>">人物名チェック</a>
        <a href="<?= h(build_duplicate_url('works', $q, $mode, $limit)) ?>" class="<?= $type === 'works' ? 'active' : '' ?>">作品名チェック</a>
        <a href="<?= h(build_duplicate_url('same_title_different_creators', $q, $mode, $limit)) ?>" class="<?= $type === 'same_title_different_creators' ? 'active' : '' ?>">同名作品・作者違い</a>
    </div>

    <form method="get" action="duplicate_check.php" class="filter-form duplicate-filter-form">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <div class="filter-row">
            <div class="filter-item filter-item-wide">
                <label for="q">検索ワード</label>
                <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="<?= $type === 'creators' ? '人物名・かな' : '作品名・サブタイトル・かな' ?>">
            </div>

            <div class="filter-item">
                <label for="mode">チェック方法</label>
                <select id="mode" name="mode">
                    <option value="contains" <?= $mode === 'contains' ? 'selected' : '' ?>>部分一致</option>
                    <option value="normalized" <?= $mode === 'normalized' ? 'selected' : '' ?>>正規化比較</option>
                    <option value="prefix" <?= $mode === 'prefix' ? 'selected' : '' ?>>先頭一致</option>
                    <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>簡易重複候補一覧</option>
                </select>
            </div>

            <div class="filter-item filter-item-year">
                <label for="limit">表示件数</label>
                <select id="limit" name="limit">
                    <?php foreach ($allowed_limits as $limit_option): ?>
                        <option value="<?= h($limit_option) ?>" <?= $limit === $limit_option ? 'selected' : '' ?>><?= h($limit_option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit">チェック</button>
                <a class="button-link" href="duplicate_check.php?type=<?= h($type) ?>">リセット</a>
            </div>
        </div>
    </form>

    <div class="result-summary">
        対象: <?= h($type_label) ?> /
        方法: <?= h($mode) ?> /
        候補表示: <?= h(count($results)) ?> 件
        <?php if ($type === 'same_title_different_creators' && $total_results > 0): ?>
            / 表示ページ: <?= h($page) ?> / <?= h($total_pages) ?>
            / 表示範囲: <?= h($display_offset + 1) ?>〜<?= h(min($display_offset + $limit, $total_results)) ?> 件
        <?php endif; ?>
        <?php if ($mode === 'all'): ?>
            / 取得上限: <?= h($candidate_fetch_limit) ?> 件
        <?php endif; ?>
    </div>

    <?php if (!empty($merge_errors)): ?>
        <div class="warning-box">
            <strong>SQL出力エラー</strong>
            <ul>
                <?php foreach ($merge_errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($sql_output !== null): ?>
        <div class="warning-box">
            この機能はSQLを出力するだけです。SQLは自動実行されません。実行前に必ずバックアップを取り、事前チェックSQLで対象を確認してください。統合先IDを間違えるとデータが不正になります。
        </div>
        <div class="sql-output">
            <h2>事前チェックSQL</h2>
            <textarea readonly rows="18"><?= h($sql_output['precheck']) ?></textarea>
        </div>
        <div class="sql-output">
            <h2>統合SQL</h2>
            <textarea readonly rows="18"><?= h($sql_output['merge']) ?></textarea>
        </div>
        <div class="sql-output">
            <h2>統合後チェックSQL</h2>
            <textarea readonly rows="18"><?= h($sql_output['postcheck']) ?></textarea>
        </div>
    <?php endif; ?>

    <?php if (!empty($results) && $can_generate_merge_sql): ?>
        <form method="post" action="<?= h(build_duplicate_url($type, $q, $mode, $limit)) ?>" class="merge-form">
            <input type="hidden" name="sql_action" value="generate_merge_sql">
            <div class="merge-tools">
                <div>
                    <strong>統合SQL出力</strong>
                    <p>統合対象にチェックを入れ、残すIDを「統合先」で選んでください。SQL文字列を表示するだけで、DB更新は行いません。</p>
                </div>
                <button type="submit">SQL出力</button>
            </div>
    <?php endif; ?>

    <?php if (empty($results)): ?>
        <p class="empty">候補は見つかりませんでした。</p>
    <?php elseif ($type === 'same_title_different_creators' && !empty($groups)): ?>
        <?php
            $displayed = 0;
            foreach ($groups as $group_key => $items):
                $visible_items = [];
                foreach ($items as $item) {
                    if (in_array((int)$item['id'], $display_ids, true)) {
                        $visible_items[] = $item;
                    }
                }
                if (empty($visible_items)) {
                    continue;
                }
        ?>
            <div class="duplicate-group">
                <div class="duplicate-group-title">同名作品・作者違い <span class="normalized-text"><?= h($group_key) ?></span></div>
                <table>
                    <thead>
                        <tr>
                            <th>掲載ID</th>
                            <th>作品ID</th>
                            <th>作品マスタ名</th>
                            <th>掲載タイトル</th>
                            <th>サブタイトル</th>
                            <th>かな</th>
                            <th>区分</th>
                            <th>掲載号</th>
                            <th>正規化作品名</th>
                            <th>作者ID</th>
                            <th>作者</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visible_items as $row): ?>
                        <tr>
                            <td><?= h($row['content_id'] ?? $row['id']) ?></td>
                            <td><?= h($row['work_id'] ?? '') ?></td>
                            <td><?= h($row['work_title'] ?? $row['title']) ?></td>
                            <td><?= h($row['title_main'] ?? '') ?></td>
                            <td><?= h($row['title_sub'] ?? '') ?></td>
                            <td><?= h($row['title_kana'] ?? '') ?></td>
                            <td><?= h($row['publication_type'] ?? '') ?></td>
                            <td>
                                <?= h($row['magazine_name'] ?? '') ?>
                                <?= h($row['issue_label'] ?? '') ?>
                            </td>
                            <td><span class="normalized-text"><?= h($row['normalized_label'] ?? '') ?></span></td>
                            <td><?= h($row['creator_ids'] ?? '') ?></td>
                            <td><?= h($row['creator_names'] ?? '') ?></td>
                            <td class="actions">
                                <a class="button-link" href="content_edit.php?id=<?= h($row['content_id'] ?? $row['id']) ?>">掲載編集</a>
                                <?php if (!empty($row['work_id'])): ?>
                                    <a class="button-link" href="work_edit.php?id=<?= h($row['work_id']) ?>">作品編集</a>
                                    <a class="button-link public" href="../work.php?work_id=<?= h($row['work_id']) ?>" target="_blank">公開</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php
                $displayed += count($visible_items);
            endforeach;
        ?>
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= h(build_duplicate_url($type, $q, $mode, $limit, 1)) ?>">最初</a>
                    <a href="<?= h(build_duplicate_url($type, $q, $mode, $limit, $page - 1)) ?>">前へ</a>
                <?php else: ?>
                    <span class="disabled">最初</span>
                    <span class="disabled">前へ</span>
                <?php endif; ?>

                <span class="current"><?= h($page) ?> / <?= h($total_pages) ?></span>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= h(build_duplicate_url($type, $q, $mode, $limit, $page + 1)) ?>">次へ</a>
                    <a href="<?= h(build_duplicate_url($type, $q, $mode, $limit, $total_pages)) ?>">最後</a>
                <?php else: ?>
                    <span class="disabled">次へ</span>
                    <span class="disabled">最後</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($mode === 'all' && !empty($groups)): ?>
        <?php
            $displayed = 0;
            foreach ($groups as $group_key => $items):
                $visible_items = [];
                foreach ($items as $item) {
                    if (in_array((int)$item['id'], $ids, true)) {
                        $visible_items[] = $item;
                    }
                }
                if (count($visible_items) < 2) {
                    continue;
                }
        ?>
            <div class="duplicate-group">
                <div class="duplicate-group-title">候補グループ: <span class="normalized-text"><?= h($group_key) ?></span></div>
                <?php if ($type === 'creators'): ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="merge-check-column">統合</th>
                                <th class="merge-check-column">統合先</th>
                                <th>ID</th>
                                <th>人物名</th>
                                <th>かな</th>
                                <th>正規化名</th>
                                <th>掲載件数</th>
                                <th>作品件数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($visible_items as $row): ?>
                            <?php $row_counts = $counts[(int)$row['id']] ?? ['content_count' => 0, 'work_count' => 0]; ?>
                            <tr>
                                <td class="merge-check-column">
                                    <input type="checkbox" name="merge_ids[]" value="<?= h($row['id']) ?>" <?= in_array((int)$row['id'], $posted_merge_ids, true) ? 'checked' : '' ?>>
                                </td>
                                <td class="merge-check-column">
                                    <input type="radio" name="target_id" value="<?= h($row['id']) ?>" <?= $posted_target_id === (int)$row['id'] ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['id']) ?></td>
                                <td><?= h($row['name']) ?></td>
                                <td><?= h($row['name_kana'] ?? '') ?></td>
                                <td><span class="normalized-text"><?= h($row['normalized_label']) ?></span></td>
                                <td class="num"><?= h($row_counts['content_count']) ?></td>
                                <td class="num"><?= h($row_counts['work_count']) ?></td>
                                <td class="actions">
                                    <a class="button-link" href="creator_edit.php?id=<?= h($row['id']) ?>">編集</a>
                                    <a class="button-link public" href="../creator.php?creator_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="merge-check-column">統合</th>
                                <th class="merge-check-column">統合先</th>
                                <th>ID</th>
                                <th>作品名</th>
                                <th>サブタイトル</th>
                                <th>かな</th>
                                <th>正規化タイトル</th>
                                <th>区分</th>
                                <th>掲載件数</th>
                                <th>作者数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($visible_items as $row): ?>
                            <?php $row_counts = $counts[(int)$row['id']] ?? ['content_count' => 0, 'creator_count' => 0]; ?>
                            <tr>
                                <td class="merge-check-column">
                                    <input type="checkbox" name="merge_ids[]" value="<?= h($row['id']) ?>" <?= in_array((int)$row['id'], $posted_merge_ids, true) ? 'checked' : '' ?>>
                                </td>
                                <td class="merge-check-column">
                                    <input type="radio" name="target_id" value="<?= h($row['id']) ?>" <?= $posted_target_id === (int)$row['id'] ? 'checked' : '' ?>>
                                </td>
                                <td><?= h($row['id']) ?></td>
                                <td><?= h($row['title']) ?></td>
                                <td><?= h($row['title_sub'] ?? '') ?></td>
                                <td><?= h($row['title_kana'] ?? '') ?></td>
                                <td><span class="normalized-text"><?= h($row['normalized_label']) ?></span></td>
                                <td><?= h($row['publication_type'] ?? '') ?></td>
                                <td class="num"><?= h($row_counts['content_count']) ?></td>
                                <td class="num"><?= h($row_counts['creator_count']) ?></td>
                                <td class="actions">
                                    <a class="button-link" href="work_edit.php?id=<?= h($row['id']) ?>">編集</a>
                                    <a class="button-link public" href="../work.php?work_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php
                $displayed += count($visible_items);
                if ($displayed >= $limit) {
                    break;
                }
            endforeach;
        ?>
    <?php else: ?>
        <?php if ($type === 'creators'): ?>
            <table>
                <thead>
                    <tr>
                        <th class="merge-check-column">統合</th>
                        <th class="merge-check-column">統合先</th>
                        <th>ID</th>
                        <th>人物名</th>
                        <th>かな</th>
                        <th>正規化名</th>
                        <th>掲載件数</th>
                        <th>作品件数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <?php $row_counts = $counts[(int)$row['id']] ?? ['content_count' => 0, 'work_count' => 0]; ?>
                    <tr>
                        <td class="merge-check-column">
                            <input type="checkbox" name="merge_ids[]" value="<?= h($row['id']) ?>" <?= in_array((int)$row['id'], $posted_merge_ids, true) ? 'checked' : '' ?>>
                        </td>
                        <td class="merge-check-column">
                            <input type="radio" name="target_id" value="<?= h($row['id']) ?>" <?= $posted_target_id === (int)$row['id'] ? 'checked' : '' ?>>
                        </td>
                        <td><?= h($row['id']) ?></td>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h($row['name_kana'] ?? '') ?></td>
                        <td><span class="normalized-text"><?= h($row['normalized_label']) ?></span></td>
                        <td class="num"><?= h($row_counts['content_count']) ?></td>
                        <td class="num"><?= h($row_counts['work_count']) ?></td>
                        <td class="actions">
                            <a class="button-link" href="creator_edit.php?id=<?= h($row['id']) ?>">編集</a>
                            <a class="button-link public" href="../creator.php?creator_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="merge-check-column">統合</th>
                        <th class="merge-check-column">統合先</th>
                        <th>ID</th>
                        <th>作品名</th>
                        <th>サブタイトル</th>
                        <th>かな</th>
                        <th>正規化タイトル</th>
                        <th>区分</th>
                        <th>掲載件数</th>
                        <th>作者数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <?php $row_counts = $counts[(int)$row['id']] ?? ['content_count' => 0, 'creator_count' => 0]; ?>
                    <tr>
                        <td class="merge-check-column">
                            <input type="checkbox" name="merge_ids[]" value="<?= h($row['id']) ?>" <?= in_array((int)$row['id'], $posted_merge_ids, true) ? 'checked' : '' ?>>
                        </td>
                        <td class="merge-check-column">
                            <input type="radio" name="target_id" value="<?= h($row['id']) ?>" <?= $posted_target_id === (int)$row['id'] ? 'checked' : '' ?>>
                        </td>
                        <td><?= h($row['id']) ?></td>
                        <td><?= h($row['title']) ?></td>
                        <td><?= h($row['title_sub'] ?? '') ?></td>
                        <td><?= h($row['title_kana'] ?? '') ?></td>
                        <td><span class="normalized-text"><?= h($row['normalized_label']) ?></span></td>
                        <td><?= h($row['publication_type'] ?? '') ?></td>
                        <td class="num"><?= h($row_counts['content_count']) ?></td>
                        <td class="num"><?= h($row_counts['creator_count']) ?></td>
                        <td class="actions">
                            <a class="button-link" href="work_edit.php?id=<?= h($row['id']) ?>">編集</a>
                            <a class="button-link public" href="../work.php?work_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($results) && $can_generate_merge_sql): ?>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
