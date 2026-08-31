<?php
// admin/content_bulk_add.php

session_start();

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function editable_issue_status_sql(): string {
    return "'approved', 'in_progress', 'verified'";
}

function normalize_episode_no_bulk($value) {
    $value = mb_convert_kana((string)$value, 'as', 'UTF-8');
    if (preg_match('/[0-9]+/u', $value, $m)) {
        return $m[0];
    }
    return '';
}

function validate_episode_no_bulk($value, $row_number) {
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[0-9]*$/', $value)) {
        return 'No.' . $row_number . ' の話数は半角数字で入力してください。';
    }

    return '';
}

// 一括登録の入力行数
const BULK_ROW_COUNT = 40;

// 指定した雑誌号の目次を開始ページ順で並べ直し、掲載順を 1,2,3... に振り直す
function renumber_issue_contents($pdo, $issue_id) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM issue_contents
        WHERE issue_id = :issue_id
        ORDER BY
            CASE
                WHEN page_start IS NULL THEN 1
                ELSE 0
            END ASC,
            page_start ASC,
            id ASC
    ");
    $stmt->execute([
        ':issue_id' => $issue_id
    ]);

    $rows = $stmt->fetchAll();

    $order = 1;

    foreach ($rows as $row) {
        $stmt_update = $pdo->prepare("
            UPDATE issue_contents
            SET sort_order = :sort_order
            WHERE id = :id
        ");
        $stmt_update->execute([
            ':sort_order' => $order,
            ':id' => $row['id']
        ]);

        $order++;
    }
}

