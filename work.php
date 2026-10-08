<?php
// work.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('作品ページ（個別）');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mediamix_type_label($type) {
    $labels = [
        'tv_anime' => 'TVアニメ',
        'anime_movie' => 'アニメ映画',
        'live_action_movie' => '実写映画',
        'tv_drama' => 'TVドラマ',
        'stage' => '舞台',
        'ova' => 'OVA',
        'web_anime' => 'Webアニメ',
        'game' => 'ゲーム',
        'other' => 'その他',
    ];

    return $labels[$type] ?? $type;
}

function kana_like_values($value) {
    $value = (string)$value;
    $hira = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'c', 'UTF-8') : $value;
    $kata = function_exists('mb_convert_kana') ? mb_convert_kana($value, 'C', 'UTF-8') : $value;

    return [
        'base' => '%' . $value . '%',
        'hira' => '%' . $hira . '%',
        'kata' => '%' . $kata . '%',
    ];
}

// 1〜9999程度までの整数を漢数字に変換
function number_to_kanji($number) {
    $number = (int)$number;

    if ($number <= 0) {
        return (string)$number;
    }

    $digits = [
        0 => '',
        1 => '一',
        2 => '二',
        3 => '三',
        4 => '四',
        5 => '五',
        6 => '六',
        7 => '七',
        8 => '八',
        9 => '九'
    ];

    $units = [
        1000 => '千',
        100  => '百',
        10   => '十'
    ];

    $result = '';
    $remaining = $number;

    foreach ($units as $unit_value => $unit_label) {
        $digit = intdiv($remaining, $unit_value);

        if ($digit > 0) {
            if ($digit > 1) {
                $result .= $digits[$digit];
            }

            $result .= $unit_label;
            $remaining = $remaining % $unit_value;
        }
    }

    if ($remaining > 0) {
        $result .= $digits[$remaining];
    }

    return $result;
}

function format_episode_number_value($episode_no, $number_style) {
    $episode_no = (string)$episode_no;

    if (!preg_match('/^[0-9]+$/', $episode_no)) {
        return $episode_no;
    }

    $number_style = in_array((string)$number_style, ['roman', 'kanji', 'old_kanji'], true) ? (string)$number_style : '';

    if ($number_style === 'kanji') {
        return number_to_kanji($episode_no);
    }

    if ($number_style === 'old_kanji') {
        return number_to_old_kanji($episode_no);
    }

    if ($number_style === 'roman') {
        return number_to_roman($episode_no);
    }

    return $episode_no;
}

