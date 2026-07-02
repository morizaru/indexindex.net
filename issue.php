<?php
// issue.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('雑誌ページ（個別）');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function issue_status_badge(?string $status): ?array {
    if ($status === 'in_progress') {
        return ['label' => '登録途中', 'class' => 'issue-status-in-progress'];
    }

    if ($status === 'verified') {
        return ['label' => '現物確認済', 'class' => 'issue-status-verified'];
    }

    return null;
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];

    if (!isset($cache[$table])) {
        $allowed_tables = ['magazine_issues'];
        if (!in_array($table, $allowed_tables, true)) {
            return false;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cache[$table] = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }

    return isset($cache[$table][$column]);
}

function display_date_or_dash($value): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }

    return $value;
}

function format_issue_volume_text(array $issue): string {
    $issue_vol = trim((string)($issue['issue_vol'] ?? ''));
    $issue_no = trim((string)($issue['issue_no'] ?? ''));
    $issue_full_vol = trim((string)($issue['issue_full_vol'] ?? ''));

    if (
        !preg_match('/^[0-9]+$/', $issue_vol) ||
        !preg_match('/^[0-9]+$/', $issue_no) ||
        !preg_match('/^[0-9]+$/', $issue_full_vol)
    ) {
        return '-';
    }

    return $issue_vol . '（' . $issue_no . '）' . $issue_full_vol;
}

function format_issue_description_date(array $issue): string {
    $release_date = trim((string)($issue['release_date'] ?? ''));
    if ($release_date !== '' && $release_date !== '0000-00-00') {
        return '発売日：' . $release_date;
    }

    $publication_date = trim((string)($issue['publication_date'] ?? ''));
    if ($publication_date !== '' && $publication_date !== '0000-00-00') {
        return '発行日：' . $publication_date;
    }

    $issue_year = trim((string)($issue['issue_year'] ?? ''));
    if ($issue_year !== '') {
        return $issue_year . '年の雑誌号';
    }

    return '';
}

// タイトル横バッジ作成
// issue_contents の専用カラムから判定する
function get_content_badges($row) {
    $badges = [];

    $publication_type = trim((string)($row['publication_type'] ?? ''));

    if (in_array($publication_type, ['one_shot', 'oneshot', 'read_one', '読切', '読み切り', '読切り'], true)) {
        $badges[] = ['label' => '読切', 'class' => 'badge-one-shot'];
    }

    if (in_array($publication_type, ['guest', 'guest_appearance', 'guest_posting', '出張掲載'], true)) {
        $badges[] = ['label' => '出張掲載', 'class' => 'badge-guest'];
    }

    if (!empty($row['is_new_series'])) {
        $badges[] = ['label' => '新連載', 'class' => 'badge-new'];
    }

    if (!empty($row['is_final_episode'])) {
        $badges[] = ['label' => '最終回', 'class' => 'badge-final'];
    }

    if (!empty($row['is_debut_work'])) {
        $badges[] = ['label' => 'デビュー作', 'class' => 'badge-debut'];
    }

    return $badges;
}