function get_or_create_work_id($pdo, $title, $publication_type, $selected_work_id = 0) {
    if ($selected_work_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, publication_type
            FROM works
            WHERE id = :id
              AND title = :title
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $selected_work_id,
            ':title' => $title
        ]);

        $work = $stmt->fetch();

        if ($work) {
            $work_id = (int)$work['id'];

            if (empty($work['publication_type'])) {
                $stmt_update = $pdo->prepare("
                    UPDATE works
                    SET publication_type = :publication_type
                    WHERE id = :id
                ");
                $stmt_update->execute([
                    ':publication_type' => $publication_type,
                    ':id' => $work_id
                ]);
            }

            return $work_id;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, publication_type
        FROM works
        WHERE BINARY title = BINARY :title
        LIMIT 1
    ");
    $stmt->execute([
        ':title' => $title
    ]);

    $work = $stmt->fetch();

    if ($work) {
        $work_id = (int)$work['id'];

        if (empty($work['publication_type'])) {
            $stmt_update = $pdo->prepare("
                UPDATE works
                SET publication_type = :publication_type
                WHERE id = :id
            ");
            $stmt_update->execute([
                ':publication_type' => $publication_type,
                ':id' => $work_id
            ]);
        }

        return $work_id;
    }

    $stmt = $pdo->prepare("
        INSERT INTO works (
            title,
            publication_type
        ) VALUES (
            :title,
            :publication_type
        )
    ");
    $stmt->execute([
        ':title' => $title,
        ':publication_type' => $publication_type
    ]);

    return (int)$pdo->lastInsertId();
}

function get_or_create_creator_id($pdo, $name) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM creators
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $name
    ]);

    $creator = $stmt->fetch();

    if ($creator) {
        return (int)$creator['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO creators (name)
        VALUES (:name)
    ");
    $stmt->execute([
        ':name' => $name
    ]);

    return (int)$pdo->lastInsertId();
}

function get_or_create_role_id_bulk($pdo, $role_name) {
    $role_name = trim((string)$role_name);
    if ($role_name === '') {
        $role_name = 'author';
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM roles
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $role_name
    ]);
    $role = $stmt->fetch();

    if ($role) {
        return (int)$role['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO roles (name)
        VALUES (:name)
    ");
    $stmt->execute([
        ':name' => $role_name
    ]);

    return (int)$pdo->lastInsertId();
}

function split_creator_role_colon_bulk($creator_name, $default_role = 'author') {
    $creator_name = trim((string)$creator_name);
    $default_role = trim((string)$default_role);
    if ($default_role === '') {
        $default_role = 'author';
    }

    if ($creator_name === '') {
        return null;
    }

    if (!preg_match('/[:：]/u', $creator_name, $match, PREG_OFFSET_CAPTURE)) {
        return [
            'name' => $creator_name,
            'role' => $default_role,
        ];
    }

    $colon_pos = $match[0][1];
    $role = trim(substr($creator_name, 0, $colon_pos));
    $name = trim(substr($creator_name, $colon_pos + strlen($match[0][0])));
    $name = preg_replace('/^[\s,，,\/／、]+|[\s,，,\/／、]+$/u', '', $name);

    if ($role === '' || $name === '') {
        return [
            'name' => $creator_name,
            'role' => $default_role,
        ];
    }

    return [
        'name' => $name,
        'role' => $role,
    ];
}

function split_creator_role_colon_rows_bulk($creator_name) {
    $creator_name = trim((string)$creator_name);
    if ($creator_name === '') {
        return [];
    }

    if (!preg_match('/[:：]/u', $creator_name, $match, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $colon_pos = $match[0][1];
    $before = trim(substr($creator_name, 0, $colon_pos));
    $name = trim(substr($creator_name, $colon_pos + strlen($match[0][0])));
    $name = preg_replace('/^[\s,，,\/／、]+|[\s,，,\/／、]+$/u', '', $name);

    if ($before === '' || $name === '') {
        return [];
    }

    $prefix = '';
    $role = $before;
    if (preg_match('/^(.*)[\s\/／、]+([^\s\/／、]+)$/u', $before, $parts)) {
        $prefix = trim($parts[1]);
        $role = trim($parts[2]);
    }

    if ($role === '') {
        return [];
    }

    $items = [];
    $seen = [];

    if ($prefix !== '') {
        foreach (split_creator_names($prefix) as $prefix_name) {
            $key = "author\n" . $prefix_name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'name' => $prefix_name,
                'role' => 'author',
            ];
        }
    }

    $key = $role . "\n" . $name;
    if (!isset($seen[$key])) {
        $items[] = [
            'name' => $name,
            'role' => $role,
        ];
    }

    return $items;
}

function split_creator_names($creator_name) {
    $creator_name = trim((string)$creator_name);
    if ($creator_name === '') {
        return [];
    }

    $creator_name = str_replace(['／', '・'], ['/', '・'], $creator_name);

    // 修正: スペースを区切り文字から除外 (区切り文字は「、」「,」「／」「/」のみ)
    $parts = preg_split('/\s*[,、\/]\s*/u', $creator_name);
    $names = [];

    foreach ($parts as $part) {
        $part = trim((string)$part);
        $part = preg_replace('/^(' . creator_role_label_pattern_bulk() . ')\s*[:：]\s*/u', '', $part);
        $part = preg_replace('/^[\s,，,\/／、]+|[\s,，,\/／、]+$/u', '', $part);
        $part = trim((string)$part);

        if ($part !== '') {
            $names[] = $part;
        }
    }

    return array_values(array_unique($names));
}

/**
 * 作者名の分割結果をデバッグ出力（HTML形式）
 * 
 * 修正内容:
 * - 区切り文字を「、」「,」「／」「/」の4つのみに限定
 * - スペースは区切り文字から除外（「DJ SPACE」のような名前を保持）
 * 
 * @param string $creator_name 入力された作者名
 * @return string HTML形式のデバッグ出力
 */
function debug_split_creator_names($creator_name) {
    $names = split_creator_names($creator_name);
    
    $output = '<div style="background: #f9f9f9; border: 1px solid #ddd; padding: 10px; margin: 5px 0; font-family: monospace; font-size: 12px;">';
    $output .= '<strong style="color: #333;">【作者名分割デバッグ】</strong><br>';
    $output .= '<span style="color: #666;">入力: ' . htmlspecialchars($creator_name, ENT_QUOTES, 'UTF-8') . '</span><br>';
    $output .= '<span style="color: #009900;"><strong>分割結果 (' . count($names) . '件):</strong></span><br>';
    
    if (empty($names)) {
        $output .= '<span style="color: #cc0000;">（分割結果なし）</span>';
    } else {
        foreach ($names as $index => $name) {
            $output .= '<div style="margin-left: 15px; color: #0066cc;">author:' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
    
    $output .= '<br><span style="font-size: 10px; color: #999;">区切り文字: 「、」「,」「／」「/」（スペースは区切りません）</span>';
    $output .= '</div>';
    
    return $output;
}

function creator_role_labels_bulk() {
    return [
        '漫画・キャラクター原案',
        'キャラクター原案',
        'まんが・キャラクター原案',
        'まんが',
        '漫画',
        '原案',
        '作画協力',
        '原作',
        '作画',
        '構成',
        '脚本',
        '協力',
        '監修',
        '作者',
        '画',
        '作',
        '文',
        '絵',
        '著',
    ];
}

function creator_role_label_pattern_bulk() {
    return implode('|', array_map(function ($label) {
        return preg_quote($label, '/');
    }, creator_role_labels_bulk()));
}

// 確認例: 画:手塚治虫 作:石ノ森章太郎 / 画 手塚治虫 作 石ノ森章太郎 / 宮島礼吏 作画協力:雪野れいじ
function split_creator_roles_bulk($creator_name) {
    $creator_name = trim((string)$creator_name);
    if ($creator_name === '') {
        return [];
    }

    $role_pattern = creator_role_label_pattern_bulk();
    $items = [];
    $pattern = '/(' . $role_pattern . ')\s*(?:[:：]\s*|[,，、\s　]+)/u';

    if (!preg_match_all($pattern, $creator_name, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $seen = [];
    $first_role_pos = $matches[0][0][1];
    $prefix = trim(substr($creator_name, 0, $first_role_pos));

    if ($prefix !== '') {
        $prefix_creator = split_creator_role_colon_bulk($prefix);
        if ($prefix_creator !== null && $prefix_creator['role'] !== 'author') {
            $prefix_items = [$prefix_creator];
        } else {
            $prefix_items = [];
            foreach (split_creator_names($prefix) as $prefix_name) {
                $prefix_items[] = [
                    'name' => $prefix_name,
                    'role' => 'author',
                ];
            }
        }

        foreach ($prefix_items as $prefix_item) {
            $prefix_name = trim((string)($prefix_item['name'] ?? ''));
            $prefix_role = trim((string)($prefix_item['role'] ?? 'author'));
            if ($prefix_name === '') {
                continue;
            }

            if ($prefix_role === '') {
                $prefix_role = 'author';
            }

            $key = $prefix_role . "\n" . $prefix_name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'name' => $prefix_name,
                'role' => $prefix_role,
            ];
        }
    }

    foreach ($matches as $index => $match) {
        $role = trim($match[1][0]);
        $name_start = $match[0][1] + strlen($match[0][0]);
        $name_end = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : strlen($creator_name);
        $name = trim(substr($creator_name, $name_start, $name_end - $name_start));
        $name = preg_replace('/^[\s,，、\/／]+|[\s,，、\/／]+$/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        if ($name === '') {
            continue;
        }

        $key = $role . "\n" . $name;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $items[] = [
            'name' => $name,
            'role' => $role,
        ];
    }

    return $items;
}

function build_creator_rows_from_name_bulk($creator_name) {
    $creator_rows = split_creator_roles_bulk($creator_name);
    if (!empty($creator_rows)) {
        return $creator_rows;
    }

    $creator_rows = split_creator_role_colon_rows_bulk($creator_name);
    if (!empty($creator_rows)) {
        return $creator_rows;
    }

    $creator_rows = [];
    foreach (split_creator_names($creator_name) as $creator_name_one) {
        $creator_rows[] = [
            'name' => $creator_name_one,
            'role' => 'author',
        ];
    }

    return $creator_rows;
}

function normalize_structured_creators($creators) {
    if (!is_array($creators)) {
        return [];
    }

    $items = [];
    foreach ($creators as $creator) {
        if (!is_array($creator)) {
            continue;
        }

        $name = mb_convert_kana(trim((string)($creator['name'] ?? '')), 'as', 'UTF-8');
        $role = mb_convert_kana(trim((string)($creator['role'] ?? '')), 'as', 'UTF-8');
        if ($role === '作者') {
            $role = '';
        }

        if ($name !== '') {
            $items[] = [
                'name' => $name,
                'role' => $role,
            ];
        }
    }

    return $items;
}

function normalize_structured_creators_json($json) {
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return normalize_structured_creators($decoded);
}

function send_creator_lookup_json($data) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function escape_like_bulk($value) {
    return strtr((string)$value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]);
}

function lookup_creators_by_title($pdo, $title) {
    $title = mb_convert_kana(trim((string)$title), 'as', 'UTF-8');

    if ($title === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM works
        WHERE title LIKE :like_title ESCAPE '\\\\'
          AND title IS NOT NULL
          AND title <> ''
        ORDER BY
            CASE
                WHEN title = :exact_title THEN 0
                WHEN title LIKE :prefix_title ESCAPE '\\\\' THEN 1
                ELSE 2
            END ASC,
            CHAR_LENGTH(title) ASC,
            title ASC
        LIMIT 2
    ");
    $stmt->execute([
        ':like_title' => '%' . escape_like_bulk($title) . '%',
        ':exact_title' => $title,
        ':prefix_title' => escape_like_bulk($title) . '%',
    ]);
    $works = $stmt->fetchAll();

    if (count($works) !== 1) {
        return '';
    }

    $work_id = (int)$works[0]['id'];

    $stmt = $pdo->prepare("
        SELECT
            c.name,
            MIN(cc.display_order) AS display_order,
            MIN(ic.id) AS content_id
        FROM issue_contents ic
        JOIN content_creators cc ON ic.id = cc.content_id
        JOIN creators c ON cc.creator_id = c.id
        WHERE ic.work_id = :work_id
          AND c.name IS NOT NULL
          AND c.name <> ''
        GROUP BY c.id, c.name
        ORDER BY
            display_order ASC,
            content_id ASC,
            c.name ASC
    ");
    $stmt->execute([
        ':work_id' => $work_id
    ]);
    $creators = $stmt->fetchAll();

    if (empty($creators)) {
        return '';
    }

    $names = [];
    foreach ($creators as $creator) {
        $name = trim((string)$creator['name']);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return implode('、', array_values(array_unique($names)));
}

if (isset($_GET['action']) && $_GET['action'] === 'lookup_creators_by_title') {
    send_creator_lookup_json([
        'creators' => lookup_creators_by_title($pdo, $_GET['title'] ?? '')
    ]);
}

function make_empty_rows() {
    $rows = [];

    for ($i = 0; $i < BULK_ROW_COUNT; $i++) {
        $rows[$i] = [
            'page_start' => '',
            'work_id' => 0,
            'title_main' => '',
            'creator_name' => '',
            'creators' => [],
            'episode_no' => '',
            'episode_title' => '',
            'is_one_shot' => 0,
            'is_guest_publication' => 0,
            'is_new_series' => 0,
            'is_final_episode' => 0,
            'note' => '',
        ];
    }

    return $rows;
}

$message = '';
$error = '';
$debug_info = null;
$show_debug_logs = false;

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;
$rows_input = make_empty_rows();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['saved']) && $_GET['saved'] === '1') {
    $inserted_count = isset($_GET['inserted']) ? max(0, (int)$_GET['inserted']) : 0;
    $message = $inserted_count . '件の目次データを登録しました。開始ページ順で掲載順を自動更新しました。';
    
    // デバッグ情報をセッションから取得（常時出力、表示状態はチェックボックスで制御）
    if (!empty($_SESSION['debug_splits'])) {
        $debug_info = $_SESSION['debug_splits'];
        // デバッグログ表示フラグを確認（POST時のチェック状態をセッションから取得）
        $show_debug_logs = isset($_SESSION['show_debug_logs']) && $_SESSION['show_debug_logs'] === true;
        unset($_SESSION['debug_splits']);  // 表示後は削除
        unset($_SESSION['show_debug_logs']);
    }
}

// 目次抽出TOOLから渡されたデータを反映
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['from_toc_extract'])) {
    $session_issue_id = isset($_SESSION['toc_extract_issue_id']) ? (int)$_SESSION['toc_extract_issue_id'] : 0;
    $session_rows = $_SESSION['toc_extract_rows'] ?? [];

    if ($issue_id > 0 && $session_issue_id === $issue_id && is_array($session_rows)) {
        for ($i = 0; $i < BULK_ROW_COUNT; $i++) {
            if (!isset($session_rows[$i])) {
                continue;
            }

            $r = $session_rows[$i];

            $rows_input[$i] = [
                'page_start' => '',
                'work_id' => isset($r['work_id']) ? (int)$r['work_id'] : 0,
                'title_main' => mb_convert_kana(trim($r['work_title'] ?? ''), 'as', 'UTF-8'),
                'creator_name' => mb_convert_kana(trim($r['creator_text'] ?? ''), 'as', 'UTF-8'),
                'creators' => normalize_structured_creators($r['creators'] ?? []),
                'episode_no' => normalize_episode_no_bulk($r['episode_no'] ?? ''),
                'episode_title' => mb_convert_kana(trim($r['episode_title'] ?? ''), 'as', 'UTF-8'),
                'is_one_shot' => !empty($r['is_one_shot']) ? 1 : 0,
                'is_guest_publication' => !empty($r['is_guest_publication']) ? 1 : 0,
                'is_new_series' => !empty($r['is_new_series']) ? 1 : 0,
                'is_final_episode' => !empty($r['is_final_episode']) ? 1 : 0,
                'note' => mb_convert_kana(trim($r['note'] ?? ''), 'as', 'UTF-8'),
            ];
        }

        $message = '目次抽出TOOLからデータを受け取りました。内容を確認してから登録してください。';
    } else {
        $error = '目次抽出TOOLから渡されたデータが見つからない、または雑誌号IDが一致しません。';
    }
}

// 雑誌号一覧を取得
$sql_issues = "
    SELECT
        mi.id,
        mi.issue_label,
        mi.release_date,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE mi.status IN (" . editable_issue_status_sql() . ")
    ORDER BY
        CASE
            WHEN mi.publication_date IS NOT NULL
                 AND mi.publication_date <> ''
                 AND mi.publication_date <> '0000-00-00'
            THEN mi.publication_date
            WHEN mi.release_date IS NOT NULL
                 AND mi.release_date <> ''
                 AND mi.release_date <> '0000-00-00'
            THEN mi.release_date
            ELSE '9999-12-31'
        END DESC,
        mi.id DESC
";
$stmt = $pdo->query($sql_issues);
$issues = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $posted_rows = $_POST['rows'] ?? [];
    if (!is_array($posted_rows)) {
        $posted_rows = [];
    }

    // POST時のデバッグログ表示状態を確認（チェックボックス状態）
    $debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
    
    // チェック状態をセッションに保存（リダイレクト後に表示制御に使用）
    $_SESSION['show_debug_logs'] = $debug_logs_enabled;

    $rows_input = make_empty_rows();

    for ($i = 0; $i < BULK_ROW_COUNT; $i++) {
        $posted_row = (isset($posted_rows[$i]) && is_array($posted_rows[$i])) ? $posted_rows[$i] : [];

        $page_start = trim($posted_row['page_start'] ?? '');
        $work_id = isset($posted_row['work_id']) ? (int)$posted_row['work_id'] : 0;
        $title_main = mb_convert_kana(trim($posted_row['title_main'] ?? ''), 'as', 'UTF-8');
        $creator_name = mb_convert_kana(trim($posted_row['creator_name'] ?? ''), 'as', 'UTF-8');
        $episode_no = trim((string)($posted_row['episode_no'] ?? ''));
        $episode_title = mb_convert_kana(trim($posted_row['episode_title'] ?? ''), 'as', 'UTF-8');
        $note = mb_convert_kana(trim($posted_row['note'] ?? ''), 'as', 'UTF-8');
        $creators = normalize_structured_creators_json($posted_row['creators_json'] ?? '');

        $rows_input[$i] = [
            'page_start' => $page_start,
            'work_id' => $work_id,
            'title_main' => $title_main,
            'creator_name' => $creator_name,
            'creators' => $creators,
            'episode_no' => $episode_no,
            'episode_title' => $episode_title,
            'is_one_shot' => isset($posted_row['is_one_shot']) ? 1 : 0,
            'is_guest_publication' => isset($posted_row['is_guest_publication']) ? 1 : 0,
            'is_new_series' => isset($posted_row['is_new_series']) ? 1 : 0,
            'is_final_episode' => isset($posted_row['is_final_episode']) ? 1 : 0,
            'note' => $note,
        ];
    }

    try {
        if ($issue_id <= 0) {
            throw new Exception('雑誌号を選択してください。');
        }

        $valid_rows = [];

        for ($i = 0; $i < BULK_ROW_COUNT; $i++) {
            $page_start = $rows_input[$i]['page_start'];
            $work_id = (int)$rows_input[$i]['work_id'];
            $title_main = $rows_input[$i]['title_main'];
            $creator_name = $rows_input[$i]['creator_name'];
            $creators = $rows_input[$i]['creators'] ?? [];
            $episode_no = $rows_input[$i]['episode_no'];
            $episode_title = $rows_input[$i]['episode_title'];
            $note = $rows_input[$i]['note'];

            $is_one_shot = (int)$rows_input[$i]['is_one_shot'];
            $is_guest_publication = (int)$rows_input[$i]['is_guest_publication'];
            $is_new_series = (int)$rows_input[$i]['is_new_series'];
            $is_final_episode = (int)$rows_input[$i]['is_final_episode'];

            $is_empty_row = (
                $page_start === '' &&
                $title_main === '' &&
                $creator_name === '' &&
                empty($creators) &&
                $episode_no === '' &&
                $episode_title === '' &&
                $note === '' &&
                $is_one_shot === 0 &&
                $is_guest_publication === 0 &&
                $is_new_series === 0 &&
                $is_final_episode === 0
            );

            if ($is_empty_row) {
                continue;
            }

            if ($title_main === '') {
                throw new Exception(($i + 1) . '行目：タイトルを入力してください。');
            }

            if ($creator_name === '' && empty($creators)) {
                throw new Exception(($i + 1) . '行目：作者名を入力してください。');
            }

            if (!empty($creators)) {
                $creator_rows = $creators;
            } else {
                $creator_rows = build_creator_rows_from_name_bulk($creator_name);
                
                // デバッグ情報をセッションに保存（表示用）
                if (!isset($_SESSION['debug_splits'])) {
                    $_SESSION['debug_splits'] = [];
                }
                $_SESSION['debug_splits'][] = [
                    'row' => $i + 1,
                    'input' => $creator_name,
                    'output' => $creator_rows,
                    'debug_html' => debug_split_creator_names($creator_name),
                ];
            }

            if (empty($creator_rows)) {
                throw new Exception(($i + 1) . '行目：作者名を入力してください。');
            }

            $episode_no_error = validate_episode_no_bulk($episode_no, $i + 1);
            if ($episode_no_error !== '') {
                throw new Exception($episode_no_error);
            }

            if ($page_start !== '' && !preg_match('/^[0-9]+$/', $page_start)) {
                throw new Exception(($i + 1) . '行目：開始ページは数字で入力してください。');
            }

            if ($is_one_shot && $is_guest_publication) {
                throw new Exception(($i + 1) . '行目：読切と出張掲載は同時に選択できません。');
            }

            if ($is_one_shot) {
                $publication_type = '読切';
            } elseif ($is_guest_publication) {
                $publication_type = '出張掲載';
            } else {
                $publication_type = '連載';
            }

            $valid_rows[] = [
                'page_start' => $page_start,
                'work_id' => $work_id,
                'title_main' => $title_main,
                'creator_name' => $creator_name,
                'creators' => $creators,
                'creator_rows' => $creator_rows,
                'episode_no' => $episode_no,
                'episode_title' => $episode_title,
                'publication_type' => $publication_type,
                'is_new_series' => $is_new_series,
                'is_final_episode' => $is_final_episode,
                'note' => $note,
            ];
        }

        if (empty($valid_rows)) {
            throw new Exception('登録する行がありません。');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id
            FROM magazine_issues
            WHERE id = :id
              AND status IN (" . editable_issue_status_sql() . ")
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $issue_id
        ]);
        $issue_exists = $stmt->fetch();

        if (!$issue_exists) {
            throw new Exception('選択した雑誌号が見つかりません。');
        }

        $inserted_count = 0;

        foreach ($valid_rows as $row) {
            $work_id = get_or_create_work_id($pdo, $row['title_main'], $row['publication_type'], $row['work_id']);

            $stmt = $pdo->prepare("
                INSERT INTO issue_contents (
                    issue_id,
                    work_id,
                    sort_order,
                    page_start,
                    title_main,
                    episode_no,
                    episode_title,
                    note,
                    content_type,
                    publication_type,
                    is_new_series,
                    is_final_episode
                ) VALUES (
                    :issue_id,
                    :work_id,
                    :sort_order,
                    :page_start,
                    :title_main,
                    :episode_no,
                    :episode_title,
                    :note,
                    'manga',
                    :publication_type,
                    :is_new_series,
                    :is_final_episode
                )
            ");

            $stmt->execute([
                ':issue_id' => $issue_id,
                ':work_id' => $work_id,
                ':sort_order' => 999999,
                ':page_start' => ($row['page_start'] !== '') ? (int)$row['page_start'] : null,
                ':title_main' => $row['title_main'],
                ':episode_no' => ($row['episode_no'] !== '') ? $row['episode_no'] : null,
                ':episode_title' => ($row['episode_title'] !== '') ? $row['episode_title'] : null,
                ':note' => ($row['note'] !== '') ? $row['note'] : null,
                ':publication_type' => $row['publication_type'],
                ':is_new_series' => $row['is_new_series'],
                ':is_final_episode' => $row['is_final_episode'],
            ]);

            $content_id = (int)$pdo->lastInsertId();

            $display_order = 1;
            $creator_rows = $row['creator_rows'];

            foreach ($creator_rows as $creator_row) {
                $creator_name_one = trim((string)($creator_row['name'] ?? ''));
                if ($creator_name_one === '') {
                    continue;
                }

                $creator_role = trim((string)($creator_row['role'] ?? ''));
                $parsed_creator = split_creator_role_colon_bulk($creator_name_one, $creator_role);

                if ($parsed_creator !== null) {
                    $creator_name_one = trim((string)$parsed_creator['name']);
                    $creator_role = trim((string)$parsed_creator['role']);
                }

                if ($creator_name_one === '') {
                    continue;
                }

                if ($creator_role === '') {
                    $creator_role = 'author';
                }

                $creator_id = get_or_create_creator_id($pdo, $creator_name_one);
                $role_id = get_or_create_role_id_bulk($pdo, $creator_role);

                $stmt = $pdo->prepare("
                    INSERT INTO content_creators (
                        content_id,
                        creator_id,
                        role_id,
                        role,
                        display_order,
                        is_visible
                    ) VALUES (
                        :content_id,
                        :creator_id,
                        :role_id,
                        :role,
                        :display_order,
                        1
                    )
                ");
                $stmt->execute([
                    ':content_id' => $content_id,
                    ':creator_id' => $creator_id,
                    ':role_id' => $role_id,
                    ':role' => $creator_role,
                    ':display_order' => $display_order
                ]);

                $display_order++;
            }

            $inserted_count++;
        }

        renumber_issue_contents($pdo, $issue_id);

        $pdo->commit();

        unset($_SESSION['toc_extract_rows'], $_SESSION['toc_extract_issue_id']);

        // リダイレクト先URL を作成（デバッグログは常時出力、表示制御はセッションで管理）
        $redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count . '&debug_logs=1';
        
        header('Location: ' . $redirect_url);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = '登録に失敗しました。入力内容は保持されています。登録エラー：' . $e->getMessage();
    }
}

// 選択中の雑誌号情報
$selected_issue = null;

if ($issue_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            mi.id,
            mi.issue_label,
            mi.release_date,
            mi.issue_year,
            mi.issue_no,
            m.name AS magazine_name,
            p.name AS publisher_name
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE mi.id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $issue_id
    ]);
    $selected_issue = $stmt->fetch();
}

// 登録済み目次一覧
$existing_contents = [];

if ($issue_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            ic.id,
            ic.sort_order,
            ic.page_start,
            ic.title_main,
            ic.episode_no,
            ic.episode_title,
            ic.publication_type,
            ic.is_new_series,
            ic.is_final_episode,
            ic.note,
            GROUP_CONCAT(c.name ORDER BY cc.display_order SEPARATOR '、') AS creator_names
        FROM issue_contents ic
        LEFT JOIN content_creators cc ON ic.id = cc.content_id
        LEFT JOIN creators c ON cc.creator_id = c.id
        WHERE ic.issue_id = :issue_id
        GROUP BY
            ic.id,
            ic.sort_order,
            ic.page_start,
            ic.title_main,
            ic.episode_no,
            ic.episode_title,
            ic.publication_type,
            ic.is_new_series,
            ic.is_final_episode,
            ic.note
        ORDER BY
            ic.sort_order ASC,
            CASE
                WHEN ic.page_start IS NULL THEN 1
                ELSE 0
            END ASC,
            ic.page_start ASC,
            ic.id ASC
    ");
    $stmt->execute([
        ':issue_id' => $issue_id
    ]);
    $existing_contents = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>目次一括登録 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1360px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 { margin-top: 0; }

        h2 {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 20px;
        }

        .nav { margin-bottom: 20px; }

        .nav a,
        .message a {
            margin-right: 12px;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
        }

        .nav a:hover,
        .message a:hover { text-decoration: underline; }

        .message {
            padding: 12px;
            background: #e8f5e9;
            border: 1px solid #81c784;
            margin-bottom: 16px;
            border-radius: 4px;
            line-height: 1.8;
        }

        .error {
            padding: 12px;
            background: #ffebee;
            border: 1px solid #e57373;
            margin-bottom: 16px;
            border-radius: 4px;
            color: #b00020;
        }

        .form-row { margin-bottom: 16px; }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            padding: 10px 20px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover { background: #111; }

        .required {
            color: #b00020;
            font-size: 13px;
        }

        .help {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
            line-height: 1.6;
        }

        .selected-issue {
            padding: 12px;
            margin-bottom: 16px;
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 6px;
            line-height: 1.7;
        }

        .bulk-table-wrap {
            overflow-x: auto;
            margin-top: 16px;
        }

        .bulk-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1320px;
        }

        .bulk-table th,
        .bulk-table td {
            border: 1px solid #ddd;
            padding: 6px;
            position: relative;
            vertical-align: middle;
            font-size: 13px;
        }

        .bulk-table th {
            background: #f0f0f0;
            white-space: nowrap;
        }

        .col-no {
            width: 42px;
            text-align: center;
            color: #666;
        }

        .col-page { width: 80px; }
        .col-title { width: 220px; }
        .col-creator { width: 170px; }
        .col-episode-no { width: 70px; }
        .col-episode-title { width: 220px; }
        .col-check {
            width: 64px;
            text-align: center;
            white-space: nowrap;
        }

        .col-note { width: 220px; }

        .check-cell { text-align: center; }

        .check-cell input { transform: scale(1.15); }

        .suggest-wrap {
            position: relative;
        }

        .suggest-list {
            display: none;
            position: absolute;
            z-index: 20;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #bbb;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .suggest-list.is-open {
            display: block;
        }

        .suggest-item {
            width: 100%;
            display: block;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 0;
            border-bottom: 1px solid #eee;
            background: #fff;
            color: #222;
            text-align: left;
            font-size: 14px;
            line-height: 1.5;
            cursor: pointer;
        }

        .suggest-item:last-child {
            border-bottom: 0;
        }

        .suggest-item:hover,
        .suggest-item:focus {
            background: #f0f6ff;
            outline: none;
        }

        .creator-autofill-note {
            display: none;
            margin-top: 4px;
            color: #2e7d32;
            font-size: 12px;
            line-height: 1.4;
        }

        .creator-autofill-note.is-visible {
            display: block;
        }

        .existing-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .existing-table th,
        .existing-table td {
            border: 1px solid #ddd;
            padding: 8px;
            font-size: 13px;
            vertical-align: top;
        }

        .existing-table th { background: #f0f0f0; }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            background: #eee;
            font-size: 12px;
            margin-right: 4px;
            margin-bottom: 3px;
        }

        .badge-strong {
            background: #222;
            color: #fff;
        }

        .empty-note {
            color: #888;
            font-size: 13px;
        }

        @media (max-width: 700px) {
            body { margin: 8px; }
            .container { padding: 12px; }
        }
    </style>

    <script>
        const shouldRestoreBulkDraft = <?= json_encode($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['saved']) && !isset($_GET['from_toc_extract'])) ?>;
        const shouldClearBulkDraft = <?= json_encode(isset($_GET['saved']) && $_GET['saved'] === '1') ?>;

        document.addEventListener('DOMContentLoaded', function () {
            // チェックボックスの状態で隠しフィールドを初期化
            const checkbox = document.getElementById('toggleDebugLogs');
            const hiddenField = document.getElementById('debugLogsEnabledField');
            if (checkbox && hiddenField) {
                hiddenField.value = checkbox.checked ? '1' : '0';
            }
            
            const bulkForm = document.querySelector('form.js-content-bulk-form');
            const issueSelect = document.querySelector('select[name="issue_id"]');

            function bulkDraftKey() {
                const issueId = issueSelect ? issueSelect.value : '';
                return 'indexindex:content_bulk_add:' + issueId;
            }

            function saveBulkDraft() {
                if (!bulkForm || !window.localStorage) {
                    return;
                }

                const values = {};
                bulkForm.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (!field.name) {
                        return;
                    }

                    if (field.type === 'checkbox') {
                        values[field.name] = field.checked ? '1' : '';
                    } else {
                        values[field.name] = field.value;
                    }
                });

                try {
                    localStorage.setItem(bulkDraftKey(), JSON.stringify(values));
                } catch (error) {
                }
            }

            function restoreBulkDraft() {
                if (!bulkForm || !window.localStorage || !shouldRestoreBulkDraft) {
                    return;
                }

                let values = null;
                try {
                    values = JSON.parse(localStorage.getItem(bulkDraftKey()) || 'null');
                } catch (error) {
                    values = null;
                }

                if (!values || typeof values !== 'object') {
                    return;
                }

                bulkForm.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (!field.name || !Object.prototype.hasOwnProperty.call(values, field.name)) {
                        return;
                    }

                    if (field.type === 'checkbox') {
                        field.checked = values[field.name] === '1';
                    } else {
                        field.value = values[field.name];
                    }
                });
            }

            function clearBulkDraft() {
                if (!window.localStorage) {
                    return;
                }

                try {
                    localStorage.removeItem(bulkDraftKey());
                } catch (error) {
                }
            }

            if (shouldClearBulkDraft) {
                clearBulkDraft();
            } else {
                restoreBulkDraft();
            }

            let draftTimer = null;
            if (bulkForm) {
                bulkForm.addEventListener('input', function () {
                    window.clearTimeout(draftTimer);
                    draftTimer = window.setTimeout(saveBulkDraft, 250);
                });

                bulkForm.addEventListener('change', function () {
                    window.clearTimeout(draftTimer);
                    draftTimer = window.setTimeout(saveBulkDraft, 100);
                });
            }

            const rows = document.querySelectorAll('.bulk-table tbody tr');

            rows.forEach(function (row) {
                const oneShot = row.querySelector('.js-one-shot');
                const guest = row.querySelector('.js-guest-publication');

                if (!oneShot || !guest) {
                    return;
                }

                oneShot.addEventListener('change', function () {
                    if (oneShot.checked) {
                        guest.checked = false;
                    }
                });

                guest.addEventListener('change', function () {
                    if (guest.checked) {
                        oneShot.checked = false;
                    }
                });
            });

            let suggestTimer = null;
            let activeController = null;

            function closeSuggestList(list) {
                if (!list) {
                    return;
                }

                list.classList.remove('is-open');
                list.innerHTML = '';
            }

            function closeAllSuggestLists(exceptList) {
                document.querySelectorAll('.suggest-list').forEach(function (list) {
                    if (list !== exceptList) {
                        closeSuggestList(list);
                    }
                });
            }

            function renderSuggestList(input, list, values) {
                closeSuggestList(list);

                if (!Array.isArray(values) || values.length === 0) {
                    return;
                }

                values.forEach(function (row) {
                    if (!row || typeof row.value !== 'string' || row.value === '') {
                        return;
                    }

                    const label = (typeof row.label === 'string' && row.label !== '') ? row.label : row.value;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggest-item';
                    button.textContent = label;
                    button.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                    });
                    button.addEventListener('click', function () {
                        input.value = row.value;
                        const workIdInput = input.parentElement.querySelector('input[name$="[work_id]"]');
                        if (workIdInput) {
                            workIdInput.value = row.work_id ? String(row.work_id) : '0';
                        }
                        saveBulkDraft();
                        closeSuggestList(list);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        input.focus();
                    });

                    list.appendChild(button);
                });

                if (list.children.length > 0) {
                    closeAllSuggestLists(list);
                    list.classList.add('is-open');
                }
            }

            function fetchSuggestions(input) {
                const q = input.value.trim();
                const type = input.dataset.suggestType || '';
                const list = input.parentElement.querySelector('.suggest-list');
                const issueId = issueSelect ? issueSelect.value : '';

                if (q === '' || issueId === '' || (type !== 'title' && type !== 'creator')) {
                    closeSuggestList(list);
                    return;
                }

                if (activeController) {
                    activeController.abort();
                }

                activeController = new AbortController();

                const params = new URLSearchParams({
                    type: type,
                    issue_id: issueId,
                    q: q
                });

                fetch('content_suggest.php?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    },
                    signal: activeController.signal
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('suggest request failed');
                        }

                        return response.json();
                    })
                    .then(function (data) {
                        renderSuggestList(input, list, data);
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            closeSuggestList(list);
                        }
                    });
            }

            document.querySelectorAll('.js-content-suggest').forEach(function (input) {
                input.setAttribute('autocomplete', 'off');

                input.addEventListener('input', function () {
                    if (input.dataset.suggestType === 'title') {
                        const workIdInput = input.parentElement.querySelector('input[name$="[work_id]"]');
                        if (workIdInput) {
                            workIdInput.value = '0';
                        }
                    }

                    window.clearTimeout(suggestTimer);
                    suggestTimer = window.setTimeout(function () {
                        fetchSuggestions(input);
                    }, 180);
                });

                input.addEventListener('focus', function () {
                    if (input.value.trim() !== '') {
                        fetchSuggestions(input);
                    }
                });

                input.addEventListener('blur', function () {
                    const list = input.parentElement.querySelector('.suggest-list');
                    window.setTimeout(function () {
                        closeSuggestList(list);
                    }, 150);
                });
            });

            function hideCreatorAutofillNote(row) {
                const note = row.querySelector('.creator-autofill-note');
                if (note) {
                    note.classList.remove('is-visible');
                    note.textContent = '';
                }
            }

            function showCreatorAutofillNote(row) {
                const note = row.querySelector('.creator-autofill-note');
                if (note) {
                    note.textContent = '既存作品から作者を自動入力しました';
                    note.classList.add('is-visible');
                }
            }

            function lookupCreatorsByTitle(titleInput) {
                const row = titleInput.closest('tr');
                const title = titleInput.value.trim();

                if (!row || title === '') {
                    if (row) {
                        hideCreatorAutofillNote(row);
                    }
                    return;
                }

                const creatorInput = row.querySelector('input[name$="[creator_name]"]');

                if (!creatorInput || creatorInput.value.trim() !== '') {
                    hideCreatorAutofillNote(row);
                    return;
                }

                hideCreatorAutofillNote(row);

                const params = new URLSearchParams({
                    action: 'lookup_creators_by_title',
                    title: title
                });

                fetch('content_bulk_add.php?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('creator lookup failed');
                        }

                        return response.json();
                    })
                    .then(function (data) {
                        if (
                            titleInput.value.trim() === title &&
                            creatorInput.value.trim() === '' &&
                            data &&
                            typeof data.creators === 'string' &&
                            data.creators !== ''
                        ) {
                            creatorInput.value = data.creators;
                            showCreatorAutofillNote(row);
                        } else {
                            hideCreatorAutofillNote(row);
                        }
                    })
                    .catch(function () {
                        hideCreatorAutofillNote(row);
                    });
            }

            document.querySelectorAll('input[data-suggest-type="title"]').forEach(function (input) {
                let creatorLookupTimer = null;

                input.addEventListener('input', function () {
                    const row = input.closest('tr');
                    if (row) {
                        hideCreatorAutofillNote(row);
                    }

                    window.clearTimeout(creatorLookupTimer);
                    creatorLookupTimer = window.setTimeout(function () {
                        lookupCreatorsByTitle(input);
                    }, 450);
                });

                input.addEventListener('change', function () {
                    window.clearTimeout(creatorLookupTimer);
                    lookupCreatorsByTitle(input);
                });

                input.addEventListener('blur', function () {
                    window.clearTimeout(creatorLookupTimer);
                    lookupCreatorsByTitle(input);
                });
            });

            document.querySelectorAll('input[name$="[creator_name]"]').forEach(function (input) {
                input.addEventListener('input', function () {
                    const row = input.closest('tr');
                    if (row) {
                        hideCreatorAutofillNote(row);

                        const creatorsJsonInput = row.querySelector('input[name$="[creators_json]"]');
                        if (creatorsJsonInput) {
                            creatorsJsonInput.value = '[]';
                        }
                    }
                });
            });

            document.querySelectorAll('.js-episode-no').forEach(function (input) {
                input.addEventListener('input', function () {
                    input.value = input.value
                        .replace(/[０-９]/g, function (char) {
                            return String.fromCharCode(char.charCodeAt(0) - 0xFEE0);
                        })
                        .replace(/[^0-9]/g, '');
                });
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.suggest-wrap, .js-content-suggest, .suggest-list')) {
                    closeAllSuggestLists(null);
                }
            });
        });
        
        // 詳細ログの表示/非表示を切り替える
        window.toggleDebugLogs = function() {
            const checkbox = document.getElementById('toggleDebugLogs');
            const container = document.getElementById('debugLogsContainer');
            const hiddenField = document.getElementById('debugLogsEnabledField');
            
            if (!checkbox || !hiddenField) return;
            
            // 隠しフィールドを更新（チェック状態をPOSTで送信）
            hiddenField.value = checkbox.checked ? '1' : '0';
            
            if (!container) return;  // ログコンテナがない場合はここで終了
            
            if (checkbox.checked) {
                container.style.display = 'block';
                // URLにdebug_logs=1を追加（リロード時に状態を保持）
                const url = new URL(window.location);
                url.searchParams.set('debug_logs', '1');
                window.history.replaceState({}, '', url.toString());
            } else {
                container.style.display = 'none';
                // URLからdebug_logsパラメータを削除
                const url = new URL(window.location);
                url.searchParams.delete('debug_logs');
                window.history.replaceState({}, '', url.toString());
            }
        };
    </script>