function number_to_old_kanji($number) {
    $number = (int)$number;

    if ($number <= 0) {
        return (string)$number;
    }

    $digits = [0 => '', 1 => '壱', 2 => '弐', 3 => '参', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九'];
    $units = [100 => '百', 10 => '拾'];
    $result = '';
    $remaining = $number;

    foreach ($units as $unit_value => $unit_label) {
        $digit = intdiv($remaining, $unit_value);

        if ($digit > 0) {
            if ($digit > 1) {
                $result .= $digits[$digit];
            }

            $result .= $unit_label;
            $remaining = $remaining % $unit_value;
        }
    }

    if ($remaining > 0) {
        $result .= $digits[$remaining];
    }

    return $result;
}

function number_to_roman($number) {
    $number = (int)$number;
    $unicode_roman = [1 => 'Ⅰ', 2 => 'Ⅱ', 3 => 'Ⅲ', 4 => 'Ⅳ', 5 => 'Ⅴ', 6 => 'Ⅵ', 7 => 'Ⅶ', 8 => 'Ⅷ', 9 => 'Ⅸ', 10 => 'Ⅹ', 11 => 'Ⅺ', 12 => 'Ⅻ'];

    if (isset($unicode_roman[$number])) {
        return $unicode_roman[$number];
    }

    if ($number <= 0) {
        return (string)$number;
    }

    $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
    $result = '';

    foreach ($map as $value => $label) {
        while ($number >= $value) {
            $result .= $label;
            $number -= $value;
        }
    }

    return $result;
}

function build_note_text($row) {
    $notes = [];

    if (!empty($row['note'])) {
        $notes[] = $row['note'];
    }

    if (($row['publication_status'] ?? '') === 'reprint') {
        $reprint_text = '再録';

        if (!empty($row['original_publisher_name'])) {
            $reprint_text .= '／' . $row['original_publisher_name'];
        }

        $original_issue_text = '';

        if (!empty($row['original_magazine_name'])) {
            $original_issue_text .= $row['original_magazine_name'];
        }

        if (!empty($row['original_issue_label'])) {
            $original_issue_text .= $row['original_issue_label'];
        }

        if ($original_issue_text !== '') {
            $reprint_text .= '／' . $original_issue_text;
        }

        $notes[] = $reprint_text;
    }

    return implode('　／　', $notes);
}

function render_creator_links($creators_data) {
    if (trim((string)$creators_data) === '') {
        return '';
    }

    $items = explode('||CREATOR||', (string)$creators_data);
    $parts = [];

    foreach ($items as $item) {
        if ($item === '') {
            continue;
        }

        $pieces = explode('||FIELD||', $item, 2);
        $creator_id = isset($pieces[0]) ? (int)$pieces[0] : 0;
        $creator_label = trim((string)($pieces[1] ?? ''));

        if ($creator_label === '') {
            continue;
        }

        if ($creator_id > 0) {
            $parts[] = '<a class="creator-link" href="creator.php?creator_id=' . h($creator_id) . '">' . h($creator_label) . '</a>';
        } else {
            $parts[] = h($creator_label);
        }
    }

    return implode(' / ', $parts);
}

$work_id_raw = $_GET['work_id'] ?? ($_GET['id'] ?? null);
if (!is_string($work_id_raw) || !ctype_digit($work_id_raw) || (int)$work_id_raw <= 0) {
    http_response_code(400);
    exit('作品IDが指定されていません。');
}

$work_id = (int)$work_id_raw;
$keyword = isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '';

// 作品情報
$stmt = $pdo->prepare("
    SELECT
        w.id,
        w.title,
        w.title_sub,
        w.publication_type,
        w.episode_format_id,
        ef.name AS episode_format_name,
        ef.prefix AS episode_format_prefix,
        ef.suffix AS episode_format_suffix,
        ef.number_style AS episode_number_style
    FROM works w
    LEFT JOIN episode_formats ef ON w.episode_format_id = ef.id
    WHERE w.id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $work_id
]);
$work = $stmt->fetch();

if (!$work) {
    http_response_code(404);
    exit('指定された作品が見つかりません。');
}