// 話数表示作成
function number_to_kanji($number) {
    $number = (int)$number;

    if ($number <= 0) {
        return (string)$number;
    }

    $digits = [0 => '', 1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九'];
    $units = [100 => '百', 10 => '十'];
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

function format_episode_no($row) {
    $episode_no = trim((string)($row['episode_no'] ?? ''));

    if ($episode_no === '') {
        return '';
    }

    // 「最終話」「番外編」「前編」「後編」など、数字以外はそのまま表示する
    if (!preg_match('/^[0-9]+$/', $episode_no)) {
        return $episode_no;
    }

    // 数字だけの場合は、目次側フォーマットを優先
    if (!empty($row['content_episode_format_id'])) {
        $episode_value = format_episode_number_value($episode_no, $row['content_episode_number_style'] ?? '');

        return
            (string)($row['content_episode_format_prefix'] ?? '') .
            $episode_value .
            (string)($row['content_episode_format_suffix'] ?? '');
    }

    // 数字だけの場合は、作品側フォーマットを適用
    if (!empty($row['work_episode_format_id'])) {
        $episode_value = format_episode_number_value($episode_no, $row['work_episode_number_style'] ?? '');

        return
            (string)($row['work_episode_format_prefix'] ?? '') .
            $episode_value .
            (string)($row['work_episode_format_suffix'] ?? '');
    }

    return $episode_no;
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

$issue_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($issue_id <= 0) {
    exit('号IDが指定されていません。');
}

$issue_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_vol')
    ? 'mi.issue_vol'
    : 'NULL AS issue_vol';
$issue_full_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_full_vol')
    ? 'mi.issue_full_vol'
    : 'NULL AS issue_full_vol';

// 雑誌・号情報を取得
$sql_issue = "
    SELECT
        mi.id,
        mi.magazine_id,
        mi.issue_label,
        mi.issue_label_special,
        mi.publication_date,
        mi.publication_year,
        mi.publication_month,
        mi.publication_day,
        mi.release_date,
        mi.issue_year,
        {$issue_vol_select},
        mi.issue_no,
        {$issue_full_vol_select},
        mi.status,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE mi.id = :issue_id
      AND mi.status IN ('approved', 'in_progress', 'verified')
";

$stmt = $pdo->prepare($sql_issue);
$stmt->execute([
    ':issue_id' => $issue_id
]);
$issue = $stmt->fetch();

if (!$issue) {
    exit('指定された号が見つかりません。');
}

$issue_order_expr = "
    COALESCE(
        NULLIF(mi.publication_date, '0000-00-00'),
        NULLIF(mi.release_date, '0000-00-00'),
        STR_TO_DATE(
            CONCAT(
                mi.publication_year,
                '-',
                LPAD(COALESCE(mi.publication_month, 1), 2, '0'),
                '-',
                LPAD(COALESCE(mi.publication_day, 1), 2, '0')
            ),
            '%Y-%m-%d'
        ),
        STR_TO_DATE(CONCAT(mi.issue_year, '-01-01'), '%Y-%m-%d'),
        STR_TO_DATE('9999-12-31', '%Y-%m-%d')
    )
";

$stmt = $pdo->prepare("
    SELECT
        mi.id,
        mi.issue_label,
        {$issue_order_expr} AS issue_order_key
    FROM magazine_issues mi
    WHERE mi.magazine_id = :magazine_id
      AND mi.status IN ('approved', 'in_progress', 'verified')
    ORDER BY
        issue_order_key ASC,
        mi.id ASC
");
$stmt->execute([
    ':magazine_id' => (int)$issue['magazine_id']
]);
$same_magazine_issues = $stmt->fetchAll();
$older_issue = null;
$newer_issue = null;

foreach ($same_magazine_issues as $index => $row) {
    if ((int)$row['id'] === $issue_id) {
        $older_issue = $same_magazine_issues[$index - 1] ?? null;
        $newer_issue = $same_magazine_issues[$index + 1] ?? null;
        break;
    }
}

// 目次データを取得
$sql_contents = "
    SELECT
        ic.id,
        ic.sort_order,
        ic.page_start,
        ic.page_end,
        ic.title_main,
        ic.episode_title,
        ic.episode_no,
        ic.episode_format_id AS content_episode_format_id,
        ic.note,
        ic.publication_type,
        ic.is_new_series,
        ic.is_final_episode,

        w.id AS work_id,
        w.title_sub,
        w.episode_format_id AS work_episode_format_id,

        cef.name AS content_episode_format_name,
        cef.prefix AS content_episode_format_prefix,
        cef.suffix AS content_episode_format_suffix,
        cef.number_style AS content_episode_number_style,

        wef.name AS work_episode_format_name,
        wef.prefix AS work_episode_format_prefix,
        wef.suffix AS work_episode_format_suffix,
        wef.number_style AS work_episode_number_style,

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
        ) AS creators_data,
        MAX(
            CASE
                WHEN c.debut_work_id IS NOT NULL
                     AND c.debut_work_id = w.id
                THEN 1
                ELSE 0
            END
        ) AS is_debut_work
    FROM issue_contents ic
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN episode_formats cef ON ic.episode_format_id = cef.id
    LEFT JOIN episode_formats wef ON w.episode_format_id = wef.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
    LEFT JOIN creators c ON cc.creator_id = c.id
    WHERE ic.issue_id = :issue_id
    GROUP BY ic.id
    ORDER BY ic.sort_order ASC, ic.id ASC
";

$stmt = $pdo->prepare($sql_contents);
$stmt->execute([
    ':issue_id' => $issue_id
]);
$contents = $stmt->fetchAll();
$content_count = count($contents);
$should_noindex = ($content_count === 0);

$issue_summary = [
    'content_count' => $content_count,
    'creator_ids' => [],
    'badges' => [
        '読切' => 0,
        '出張掲載' => 0,
        '新連載' => 0,
        '最終回' => 0,
    ],
];

foreach ($contents as $content_row) {
    foreach (get_content_badges($content_row) as $badge) {
        $label = $badge['label'] ?? '';
        if (isset($issue_summary['badges'][$label])) {
            $issue_summary['badges'][$label]++;
        }
    }

    if (trim((string)($content_row['creators_data'] ?? '')) !== '') {
        foreach (explode('||CREATOR||', (string)$content_row['creators_data']) as $creator_item) {
            $creator_parts = explode('||FIELD||', $creator_item, 2);
            $creator_id = isset($creator_parts[0]) ? (int)$creator_parts[0] : 0;
            if ($creator_id > 0) {
                $issue_summary['creator_ids'][$creator_id] = true;
            }
        }
    }
}

$issue_summary['creator_count'] = count($issue_summary['creator_ids']);

$issue_label_special = trim((string)($issue['issue_label_special'] ?? ''));
$issue_display_name = trim((string)($issue['magazine_name'] ?? '') . ' ' . (string)($issue['issue_label'] ?? ''));
if ($issue_label_special !== '') {
    $issue_display_name .= '（' . $issue_label_special . '）';
}
$issue_status_badge = issue_status_badge($issue['status'] ?? null);
$issue_description_name = $issue_display_name;
$issue_description_date = format_issue_description_date($issue);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($should_noindex)): ?>
        <meta name="robots" content="noindex,follow">
    <?php endif; ?>
    <title><?= h($issue_display_name) ?> - INDEXINDEX</title>

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 24px;
            background: #f5f5f5;
            color: #222;
            line-height: 1.6;
        }

        .container {
            max-width: 1148px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 20px;
            line-height: 1.3;
        }

        .issue-title-nav {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            line-height: 1.35;
        }

        .issue-title-text {
            min-width: 0;
        }

        .issue-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.4;
            white-space: nowrap;
        }

        .issue-status-in-progress {
            background: #fff7e6;
            color: #8a5a00;
            border: 1px solid #f0c36a;
        }

        .issue-status-verified {
            background: #eaf8ef;
            color: #16743a;
            border: 1px solid #a9dfb8;
        }

        .issue-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: 1px solid #ddd;
            border-radius: 999px;
            background: #f7f7f7;
            color: #0066cc;
            font-size: 16px;
            font-weight: 800;
            line-height: 1;
            text-decoration: none;
            flex: 0 0 auto;
        }

        .issue-nav-link:hover {
            background: #f0f6ff;
            text-decoration: none;
        }

        .meta {
            margin-bottom: 24px;
            color: #555;
            line-height: 1.8;
        }

        .description-box {
            margin: 0 0 20px;
            padding: 14px 16px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.8;
        }

        .issue-summary {
            margin: 0 0 20px;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .issue-summary-title {
            margin: 0 0 10px;
            font-size: 18px;
            font-weight: 800;
        }

        .issue-summary-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .issue-summary-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: #f7f7f7;
            border: 1px solid #e5e5e5;
            color: #444;
            font-size: 14px;
            font-weight: 700;
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

        .order {
            width: 60px;
            text-align: center;
        }

        .page {
            width: 100px;
            text-align: center;
        }

        .episode {
            width: 90px;
            white-space: nowrap;
        }

        .title-main {
            font-weight: bold;
            font-size: 16px;
        }

        .content-badge {
            display: inline-flex;
            align-items: center;
            margin-left: 6px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
            vertical-align: 1px;
            white-space: nowrap;
        }

        .badge-one-shot {
            background: #eef6ff;
            color: #0059a8;
            border: 1px solid #b9dcff;
        }

        .badge-guest {
            background: #f4f0ff;
            color: #5a35a8;
            border: 1px solid #d6c8ff;
        }

        .badge-new {
            background: #fff3d8;
            color: #9a6200;
            border: 1px solid #ffd98a;
        }

        .badge-final {
            background: #ffecec;
            color: #b00020;
            border: 1px solid #ffb8c2;
        }

        .badge-debut {
            background: #eaf8ef;
            color: #16743a;
            border: 1px solid #a9dfb8;
        }

        .title-sub {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
        }

        .episode-title {
            color: #444;
            font-size: 14px;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        .empty {
            color: #666;
        }

        @media (max-width: 700px) {
            body {
                padding: 8px;
            }

            .container {
                padding: 12px;
                border-radius: 6px;
            }

            h1 {
                font-size: 24px;
            }

            .issue-title-nav {
                gap: 6px;
            }

            .issue-nav-link {
                width: 30px;
                height: 30px;
            }

            table {
                font-size: 14px;
            }

            th, td {
                padding: 7px 6px;
            }
        }
        @media screen and (max-width: 800px) {
            .toc-note-col {
                display: none !important;
            }
        }
    </style>

    <link rel="stylesheet" href="assets/css/common.css">
</head>
<body id="top">

<?php
$current_page = 'issue';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1 class="issue-title-nav">
        <?php if ($older_issue): ?>
            <a
                class="issue-nav-link"
                href="issue.php?id=<?= h($older_issue['id']) ?>"
                title="前の号へ / 古い号へ：<?= h($older_issue['issue_label'] ?? '') ?>"
            >▼</a>
        <?php endif; ?>

        <span class="issue-title-text"><?= h($issue_display_name) ?></span>

        <?php if ($issue_status_badge): ?>
            <span class="issue-status-badge <?= h($issue_status_badge['class']) ?>">
                <?= h($issue_status_badge['label']) ?>
            </span>
        <?php endif; ?>

        <?php if ($newer_issue): ?>
            <a
                class="issue-nav-link"
                href="issue.php?id=<?= h($newer_issue['id']) ?>"
                title="次の号へ / 新しい号へ：<?= h($newer_issue['issue_label'] ?? '') ?>"
            >▲</a>
        <?php endif; ?>
    </h1>

    <div class="meta">
        出版社：<?= h($issue['publisher_name']) ?><br>
        発行日：<?= h(display_date_or_dash($issue['publication_date'] ?? '')) ?><br>
        発売日：<?= h(display_date_or_dash($issue['release_date'] ?? '')) ?><br>
        巻（号）通巻：<?= h(format_issue_volume_text($issue)) ?>
    </div>

    <div class="description-box">
        このページでは、<?= h($issue_description_name) ?>の目次情報、掲載作品、作者、掲載順、開始ページを確認できます。<?= $issue_description_date !== '' ? h($issue_description_date) . '。' : '' ?>
    </div>

    <section class="issue-summary" aria-label="この雑誌号の収録内容概要">
        <h2 class="issue-summary-title">この雑誌号の収録内容</h2>
        <div class="issue-summary-grid">
            <div class="issue-summary-item">登録目次件数：<?= h($issue_summary['content_count']) ?>件</div>
            <div class="issue-summary-item">登録作者数：<?= h($issue_summary['creator_count']) ?>人</div>
            <?php foreach ($issue_summary['badges'] as $label => $count): ?>
                <?php if ($count > 0): ?>
                    <div class="issue-summary-item"><?= h($label) ?>：<?= h($count) ?>件</div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($contents)): ?>

        <p class="empty">この雑誌号にはまだ目次が登録されていません。データは順次追加・整理しています。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th class="order">順</th>
                    <th class="page">ページ</th>
                    <th>タイトル</th>
                    <th class="episode">話数</th>
                    <th></th>
                    <th>作者</th>
                    <th class="toc-note-col">備考</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
                <?php
                    $episode_display = format_episode_no($row);
                    $content_badges = get_content_badges($row);
                ?>
                <tr>
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

                    <td class="title">
                        <div class="title-main">
                            <?php if (!empty($row['work_id'])): ?>
                                <a href="work.php?work_id=<?= h($row['work_id']) ?>">
                                    <?= h($row['title_main']) ?>
                                </a>
                            <?php else: ?>
                                <?= h($row['title_main']) ?>
                            <?php endif; ?>

                            <?php foreach ($content_badges as $badge): ?>
                                <span class="content-badge <?= h($badge['class']) ?>"><?= h($badge['label']) ?></span>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($row['title_sub'])): ?>
                            <div class="title-sub"><?= h($row['title_sub']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="episode"><?= h($episode_display) ?></td>

                    <td class="episode-title">
                        <?= h($row['episode_title'] ?? '') ?>
                    </td>

                    <td class="creators"><?= render_creator_links($row['creators_data'] ?? '') ?></td>

                    <td class="note-cell toc-note-col">
                        <?php if (!empty($row['note'])): ?>
                            <span class="note"><?= h($row['note']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
</body>
</html>