</head>
<body>
<div class="container">

    <h1>目次一括登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="content_add.php<?= ($issue_id > 0) ? '?issue_id=' . h($issue_id) : '' ?>">目次登録</a>
        <a href="toc_extract_tool_ai.php<?= ($issue_id > 0) ? '?issue_id=' . h($issue_id) : '' ?>">目次抽出TOOL</a>
        <a href="content_list.php<?= ($issue_id > 0) ? '?issue_id=' . h($issue_id) : '' ?>">目次一覧</a>
        <a href="../issues.php">公開：雑誌号一覧</a>
        <?php if ($issue_id > 0): ?>
            <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
        <?php endif; ?>
    </div>

    <!-- 詳細ログ表示の切り替え（常に表示） -->
    <div style="margin: 15px 0; padding: 10px; background: #f5f5f5; border: 1px solid #ddd;">
        <label style="cursor: pointer; user-select: none;">
            <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()" 
                <?= $show_debug_logs ? 'checked' : '' ?>>
            <strong style="color: #333;">詳細ログを表示（作者名分割処理）</strong>
        </label>
    </div>
    
    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <?php if ((int)$issue_id > 0): ?>
                <a href="content_list.php?issue_id=<?= h($issue_id) ?>">この雑誌号の目次一覧を開く</a>
                <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- デバッグ情報表示エリア（登録後のみ、チェック状態に応じて表示） -->
    <?php if (!empty($debug_info)): ?>
        <div id="debugLogsContainer" style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
            <div style="background: #f0f0f0; border: 2px solid #666; padding: 15px; margin: 20px 0;">
                <h3 style="color: #333; margin-top: 0;">【詳細：作者名分割処理ログ】</h3>
                <p style="color: #666; font-size: 12px; margin: 5px 0;">
                    区切り文字：「、」「,」「／」「/」（スペースは区切りません）<br>
                    全 <?= count($debug_info) ?> 件の作者名を処理しました
                </p>
                <?php foreach ($debug_info as $debug): ?>
                    <div style="background: #fff; border: 1px solid #ccc; padding: 10px; margin: 10px 0;">
                        <strong style="color: #0066cc;">行 <?= $debug['row'] ?>:</strong>
                        <span style="color: #333;">入力値: <code style="background: #f5f5f5; padding: 2px 5px;"><?= htmlspecialchars($debug['input'], ENT_QUOTES, 'UTF-8') ?></code></span>
                        <?= $debug['debug_html'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>
    
    <!-- 登録前ユーザーへの説明 -->
    <?php if ($message === ''): ?>
        <p style="color: #999; font-size: 12px; margin-top: 10px;">
            💡 チェックボックスをONにすると、登録後に作者名の分割処理ログが表示されます
        </p>
    <?php endif; ?>

    <?php if ($selected_issue): ?>
        <div class="selected-issue">
            <strong>選択中：</strong>
            <?= h($selected_issue['magazine_name']) ?>
            <?= h($selected_issue['issue_label']) ?>
            /
            <?= h($selected_issue['publisher_name']) ?>
            <?php if (!empty($selected_issue['issue_no'])): ?>
                /
                <?= h($selected_issue['issue_no']) ?>号
            <?php endif; ?>
            <?php if (!empty($selected_issue['release_date'])): ?>
                /
                <?= h($selected_issue['release_date']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="content_bulk_add.php<?= ($issue_id > 0) ? '?issue_id=' . h($issue_id) : '' ?>" class="js-content-bulk-form">
        <!-- デバッグログ表示状態をPOSTで送信（チェックボックスの状態を保持） -->
        <input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="0">

        <div class="form-row">
            <label>
                雑誌号 <span class="required">必須</span>
            </label>
            <select name="issue_id">
                <option value="">選択してください</option>
                <?php foreach ($issues as $row): ?>
                    <option value="<?= h($row['id']) ?>" <?= ((string)$issue_id === (string)$row['id']) ? 'selected' : '' ?>>
                        <?= h($row['magazine_name']) ?>
                        <?= h($row['issue_label']) ?>
                        /
                        <?= h($row['publisher_name']) ?>
                        <?php if (!empty($row['issue_no'])): ?>
                            /
                            <?= h($row['issue_no']) ?>号
                        <?php endif; ?>
                        <?php if (!empty($row['release_date'])): ?>
                            /
                            <?= h($row['release_date']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="help">
                入力した行だけ登録します。読切・出張掲載が未チェックの場合は「連載」として登録します。話数は数字だけで保存します。複数人入力は「まんが:〇〇, 原案:△△」のように半角カンマ区切りで入力できます。
            </div>
        </div>

        <div class="bulk-table-wrap">
            <table class="bulk-table">
                <thead>
                    <tr>
                        <th class="col-no">No</th>
                        <th class="col-page">開始P</th>
                        <th class="col-title">タイトル</th>
                        <th class="col-creator">作者名</th>
                        <th class="col-episode-no">話数</th>
                        <th class="col-episode-title">話タイトル</th>
                        <th class="col-check">読切</th>
                        <th class="col-check">出張</th>
                        <th class="col-check">新連載</th>
                        <th class="col-check">最終回</th>
                        <th class="col-note">備考</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < BULK_ROW_COUNT; $i++): ?>
                        <?php $creators_json = json_encode($rows_input[$i]['creators'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                        <tr>
                            <td class="col-no"><?= h($i + 1) ?></td>

                            <td>
                                <input
                                    type="number"
                                    name="rows[<?= h($i) ?>][page_start]"
                                    value="<?= h($rows_input[$i]['page_start'] ?? '') ?>"
                                    placeholder="例：3"
                                >
                            </td>

                            <td>
                                <input
                                    type="text"
                                    class="js-content-suggest"
                                    data-suggest-type="title"
                                    name="rows[<?= h($i) ?>][title_main]"
                                    value="<?= h($rows_input[$i]['title_main'] ?? '') ?>"
                                    placeholder="タイトル"
                                >
                                <input
                                    type="hidden"
                                    name="rows[<?= h($i) ?>][work_id]"
                                    value="<?= h($rows_input[$i]['work_id'] ?? 0) ?>"
                                >
                                <div class="suggest-list"></div>
                                <div class="creator-autofill-note"></div>
                            </td>

                            <td>
                                <input
                                    type="text"
                                    class="js-content-suggest"
                                    data-suggest-type="creator"
                                    name="rows[<?= h($i) ?>][creator_name]"
                                    value="<?= h($rows_input[$i]['creator_name'] ?? '') ?>"
                                    placeholder="作者名"
                                >
                                <input
                                    type="hidden"
                                    name="rows[<?= h($i) ?>][creators_json]"
                                    value="<?= h($creators_json ?: '[]') ?>"
                                >
                                <div class="suggest-list"></div>
                            </td>

                            <td>
                                <input
                                    type="text"
                                    class="js-episode-no"
                                    name="rows[<?= h($i) ?>][episode_no]"
                                    value="<?= h($rows_input[$i]['episode_no'] ?? '') ?>"
                                    placeholder="例：79"
                                    inputmode="numeric"
                                >
                            </td>

                            <td>
                                <input
                                    type="text"
                                    name="rows[<?= h($i) ?>][episode_title]"
                                    value="<?= h($rows_input[$i]['episode_title'] ?? '') ?>"
                                    placeholder="話タイトル"
                                >
                            </td>

                            <td class="check-cell">
                                <input
                                    type="checkbox"
                                    class="js-one-shot"
                                    name="rows[<?= h($i) ?>][is_one_shot]"
                                    value="1"
                                    <?= !empty($rows_input[$i]['is_one_shot']) ? 'checked' : '' ?>
                                >
                            </td>

                            <td class="check-cell">
                                <input
                                    type="checkbox"
                                    class="js-guest-publication"
                                    name="rows[<?= h($i) ?>][is_guest_publication]"
                                    value="1"
                                    <?= !empty($rows_input[$i]['is_guest_publication']) ? 'checked' : '' ?>
                                >
                            </td>

                            <td class="check-cell">
                                <input
                                    type="checkbox"
                                    name="rows[<?= h($i) ?>][is_new_series]"
                                    value="1"
                                    <?= !empty($rows_input[$i]['is_new_series']) ? 'checked' : '' ?>
                                >
                            </td>

                            <td class="check-cell">
                                <input
                                    type="checkbox"
                                    name="rows[<?= h($i) ?>][is_final_episode]"
                                    value="1"
                                    <?= !empty($rows_input[$i]['is_final_episode']) ? 'checked' : '' ?>
                                >
                            </td>

                            <td>
                                <input
                                    type="text"
                                    name="rows[<?= h($i) ?>][note]"
                                    value="<?= h($rows_input[$i]['note'] ?? '') ?>"
                                    placeholder="例：巻頭カラー"
                                >
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="help">
            備考欄に入力した文字は、そのまま目次の備考に保存します。
        </div>

        <div class="form-row" style="margin-top: 18px;">
            <button type="submit">一括登録する</button>
        </div>

    </form>

    <h2>登録済み目次</h2>

    <?php if ($issue_id <= 0): ?>
        <p class="empty-note">雑誌号を選択すると、登録済み目次を表示します。</p>
    <?php elseif (empty($existing_contents)): ?>
        <p class="empty-note">この雑誌号には、まだ目次が登録されていません。</p>
    <?php else: ?>
        <table class="existing-table">
            <thead>
                <tr>
                    <th>順</th>
                    <th>開始P</th>
                    <th>タイトル</th>
                    <th>話数</th>
                    <th>話タイトル</th>
                    <th>作者</th>
                    <th>区分</th>
                    <th>フラグ</th>
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($existing_contents as $content): ?>
                    <tr>
                        <td><?= h($content['sort_order']) ?></td>
                        <td><?= h($content['page_start']) ?></td>
                        <td><?= h($content['title_main']) ?></td>
                        <td><?= h($content['episode_no']) ?></td>
                        <td><?= h($content['episode_title']) ?></td>
                        <td><?= h($content['creator_names']) ?></td>
                        <td>
                            <?php if (!empty($content['publication_type'])): ?>
                                <span class="badge"><?= h($content['publication_type']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$content['is_new_series'] === 1): ?>
                                <span class="badge badge-strong">新連載</span>
                            <?php endif; ?>

                            <?php if ((int)$content['is_final_episode'] === 1): ?>
                                <span class="badge badge-strong">最終回</span>
                            <?php endif; ?>
                        </td>
                        <td><?= nl2br(h($content['note'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>
</body>
</html>