// 作品としての標準人物（掲載ごとの content_creators とは別に取得）
$stmt = $pdo->prepare("
    SELECT wc.creator_id, wc.role, wc.display_order, c.name AS creator_name
    FROM work_creators wc
    JOIN creators c ON c.id = wc.creator_id
    WHERE wc.work_id = :work_id
    ORDER BY wc.display_order ASC, wc.id ASC
");
$stmt->execute([
    ':work_id' => $work_id
]);
$work_creators = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_count
    FROM issue_contents
    WHERE work_id = :work_id
");
$stmt->execute([
    ':work_id' => $work_id
]);
$work_total_content_count = (int)($stmt->fetch()['total_count'] ?? 0);
$should_noindex = ($work_total_content_count === 0);

$keyword_where_sql = '';
$keyword_params = [];
if ($keyword !== '') {
    $keyword_where_sql = "
      AND (
          w.title LIKE :keyword_work_title
          OR w.title_sub LIKE :keyword_work_title_sub
          OR ic.title_main LIKE :keyword_content_title
          OR m.name LIKE :keyword_magazine
          OR EXISTS (
              SELECT 1
              FROM content_creators cc_kw
              JOIN creators c_kw ON cc_kw.creator_id = c_kw.id
              WHERE cc_kw.content_id = ic.id
                AND cc_kw.is_visible = 1
                AND (
                    c_kw.name LIKE :keyword_creator
                    OR c_kw.name_search LIKE :keyword_creator_search
                    OR c_kw.name_kana LIKE :keyword_creator_kana
                    OR c_kw.name_kana LIKE :keyword_creator_kana_hira
                    OR c_kw.name_kana LIKE :keyword_creator_kana_kata
                )
          )
      )
    ";
    $keyword_like = '%' . $keyword . '%';
    $keyword_kana_likes = kana_like_values($keyword);
    $keyword_params = [
        ':keyword_work_title' => $keyword_like,
        ':keyword_work_title_sub' => $keyword_like,
        ':keyword_content_title' => $keyword_like,
        ':keyword_magazine' => $keyword_like,
        ':keyword_creator' => $keyword_like,
        ':keyword_creator_search' => $keyword_like,
        ':keyword_creator_kana' => $keyword_kana_likes['base'],
        ':keyword_creator_kana_hira' => $keyword_kana_likes['hira'],
        ':keyword_creator_kana_kata' => $keyword_kana_likes['kata'],
    ];
}

// 掲載一覧
$issue_label_year_char_sql = "CONVERT(UNHEX('E5B9B4') USING utf8mb4)";
$issue_label_month_char_sql = "CONVERT(UNHEX('E69C88') USING utf8mb4)";
$issue_label_issue_char_sql = "CONVERT(UNHEX('E58FB7') USING utf8mb4)";
$issue_label_year_pos_sql = "LOCATE({$issue_label_year_char_sql}, mi.issue_label)";
$issue_label_month_pos_sql = "LOCATE({$issue_label_month_char_sql}, mi.issue_label, {$issue_label_year_pos_sql} + 1)";
$issue_label_issue_pos_sql = "LOCATE({$issue_label_issue_char_sql}, mi.issue_label, {$issue_label_year_pos_sql} + 1)";
$issue_label_year_value_sql = "
    SUBSTRING(
        mi.issue_label,
        1,
        {$issue_label_year_pos_sql} - 1
    )
";
$issue_label_month_value_sql = "
    SUBSTRING(
        mi.issue_label,
        {$issue_label_year_pos_sql} + CHAR_LENGTH({$issue_label_year_char_sql}),
        {$issue_label_month_pos_sql} - {$issue_label_year_pos_sql} - CHAR_LENGTH({$issue_label_year_char_sql})
    )
";
$issue_label_issue_value_sql = "
    SUBSTRING(
        mi.issue_label,
        {$issue_label_year_pos_sql} + CHAR_LENGTH({$issue_label_year_char_sql}),
        {$issue_label_issue_pos_sql} - {$issue_label_year_pos_sql} - CHAR_LENGTH({$issue_label_year_char_sql})
    )
";
$issue_label_month_order_sql = "
    CASE
        WHEN {$issue_label_year_pos_sql} > 0
             AND {$issue_label_month_pos_sql} > {$issue_label_year_pos_sql}
             AND {$issue_label_month_value_sql} REGEXP '^[0-9]{1,2}$'
        THEN CAST({$issue_label_month_value_sql} AS UNSIGNED)
        ELSE NULL
    END
";
$issue_label_issue_order_sql = "
    CASE
        WHEN {$issue_label_year_pos_sql} > 0
             AND {$issue_label_issue_pos_sql} > {$issue_label_year_pos_sql}
             AND {$issue_label_issue_value_sql} REGEXP '^[0-9]+'
        THEN CAST({$issue_label_issue_value_sql} AS UNSIGNED)
        ELSE NULL
    END
";
$issue_label_year_order_sql = "
    CASE
        WHEN {$issue_label_year_pos_sql} > 0
             AND {$issue_label_year_value_sql} REGEXP '^[0-9]{4}$'
        THEN CAST({$issue_label_year_value_sql} AS UNSIGNED)
        ELSE NULL
    END
";

$sql = "
    SELECT
        ic.id AS content_id,
        ic.issue_id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_no,
        ic.episode_format_id AS content_episode_format_id,
        ic.episode_title,
        ic.publication_status,
        ic.original_content_id,
        ic.note,

        cef.name AS content_episode_format_name,
        cef.prefix AS content_episode_format_prefix,
        cef.suffix AS content_episode_format_suffix,
        cef.number_style AS content_episode_number_style,

        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        mi.publication_date,
        mi.release_date,

        m.id AS magazine_id,
        p.id AS publisher_id,
        m.name AS magazine_name,
        p.name AS publisher_name,

        oic.id AS original_content_id_check,
        omi.id AS original_issue_id,
        omi.issue_label AS original_issue_label,
        om.name AS original_magazine_name,
        op.name AS original_publisher_name,

        GROUP_CONCAT(
            DISTINCT
            CASE
                WHEN cc.role IS NOT NULL
                     AND cc.role <> ''
                     AND cc.role <> 'author'
                THEN CONCAT(cc.role, '：', c.name)
                ELSE c.name
            END
            ORDER BY cc.display_order
            SEPARATOR ' / '
        ) AS creators,
        GROUP_CONCAT(
            DISTINCT
            CONCAT(
                COALESCE(c.id, ''),
                '||FIELD||',
                CASE
                    WHEN cc.role IS NOT NULL
                         AND cc.role <> ''
                         AND cc.role <> 'author'
                    THEN CONCAT(cc.role, '：', c.name)
                    ELSE c.name
                END
            )
            ORDER BY cc.display_order
            SEPARATOR '||CREATOR||'
        ) AS creators_data
    FROM issue_contents ic
    JOIN works w ON ic.work_id = w.id
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    LEFT JOIN episode_formats cef ON ic.episode_format_id = cef.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id

    LEFT JOIN issue_contents oic ON ic.original_content_id = oic.id
    LEFT JOIN magazine_issues omi ON oic.issue_id = omi.id
    LEFT JOIN magazines om ON omi.magazine_id = om.id
    LEFT JOIN publishers op ON om.publisher_id = op.id

    WHERE ic.work_id = :work_id
      {$keyword_where_sql}
    GROUP BY ic.id
    ORDER BY
        COALESCE(
            mi.issue_year,
            {$issue_label_year_order_sql},
            mi.publication_year,
            YEAR(NULLIF(mi.release_date, '0000-00-00')),
            YEAR(NULLIF(mi.publication_date, '0000-00-00')),
            9999
        ) ASC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+' THEN 0
            WHEN {$issue_label_issue_order_sql} IS NOT NULL THEN 0
            WHEN mi.publication_month BETWEEN 1 AND 12 THEN 0
            WHEN {$issue_label_month_order_sql} BETWEEN 1 AND 12 THEN 0
            ELSE 1
        END ASC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+' THEN CAST(mi.issue_no AS UNSIGNED)
            WHEN {$issue_label_issue_order_sql} IS NOT NULL THEN {$issue_label_issue_order_sql}
            WHEN mi.publication_month BETWEEN 1 AND 12 THEN mi.publication_month
            WHEN {$issue_label_month_order_sql} BETWEEN 1 AND 12 THEN {$issue_label_month_order_sql}
            ELSE 999999
        END ASC,
        mi.issue_no ASC,
        CASE
            WHEN mi.release_date IS NULL OR mi.release_date = '' OR mi.release_date = '0000-00-00' THEN 1
            ELSE 0
        END ASC,
        mi.release_date ASC,
        CASE
            WHEN mi.publication_date IS NULL OR mi.publication_date = '' OR mi.publication_date = '0000-00-00' THEN 1
            ELSE 0
        END ASC,
        mi.publication_date ASC,
        ic.sort_order ASC,
        ic.page_start ASC,
        ic.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([
    ':work_id' => $work_id
], $keyword_params));
$contents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        id,
        media_type,
        title,
        start_date,
        end_date,
        company,
        broadcaster,
        note,
        source_url
    FROM work_mediamix
    WHERE work_id = :work_id
      AND status = 'active'
    ORDER BY
        CASE WHEN start_date IS NULL THEN 1 ELSE 0 END ASC,
        start_date ASC,
        id ASC
