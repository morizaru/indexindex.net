<?php
// search.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('検索');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function selected_attr($value, $current) {
    return $value === $current ? ' checked' : '';
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

function split_search_keywords(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[\s,，、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = [];

    foreach ($parts as $part) {
        $token = trim((string)$part);
        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    return $tokens;
}

function build_multi_keyword_clause(array $tokens, array $field_names, string $param_prefix): string {
    if (empty($tokens)) {
        return '1 = 0';
    }

    $clauses = [];

    foreach ($tokens as $index => $token) {
        $token_terms = [];
        foreach ($field_names as $field_name) {
            $token_terms[] = $field_name . ' LIKE :' . $param_prefix . '_' . $index;
        }
        $clauses[] = '(' . implode(' OR ', $token_terms) . ')';
    }

    return implode(' OR ', $clauses);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$target = isset($_GET['target']) ? trim((string)$_GET['target']) : 'all';

$allowed_targets = ['all', 'magazine', 'work', 'creator'];
if (!in_array($target, $allowed_targets, true)) {
    $target = 'all';
}

$results = [];
$all_results = [
    'magazine' => [],
    'work' => [],
    'creator' => [],
];
$limit = 500;

$target_labels = [
    'all' => '全て',
    'magazine' => '雑誌名',
    'work' => '作品名',
    'creator' => '作者名',
];

$keyword_tokens = split_search_keywords($q);

if ($q !== '' && !empty($keyword_tokens)) {
    if ($target === 'all' || $target === 'magazine') {
        $magazine_where = build_multi_keyword_clause($keyword_tokens, ['m.name', 'm.name_search', 'mi.issue_label', 'mi.issue_label_special'], 'mag_keyword');
        $magazine_params = [];
        foreach ($keyword_tokens as $index => $keyword_token) {
            $magazine_params[':mag_keyword_' . $index] = '%' . $keyword_token . '%';
        }

        $sql = "
            SELECT
                m.id AS magazine_id,
                m.name AS magazine_name,
                p.name AS publisher_name,
                COUNT(DISTINCT mi.id) AS issue_count,
                MIN(mi.issue_year) AS first_year,
                MAX(mi.issue_year) AS last_year
            FROM magazines m
            LEFT JOIN publishers p
                ON p.id = m.publisher_id
            LEFT JOIN magazine_issues mi
                ON mi.magazine_id = m.id
               AND mi.status = 'approved'
            WHERE {$magazine_where}
            GROUP BY
                m.id,
                m.name,
                p.name
            ORDER BY
                MIN(mi.issue_year) IS NULL ASC,
                MIN(mi.issue_year) ASC,
                MAX(mi.issue_year) ASC,
                m.name ASC,
                p.name ASC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($magazine_params);
        if ($target === 'all') {
            $all_results['magazine'] = $stmt->fetchAll();
        } else {
            $results = $stmt->fetchAll();
        }
    }

    if ($target === 'all' || $target === 'work') {
        $work_where = build_multi_keyword_clause($keyword_tokens, ['w.title'], 'work_keyword');
        $work_params = [];
        foreach ($keyword_tokens as $index => $keyword_token) {
            $work_params[':work_keyword_' . $index] = '%' . $keyword_token . '%';
        }

        $sql = "
            SELECT
                w.id AS work_id,
                w.title AS work_title,
                w.publication_type,
                COUNT(DISTINCT ic.id) AS content_count,
                MIN(mi.issue_year) AS first_year,
                MAX(mi.issue_year) AS last_year
            FROM works w
            LEFT JOIN issue_contents ic
                ON ic.work_id = w.id
            LEFT JOIN magazine_issues mi
                ON mi.id = ic.issue_id
               AND mi.status = 'approved'
            WHERE {$work_where}
            GROUP BY
                w.id,
                w.title,
                w.publication_type
            ORDER BY
                w.title ASC,
                w.id ASC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($work_params);
        if ($target === 'all') {
            $all_results['work'] = $stmt->fetchAll();
        } else {
            $results = $stmt->fetchAll();
        }
    }

    if ($target === 'all' || $target === 'creator') {
        $creator_where_clauses = [];
        $creator_params = [];

        foreach ($keyword_tokens as $index => $keyword_token) {
            $keyword_kana_likes = kana_like_values($keyword_token);
            $creator_where_clauses[] = "(
                c.name LIKE :creator_keyword_{$index}
                OR c.name_search LIKE :creator_keyword_{$index}
                OR c.name_kana LIKE :creator_kana_base_{$index}
                OR c.name_kana LIKE :creator_kana_hira_{$index}
                OR c.name_kana LIKE :creator_kana_kata_{$index}
            )";

            $creator_params[':creator_keyword_' . $index] = '%' . $keyword_token . '%';
            $creator_params[':creator_kana_base_' . $index] = $keyword_kana_likes['base'];
            $creator_params[':creator_kana_hira_' . $index] = $keyword_kana_likes['hira'];
            $creator_params[':creator_kana_kata_' . $index] = $keyword_kana_likes['kata'];
        }

        $sql = "
            SELECT
                c.id AS creator_id,
                c.name AS creator_name,
                COUNT(DISTINCT cc.content_id) AS content_count,
                COUNT(DISTINCT ic.work_id) AS work_count,
                MIN(mi.issue_year) AS first_year,
                MAX(mi.issue_year) AS last_year
            FROM creators c
            LEFT JOIN content_creators cc
                ON cc.creator_id = c.id
            LEFT JOIN issue_contents ic
                ON ic.id = cc.content_id
            LEFT JOIN magazine_issues mi
                ON mi.id = ic.issue_id
               AND mi.status = 'approved'
            WHERE " . implode(' OR ', $creator_where_clauses) . "
            GROUP BY
                c.id,
                c.name
            ORDER BY
                c.name ASC,
                c.id ASC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($creator_params);
        if ($target === 'all') {
            $all_results['creator'] = $stmt->fetchAll();
        } else {
            $results = $stmt->fetchAll();
        }
    }
}

$total_results = ($target === 'all')
    ? count($all_results['magazine']) + count($all_results['work']) + count($all_results['creator'])
    : count($results);

$all_result_tabs = [
    'magazine' => '雑誌名',
    'work' => '作品名',
    'creator' => '作者名',
];
$active_all_tab = 'magazine';
foreach ($all_result_tabs as $tab_key => $tab_label) {
    if (!empty($all_results[$tab_key])) {
        $active_all_tab = $tab_key;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>詳細検索 - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
            font-size: 28px;
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

        .search-form {
            margin-bottom: 20px;
            padding: 16px;
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .search-box {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        input[type="text"] {
            flex: 1;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            padding: 10px 18px;
            font-size: 16px;
            border: 1px solid #333;
            background: #333;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #111;
        }

        .radio-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            font-size: 15px;
        }

        .radio-row label {
            cursor: pointer;
            white-space: nowrap;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
        }

        .notice {
            color: #666;
            margin: 12px 0;
        }

        .page-description,
        .description-box {
            margin: 0 0 18px;
            padding: 14px 16px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.8;
        }

        .page-description strong {
            color: #222;
        }

        .description-box p {
            margin: 0 0 8px;
        }

        .description-box p:last-child {
            margin-bottom: 0;
        }

        .example-box {
            margin: 14px 0 0;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.7;
        }

        .help-box {
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 14px 16px;
            margin: 14px 0;
            color: #444;
            line-height: 1.8;
        }

        .example-box h2 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .example-list {
            margin: 0;
            padding-left: 1.4em;
        }

        .search-guide-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .search-guide-card {
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            color: #444;
            line-height: 1.7;
        }

        .search-guide-title {
            margin: 0 0 6px;
            font-weight: 800;
            color: #222;
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
            text-decoration: none;
            font-weight: bold;
        }

        a:hover {
            text-decoration: underline;
        }

        .main-name {
            font-weight: bold;
        }

        .sub-name {
            color: #666;
            font-size: 14px;
            margin-top: 4px;
        }

        .small {
            color: #666;
            font-size: 13px;
        }

        .result-section {
            margin-top: 22px;
        }

        .result-section h2 {
            margin: 0 0 10px;
            font-size: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .result-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin: 0 0 14px;
            border-bottom: 1px solid #ddd;
        }

        .result-tab {
            padding: 9px 14px;
            border: 1px solid #ddd;
            border-bottom: none;
            background: #f3f3f3;
            color: #444;
            border-radius: 7px 7px 0 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .result-tab:hover {
            background: #fff;
            color: #0066cc;
        }

        .result-tab.is-active {
            position: relative;
            top: 1px;
            background: #fff;
            border-color: #bbb;
            color: #222;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.is-active {
            display: block;
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

        @media (max-width: 700px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .search-box {
                display: block;
            }

            input[type="text"] {
                width: 100%;
                box-sizing: border-box;
                margin-bottom: 8px;
            }

            button {
                width: 100%;
            }

            .radio-row {
                gap: 10px;
            }

            table, thead, tbody, tr, th, td {
                display: block;
            }

            thead {
                display: none;
            }

            tr {
                border: 1px solid #ddd;
                margin-bottom: 12px;
                padding: 8px;
            }

            td {
                border: none;
                padding: 4px 0;
            }

            td::before {
                font-weight: bold;
                display: inline-block;
                width: 90px;
                color: #555;
            }

            td.name-cell::before { content: "名称"; }
            td.publisher-cell::before { content: "出版社"; }
            td.count-cell::before { content: "件数"; }
            td.year-cell::before { content: "収録年"; }
            td.type-cell::before { content: "区分"; }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <link rel="stylesheet" href="assets/css/common.css">
    
</head>
<body>

<?php
$current_page = 'search';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1>詳細検索</h1>

    <div class="page-description">
        <p><strong>INDEXINDEX検索について</strong></p>
        <p>INDEXINDEXに登録されている漫画雑誌の目次情報を、作品名、作者名、雑誌名から検索できます。検索結果から、雑誌号・作品・人物の詳細ページへ移動し、掲載順、開始ページ、掲載号などを確認できます。</p>
    </div>

    <form method="get" action="search.php" class="search-form">
        <div class="search-box">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="例：鳥山明 DRAGON / 作者名・雑誌名・作品名">
            <button type="submit">検索</button>
        </div>

        <div class="radio-row">
            <label><input type="radio" name="target" value="all"<?= selected_attr('all', $target) ?>> 全て</label>
            <label><input type="radio" name="target" value="magazine"<?= selected_attr('magazine', $target) ?>> 雑誌名</label>
            <label><input type="radio" name="target" value="work"<?= selected_attr('work', $target) ?>> 作品名</label>
            <label><input type="radio" name="target" value="creator"<?= selected_attr('creator', $target) ?>> 作者名</label>
        </div>
    </form>

    <?php if ($q === ''): ?>

        <div class="help-box">
            <strong>検索で調べられること</strong><br>
            古い漫画雑誌の掲載情報を調べるときに、作品名の一部、作者名、雑誌名から手がかりを探せます。作品・人物・雑誌号のページへ移動して、掲載順や開始ページ、掲載された号を確認できます。
        </div>
        <div class="search-guide-grid" aria-label="検索対象の説明">
            <div class="search-guide-card">
                <div class="search-guide-title">作品名検索</div>
                <p>作品タイトルから、掲載された雑誌号、掲載順、開始ページ、関係する作者情報へたどれます。</p>
            </div>
            <div class="search-guide-card">
                <div class="search-guide-title">作者名検索</div>
                <p>作者、原作者、作画担当などの人物名から、関係する掲載作品や雑誌号を確認できます。</p>
            </div>
            <div class="search-guide-card">
                <div class="search-guide-title">雑誌名検索</div>
                <p>雑誌名や号数から、登録済みの雑誌号一覧と各号の目次ページへ移動できます。</p>
            </div>
        </div>
        <div class="example-box">
            <h2>検索例</h2>
            <ul class="example-list">
                <li>作品名で探す：ドラゴンボール、ゴルゴ13、北斗の拳</li>
                <li>作者名で探す：手塚治虫、石ノ森章太郎、藤子不二雄</li>
                <li>雑誌名で探す：週刊少年ジャンプ、週刊少年サンデー、漫画アクション</li>
            </ul>
            <p>検索結果からは、雑誌号、掲載作品、作者ページ、掲載順の情報を順に確認できます。</p>
        </div>

    <?php else: ?>

        <div class="count">
            「<?= h($q) ?>」の<?= h($target_labels[$target]) ?>検索結果：<?= h($total_results) ?>件
            <?php if ($target === 'all' && (count($all_results['magazine']) >= $limit || count($all_results['work']) >= $limit || count($all_results['creator']) >= $limit)): ?>
                <span class="small">※各カテゴリ最大<?= h($limit) ?>件まで表示しています。</span>
            <?php elseif ($target !== 'all' && count($results) >= $limit): ?>
                <span class="small">※最大<?= h($limit) ?>件まで表示しています。</span>
            <?php endif; ?>
        </div>

        <?php if ($total_results === 0): ?>

            <div class="help-box">
                <strong>該当するデータは見つかりませんでした。</strong><br>
                作品名・作者名・雑誌名の表記を変えて、もう一度検索してください。
                <ul class="example-list">
                    <li>漢字・かな・カタカナなど表記ゆれを試す</li>
                    <li>作者名の一部だけで検索する</li>
                    <li>雑誌名を短くして検索する</li>
                    <li>検索対象を「全て」「作品名」「作者名」「雑誌名」で切り替える</li>
                </ul>
                データは順次追加・整理しています。
            </div>

        <?php elseif ($target === 'all'): ?>

            <p class="notice">検索結果から、作品・人物・雑誌号の詳細ページを確認できます。</p>

            <div class="result-tabs" role="tablist" aria-label="検索結果カテゴリ">
                <?php foreach ($all_result_tabs as $tab_key => $tab_label): ?>
                    <?php if (empty($all_results[$tab_key])) continue; ?>
                    <button
                        type="button"
                        class="result-tab<?= ($active_all_tab === $tab_key) ? ' is-active' : '' ?>"
                        data-tab-target="<?= h($tab_key) ?>"
                        role="tab"
                        aria-selected="<?= ($active_all_tab === $tab_key) ? 'true' : 'false' ?>"
                    >
                        <?= h($tab_label) ?>（<?= h(count($all_results[$tab_key])) ?>）
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($all_results['magazine'])): ?>
                <div class="result-section tab-panel<?= ($active_all_tab === 'magazine') ? ' is-active' : '' ?>" data-tab-panel="magazine" role="tabpanel">
                    <h2>雑誌名の検索結果</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>雑誌名</th>
                                <th>出版社</th>
                                <th>雑誌号数</th>
                                <th>収録年</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all_results['magazine'] as $row): ?>
                            <tr>
                                <td class="name-cell">
                                    <div class="main-name">
                                        <a href="issues.php?magazine_id=<?= h($row['magazine_id']) ?>"><?= h($row['magazine_name']) ?></a>
                                    </div>
                                </td>
                                <td class="publisher-cell"><?= h($row['publisher_name'] ?? '') ?></td>
                                <td class="count-cell"><?= h($row['issue_count']) ?>件</td>
                                <td class="year-cell">
                                    <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                        <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($all_results['work'])): ?>
                <div class="result-section tab-panel<?= ($active_all_tab === 'work') ? ' is-active' : '' ?>" data-tab-panel="work" role="tabpanel">
                    <h2>作品名の検索結果</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>作品名</th>
                                <th>区分</th>
                                <th>登録件数</th>
                                <th>収録年</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all_results['work'] as $row): ?>
                            <tr>
                                <td class="name-cell">
                                    <div class="main-name">
                                        <a href="work.php?work_id=<?= h($row['work_id']) ?>"><?= h($row['work_title']) ?></a>
                                    </div>
                                </td>
                                <td class="type-cell"><?= h($row['publication_type'] ?? '') ?></td>
                                <td class="count-cell"><?= h($row['content_count']) ?>件</td>
                                <td class="year-cell">
                                    <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                        <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($all_results['creator'])): ?>
                <div class="result-section tab-panel<?= ($active_all_tab === 'creator') ? ' is-active' : '' ?>" data-tab-panel="creator" role="tabpanel">
                    <h2>作者名の検索結果</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>作者名</th>
                                <th>作品数</th>
                                <th>目次件数</th>
                                <th>収録年</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all_results['creator'] as $row): ?>
                            <tr>
                                <td class="name-cell">
                                    <div class="main-name">
                                        <a href="creator.php?creator_id=<?= h($row['creator_id']) ?>"><?= h($row['creator_name']) ?></a>
                                    </div>
                                </td>
                                <td class="count-cell"><?= h($row['work_count']) ?>件</td>
                                <td class="count-cell"><?= h($row['content_count']) ?>件</td>
                                <td class="year-cell">
                                    <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                        <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($target === 'magazine'): ?>

            <table>
                <thead>
                    <tr>
                        <th>雑誌名</th>
                        <th>出版社</th>
                        <th>雑誌号数</th>
                        <th>収録年</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td class="name-cell">
                            <div class="main-name">
                                <a href="issues.php?magazine_id=<?= h($row['magazine_id']) ?>"><?= h($row['magazine_name']) ?></a>
                            </div>
                        </td>
                        <td class="publisher-cell"><?= h($row['publisher_name'] ?? '') ?></td>
                        <td class="count-cell"><?= h($row['issue_count']) ?>件</td>
                        <td class="year-cell">
                            <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($target === 'work'): ?>

            <table>
                <thead>
                    <tr>
                        <th>作品名</th>
                        <th>区分</th>
                        <th>登録件数</th>
                        <th>収録年</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td class="name-cell">
                            <div class="main-name">
                                <a href="work.php?work_id=<?= h($row['work_id']) ?>"><?= h($row['work_title']) ?></a>
                            </div>
                        </td>
                        <td class="type-cell"><?= h($row['publication_type'] ?? '') ?></td>
                        <td class="count-cell"><?= h($row['content_count']) ?>件</td>
                        <td class="year-cell">
                            <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($target === 'creator'): ?>

            <table>
                <thead>
                    <tr>
                        <th>作者名</th>
                        <th>作品数</th>
                        <th>目次件数</th>
                        <th>収録年</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td class="name-cell">
                            <div class="main-name">
                                <a href="creator.php?creator_id=<?= h($row['creator_id']) ?>"><?= h($row['creator_name']) ?></a>
                            </div>
                        </td>
                        <td class="count-cell"><?= h($row['work_count']) ?>件</td>
                        <td class="count-cell"><?= h($row['content_count']) ?>件</td>
                        <td class="year-cell">
                            <?php if (!empty($row['first_year']) && !empty($row['last_year'])): ?>
                                <?= h($row['first_year']) ?>〜<?= h($row['last_year']) ?>年
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
document.querySelectorAll('.result-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        const target = tab.dataset.tabTarget;

        document.querySelectorAll('.result-tab').forEach(item => {
            const isActive = item === tab;
            item.classList.toggle('is-active', isActive);
            item.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.toggle('is-active', panel.dataset.tabPanel === target);
        });
    });
});
</script>

</body>
</html>