");
$stmt->execute([
    ':work_id' => $work_id
]);
$mediamix_rows = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT ic.issue_id) AS issue_count,
        COUNT(DISTINCT mi.magazine_id) AS magazine_count,
        COUNT(DISTINCT cc.creator_id) AS creator_count
    FROM issue_contents ic
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    WHERE ic.work_id = :work_id
");
$stmt->execute([
    ':work_id' => $work_id
]);
$work_summary = $stmt->fetch() ?: [];
$work_issue_count = (int)($work_summary['issue_count'] ?? 0);
$work_magazine_count = (int)($work_summary['magazine_count'] ?? 0);
$work_creator_count = (int)($work_summary['creator_count'] ?? 0);

// 年一覧
$years = [];
foreach ($contents as $row) {
    if (!empty($row['issue_year'])) {
        $years[(string)$row['issue_year']] = true;
    }
}
$years = array_keys($years);
sort($years, SORT_NUMERIC);

// 話数表示作成
function format_episode_no($row, $work) {
    $episode_no = $row['episode_no'] ?? '';

    if ($episode_no === '' || $episode_no === null) {
        return '';
    }

    // 目次側フォーマットを優先
    if (!empty($row['content_episode_format_id'])) {
        $episode_value = format_episode_number_value(
            $episode_no,
            $row['content_episode_number_style'] ?? ''
        );

        return
            (string)($row['content_episode_format_prefix'] ?? '') .
            $episode_value .
            (string)($row['content_episode_format_suffix'] ?? '');
    }

    // 作品側フォーマット
    if (!empty($work['episode_format_id'])) {
        $episode_value = format_episode_number_value(
            $episode_no,
            $work['episode_number_style'] ?? ''
        );

        return
            (string)($work['episode_format_prefix'] ?? '') .
            $episode_value .
            (string)($work['episode_format_suffix'] ?? '');
    }

    return (string)$episode_no;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <link rel="canonical" href="https://indexindex.net/work.php?work_id=<?= h($work_id) ?>">
    <meta charset="UTF-8">
    <?php if (!empty($should_noindex)): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <title><?= h($work['title']) ?> - 作品詳細 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1148px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-sizing: border-box;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 20px;
            line-height: 1.3;
        }

        .work-sub {
            color: #666;
            font-size: 15px;
            margin-bottom: 10px;
        }

        .meta {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.7;
        }

        .work-title-row {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px 24px;
            margin-bottom: 20px;
        }

        .work-title-row h1,
        .work-title-row .meta {
            margin-bottom: 0;
        }

        .work-title-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0 1em;
        }

        .work-creators {
            font-weight: bold;
        }

        .nav {
            margin-bottom: 20px;
        }

        .nav a {
            margin-right: 12px;
            color: #0066cc;
            text-decoration: none;
            font-weight: bold;
        }

        .nav a:hover {
            text-decoration: underline;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
        }

        .page-description {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 14px 16px;
            margin: 16px 0 20px;
            color: #444;
            line-height: 1.8;
            font-size: 14px;
        }

        .page-description strong {
            color: #222;
        }

        .filter-box {
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 12px;
        }

        .filter-box select,
        .filter-box input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .graph-links {
            margin: 16px 0;
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            border-radius: 4px;
            line-height: 1.8;
        }

        .graph-links a {
            color: #0066cc;
            font-weight: bold;
            margin-right: 12px;
            text-decoration: none;
        }

        .graph-links a:hover {
            text-decoration: underline;
        }

        .mediamix-section {
            margin: 20px 0;
            padding: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow: hidden;
        }

        .mediamix-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 14px 18px;
            border: 0;
            background: #222;
            color: #fff;
            cursor: pointer;
            text-align: left;
        }

        .mediamix-toggle::after {
            content: "+";
            flex: 0 0 auto;
            margin-left: 8px;
            font-size: 20px;
            line-height: 1;
        }

        .mediamix-toggle[aria-expanded="true"]::after {
            content: "-";
        }

        .mediamix-toggle-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .mediamix-content {
            padding: 18px 22px 20px;
        }

        .mediamix-list {
            display: grid;
            gap: 10px;
        }

        .mediamix-item {
            padding: 10px 12px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            line-height: 1.7;
        }

        .mediamix-type {
            display: inline-block;
            margin-right: 8px;
            padding: 2px 7px;
            border-radius: 4px;
            background: #333;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
        }

        .mediamix-title {
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
        }

        th {
            background: #eee;
            text-align: left;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .creator-link {
            display: inline-block;
            margin: 0 2px 2px 0;
        }

        .page {
            width: 100px;
            text-align: center;
        }

        .order {
            width: 70px;
            text-align: center;
        }

        .episode {
            width: 120px;
            white-space: nowrap;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .note-row .note-cell {
            background: #fafafa;
        }

        .note-row .note-cell::before {
            content: none;
        }

        .empty {
            color: #666;
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            color: #888;
            font-size: 13px;
        }

        .footer a {
            color: #666;
            text-decoration: none;
            margin: 0 6px;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .filter-box {
                display: block;
            }

            .filter-box select {
                margin-bottom: 8px;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            thead {
                display: none;
            }

            tbody.work-item {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 8px;
            }

            tr {
                border: none;
                margin: 0;
                padding: 0;
            }

            td {
                border: none;
                padding: 4px 0;
            }

            td::before {
                font-weight: bold;
                display: inline-block;
                width: 120px;
                color: #555;
            }

            td.issue::before { content: "雑誌・号"; }
            td.order::before { content: "掲載順"; }
            td.page::before { content: "ページ"; }
            td.episode::before { content: "話数"; }
            td.episode-title::before { content: "話タイトル"; }
            td.creators::before { content: "人物"; }
            td.note-cell::before { content: "備考"; }

            .note-row .note-cell::before {
                content: "備考";
            }
        }
    </style>

    <link rel="stylesheet" href="assets/css/common.css">
</head>
<body id="top">

<?php
$current_page = 'work';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <div class="work-title-row">
        <h1><?= h($work['title']) ?></h1>
        <div class="meta work-title-meta">
            <span>区分：<?= h($work['publication_type'] ?? '') ?></span>
            <span>
                話数形式：
                <?php if (!empty($work['episode_format_name'])): ?>
                    <?= h($work['episode_format_name']) ?>
                    <?php if (!empty($work['episode_number_style'])): ?>
                        / 数字形式：<?= h($work['episode_number_style']) ?>
                    <?php endif; ?>
                <?php else: ?>
                    未設定
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php if ($work_creators): ?>
        <div class="meta work-creators">
            人物：<?php foreach ($work_creators as $index => $work_creator): ?><?= $index > 0 ? '／' : '' ?><a href="creator.php?creator_id=<?= h((int)$work_creator['creator_id']) ?>"><?= h($work_creator['creator_name']) ?></a><?php if ((string)$work_creator['role'] !== '' && $work_creator['role'] !== 'author'): ?>（<?= h($work_creator['role']) ?>）<?php endif; ?><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($work['title_sub'])): ?>
        <div class="work-sub"><?= h($work['title_sub']) ?></div>
    <?php endif; ?>

    <div class="count">
        掲載件数：<?= h(count($contents)) ?>件
    </div>

    <div class="page-description">
        <?php if ($work_total_content_count > 0): ?>
            このページでは、作品<strong>『<?= h($work['title']) ?>』</strong>の掲載情報を掲載しています。登録済みの<?= h($work_total_content_count) ?>件の掲載データから、掲載された雑誌号<?= h($work_issue_count) ?>件、掲載雑誌<?= h($work_magazine_count) ?>誌、作者・関係者<?= h($work_creator_count) ?>人、掲載順、開始ページなどを確認できます。
        <?php else: ?>
            この作品の掲載情報はまだ登録されていません。データは順次追加・整理しています。
        <?php endif; ?>
    </div>

    <?php if (!empty($mediamix_rows)): ?>
        <section class="mediamix-section">
            <button type="button" class="mediamix-toggle" aria-expanded="false">
                <span class="mediamix-toggle-title">メディアミックス</span>
            </button>
            <div class="mediamix-content" hidden>
                <div class="mediamix-list">
                    <?php foreach ($mediamix_rows as $row): ?>
                        <div class="mediamix-item">
                            <span class="mediamix-type"><?= h(mediamix_type_label($row['media_type'])) ?></span>
                            <span class="mediamix-title"><?= h($row['title']) ?></span>
                            <div class="small">
                                <?php if (!empty($row['start_date'])): ?>
                                    <?= h($row['start_date']) ?>
                                    <?php if (!empty($row['end_date'])): ?>
                                        - <?= h($row['end_date']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($row['broadcaster'])): ?>
                                    <br><?= h($row['broadcaster']) ?>
                                <?php endif; ?>
                                <?php if (!empty($row['company'])): ?>
                                    <br><?= h($row['company']) ?>
                                <?php endif; ?>
                                <?php if (!empty($row['note'])): ?>
                                    <br><?= h($row['note']) ?>
                                <?php endif; ?>
                                <?php if (!empty($row['source_url'])): ?>
                                    <br><a href="<?= h($row['source_url']) ?>" target="_blank" rel="noopener">source</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($contents)): ?>

        <div class="filter-box">
            <select id="yearFilter">
                <option value="">全ての年</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?= h($year) ?>"><?= h($year) ?>年</option>
                <?php endforeach; ?>
            </select>

            <input type="text" id="textFilter" placeholder="雑誌名・号数・話タイトル・人物・備考で絞り込み">
        </div>

        <?php if (($work['publication_type'] ?? '') === '連載'): ?>
        <div class="graph-links">
            連載順グラフ：
            <?php foreach ($years as $year): ?>
                <?php
                    $firstRowForYear = null;
                    foreach ($contents as $r) {
                        if ((string)$r['issue_year'] === (string)$year) {
                            $firstRowForYear = $r;
                            break;
                        }
                    }
                ?>
                <?php if ($firstRowForYear): ?>
                    <a href="work_graph.php?publisher_id=<?= h($firstRowForYear['publisher_id']) ?>&magazine_id=<?= h($firstRowForYear['magazine_id']) ?>&year=<?= h($year) ?>&work_id=<?= h($work_id) ?>">
                        <?= h($year) ?>年
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <table id="contentsTable">
            <thead>
                <tr>
                    <th>雑誌・号</th>
                    <th class="order">掲載順</th>
                    <th class="page">ページ</th>
                    <th class="episode">話数</th>
                    <th></th>
                    <th>人物</th>
                </tr>
            </thead>
            <?php foreach ($contents as $row): ?>
                <?php
                    $episode_display = format_episode_no($row, $work);
                    $note_display = build_note_text($row);
                ?>
            <tbody class="work-item" data-year="<?= h($row['issue_year']) ?>">
                <tr>
                    <td class="issue" rowspan="2">
                        <a href="issue.php?id=<?= h($row['issue_id']) ?>">
                            <?= h($row['magazine_name']) ?><br>
                            <?= h($row['issue_label']) ?>
                        </a>
                        <div class="small">
                            <?= h($row['publisher_name']) ?><br>
                            <?php if (!empty($row['issue_no'])): ?>
                                <?= h($row['issue_no']) ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="order"><?= h($row['sort_order']) ?></td>

                    <td class="page">
                        <?php if ($row['page_start'] && $row['page_end']): ?>
                            <?= h($row['page_start']) ?>〜<?= h($row['page_end']) ?>
                        <?php elseif ($row['page_start']): ?>
                            <?= h($row['page_start']) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td class="episode"><?= h($episode_display) ?></td>

                    <td class="episode-title"><?= h($row['episode_title'] ?? '') ?></td>

                    <td class="creators"><?= render_creator_links($row['creators_data'] ?? '') ?></td>
                </tr>

                <tr class="note-row">
                    <td class="note-cell" colspan="5">
                        <?php if ($note_display !== ''): ?>
                            <span class="note"><?= h($note_display) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
            <?php endforeach; ?>
        </table>

    <?php else: ?>

        <p class="empty">この作品の掲載情報はまだ登録されていません。データは順次追加・整理しています。</p>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
const yearFilter = document.getElementById('yearFilter');
const textFilter = document.getElementById('textFilter');
const table = document.getElementById('contentsTable');

function applyFilters() {
    if (!table) {
        return;
    }

    const year = yearFilter ? yearFilter.value : '';
    const keyword = textFilter ? textFilter.value.trim().toLowerCase() : '';
    const items = table.querySelectorAll('tbody.work-item');

    items.forEach(item => {
        const itemYear = item.dataset.year || '';
        const text = item.textContent.toLowerCase();

        const matchYear = !year || itemYear === year;
        const matchText = !keyword || text.includes(keyword);

        item.style.display = (matchYear && matchText) ? '' : 'none';
    });
}

if (yearFilter) {
    yearFilter.addEventListener('change', applyFilters);
}

if (textFilter) {
    textFilter.addEventListener('input', applyFilters);
}

document.querySelectorAll('.mediamix-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const content = button.nextElementSibling;
        const isOpen = button.getAttribute('aria-expanded') === 'true';

        button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

        if (content) {
            content.hidden = isOpen;
        }
    });
});
</script>

</body>
</html>
