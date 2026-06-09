<?php
// admin/works.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_works_page_url(int $page, int $publisher_id, int $magazine_id, string $publication_year, string $q): string {
    $query = [];

    if ($publisher_id > 0) {
        $query['publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $query['magazine_id'] = $magazine_id;
    }

    if ($publication_year !== '') {
        $query['publication_year'] = $publication_year;
    }

    if ($q !== '') {
        $query['q'] = $q;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'works.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function render_pagination(int $page, int $total_pages, int $publisher_id, int $magazine_id, string $publication_year, string $q): void {
    if ($total_pages <= 1) {
        return;
    }

    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $page + 2);
    ?>
    <div class="pagination" aria-label="ページ移動">
        <?php if ($page > 1): ?>
            <a href="<?= h(build_works_page_url(1, $publisher_id, $magazine_id, $publication_year, $q)) ?>">最初</a>
            <a href="<?= h(build_works_page_url($page - 1, $publisher_id, $magazine_id, $publication_year, $q)) ?>">前へ</a>
        <?php else: ?>
            <span class="disabled">最初</span>
            <span class="disabled">前へ</span>
        <?php endif; ?>

        <?php if ($start_page > 1): ?>
            <a href="<?= h(build_works_page_url(1, $publisher_id, $magazine_id, $publication_year, $q)) ?>">1</a>
            <?php if ($start_page > 2): ?>
                <span class="dots">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= h($i) ?></span>
            <?php else: ?>
                <a href="<?= h(build_works_page_url($i, $publisher_id, $magazine_id, $publication_year, $q)) ?>"><?= h($i) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="dots">...</span>
            <?php endif; ?>
            <a href="<?= h(build_works_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $q)) ?>"><?= h($total_pages) ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= h(build_works_page_url($page + 1, $publisher_id, $magazine_id, $publication_year, $q)) ?>">次へ</a>
            <a href="<?= h(build_works_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $q)) ?>">最後</a>
        <?php else: ?>
            <span class="disabled">次へ</span>
            <span class="disabled">最後</span>
        <?php endif; ?>
    </div>
    <?php
}

$message = '';
$error = '';

$publisher_id = isset($_GET['publisher_id']) ? (int)$_GET['publisher_id'] : 0;
$magazine_id = isset($_GET['magazine_id']) ? (int)$_GET['magazine_id'] : 0;
$publication_year = trim((string)($_GET['publication_year'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 100;

if ($publication_year !== '' && !preg_match('/^[0-9]{4}$/', $publication_year)) {
    $publication_year = '';
}

$filter_year_expr = "COALESCE(
    mi.publication_year,
    YEAR(mi.publication_date),
    YEAR(mi.release_date),
    mi.issue_year
)";

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $work_id = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;

    if ($work_id <= 0) {
        $error = '作品IDが不正です。';
    } else {
        try {
            // 目次に使われているか確認
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM issue_contents
                WHERE work_id = :work_id
            ");
            $stmt->execute([
                ':work_id' => $work_id
            ]);
            $row = $stmt->fetch();

            if ((int)$row['cnt'] > 0) {
                $error = 'この作品は目次データに使用されているため削除できません。先に目次側を修正してください。';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM works
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $work_id
                ]);

                $message = '作品を削除しました。';
            }
        } catch (Exception $e) {
            $error = '削除エラー：' . $e->getMessage();
        }
    }
}

// 絞り込み候補取得
$stmt = $pdo->query("
    SELECT
        id,
        name
    FROM publishers
    ORDER BY name ASC
");
$publishers = $stmt->fetchAll();

$magazine_sql = "
    SELECT
        id,
        name
    FROM magazines
    WHERE 1 = 1
";
$magazine_params = [];

if ($publisher_id > 0) {
    $magazine_sql .= " AND publisher_id = :publisher_id";
    $magazine_params[':publisher_id'] = $publisher_id;
}

$magazine_sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($magazine_sql);
$stmt->execute($magazine_params);
$magazines = $stmt->fetchAll();

$year_sql = "
    SELECT DISTINCT
        {$filter_year_expr} AS filter_year
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    WHERE {$filter_year_expr} IS NOT NULL
";
$year_params = [];

if ($publisher_id > 0) {
    $year_sql .= " AND m.publisher_id = :publisher_id";
    $year_params[':publisher_id'] = $publisher_id;
}

if ($magazine_id > 0) {
    $year_sql .= " AND mi.magazine_id = :magazine_id";
    $year_params[':magazine_id'] = $magazine_id;
}

$year_sql .= " ORDER BY filter_year DESC";
$stmt = $pdo->prepare($year_sql);
$stmt->execute($year_params);
$publication_years = $stmt->fetchAll();

if ($publication_year !== '') {
    $year_exists = false;

    foreach ($publication_years as $row) {
        if ((string)($row['filter_year'] ?? '') === $publication_year) {
            $year_exists = true;
            break;
        }
    }

    if (!$year_exists) {
        $publication_year = '';
    }
}

// 一覧取得
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        w.title LIKE :q_title
        OR w.title_sub LIKE :q_title_sub
        OR w.title_kana LIKE :q_title_kana
    )";
    $params[':q_title'] = '%' . $q . '%';
    $params[':q_title_sub'] = '%' . $q . '%';
    $params[':q_title_kana'] = '%' . $q . '%';
}

if ($publisher_id > 0 || $magazine_id > 0 || $publication_year !== '') {
    $exists_where = [];

    if ($publisher_id > 0) {
        $exists_where[] = "m2.publisher_id = :publisher_id";
        $params[':publisher_id'] = $publisher_id;
    }

    if ($magazine_id > 0) {
        $exists_where[] = "mi2.magazine_id = :magazine_id";
        $params[':magazine_id'] = $magazine_id;
    }

    if ($publication_year !== '') {
        $exists_year_expr = str_replace('mi.', 'mi2.', $filter_year_expr);
        $exists_where[] = "{$exists_year_expr} = :publication_year";
        $params[':publication_year'] = (int)$publication_year;
    }

    $where[] = "EXISTS (
        SELECT 1
        FROM issue_contents ic2
        JOIN magazine_issues mi2 ON ic2.issue_id = mi2.id
        JOIN magazines m2 ON mi2.magazine_id = m2.id
        WHERE ic2.work_id = w.id
          AND " . implode("\n          AND ", $exists_where) . "
    )";
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode("\n      AND ", $where);
}

$count_sql = "
    SELECT COUNT(*) AS total_count
    FROM works w
    {$where_sql}
";

$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = (int)($stmt->fetch()['total_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_count / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;
$from_count = $total_count > 0 ? $offset + 1 : 0;
$to_count = min($offset + $per_page, $total_count);

$sql = "
    SELECT
        w.id,
        w.title,
        w.title_sub,
        w.title_kana,
        w.publication_type,
        w.episode_format_id,
        ef.name AS episode_format_name,
        ef.prefix AS episode_format_prefix,
        ef.suffix AS episode_format_suffix,
        w.created_at,
        w.updated_at,
        COUNT(DISTINCT ic.id) AS content_count,
        MIN(mi.issue_year) AS first_year,
        MAX(mi.issue_year) AS last_year
    FROM works w
    LEFT JOIN episode_formats ef ON w.episode_format_id = ef.id
    LEFT JOIN issue_contents ic ON w.id = ic.work_id
    LEFT JOIN magazine_issues mi ON ic.issue_id = mi.id
    {$where_sql}
    GROUP BY w.id
    ORDER BY w.title ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $param_type = strpos($key, ':q_') === 0 ? PDO::PARAM_STR : PDO::PARAM_INT;
    $stmt->bindValue($key, $value, $param_type);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$works = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>作品一覧 - INDEXINDEX 管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 1300px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
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

        .message {
            padding: 12px;
            background: #e8f5e9;
            border: 1px solid #81c784;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .error {
            padding: 12px;
            background: #ffebee;
            border: 1px solid #e57373;
            color: #b00020;
            margin-bottom: 16px;
            border-radius: 4px;
        }

        .count {
            margin-bottom: 16px;
            color: #666;
        }

        .filter-box {
            margin-bottom: 16px;
        }

        .filter-box input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
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

        .num {
            width: 90px;
            text-align: right;
        }

        .type {
            width: 90px;
        }

        .format {
            width: 150px;
        }

        .year {
            width: 150px;
        }

        .actions {
            width: 210px;
            white-space: nowrap;
        }

        .button-link {
            display: inline-block;
            padding: 6px 10px;
            background: #333;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            margin-right: 4px;
            margin-bottom: 4px;
        }

        .button-link.public {
            background: #0066cc;
        }

        .button-link.graph {
            background: #00897b;
        }

        button.delete-button {
            padding: 6px 10px;
            background: #b00020;
            color: #fff;
            border: 1px solid #b00020;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        button.delete-button:disabled {
            background: #aaa;
            border-color: #aaa;
            cursor: not-allowed;
        }

        .title-main {
            font-weight: bold;
        }

        .title-sub {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
        }

        .small {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }

        .format-preview {
            font-weight: bold;
            color: #444;
        }

        @media (max-width: 900px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
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
                width: 130px;
                color: #555;
            }

            td.title::before { content: "作品"; }
            td.type::before { content: "区分"; }
            td.format::before { content: "話数形式"; }
            td.num::before { content: "件数"; }
            td.year::before { content: "収録年"; }
            td.actions::before { content: "操作"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>作品一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="episode_formats.php">話数フォーマット一覧</a>
        <a href="../works.php">公開：作品一覧</a>
        <a href="../index.php">公開トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="count">
        全 <?= h($total_count) ?> 件中 <?= h($from_count) ?>〜<?= h($to_count) ?> 件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?> ページ
    </div>

    <form method="get" action="works.php" id="workFilterForm" class="filter-form">
        <input type="hidden" name="page" id="filterPage" value="1">
        <div class="filter-row">
            <div class="filter-item">
                <label for="publisherFilter">出版社</label>
                <select name="publisher_id" id="publisherFilter">
                    <option value="">すべて</option>
                    <?php foreach ($publishers as $row): ?>
                        <option value="<?= h($row['id']) ?>" <?= ($publisher_id === (int)$row['id']) ? 'selected' : '' ?>>
                            <?= h($row['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item filter-item-wide">
                <label for="magazineFilter">雑誌</label>
                <select name="magazine_id" id="magazineFilter" class="select-magazine-wide">
                    <option value="">すべて</option>
                    <?php foreach ($magazines as $row): ?>
                        <option value="<?= h($row['id']) ?>" <?= ($magazine_id === (int)$row['id']) ? 'selected' : '' ?>>
                            <?= h($row['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item filter-item-year">
                <label for="publicationYearFilter">発行年</label>
                <select name="publication_year" id="publicationYearFilter">
                    <option value="">すべて</option>
                    <?php foreach ($publication_years as $row): ?>
                        <?php if ($row['filter_year'] === null || $row['filter_year'] === '') continue; ?>
                        <?php $year = (string)$row['filter_year']; ?>
                        <option value="<?= h($year) ?>" <?= ($publication_year === $year) ? 'selected' : '' ?>>
                            <?= h($year) ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item filter-item-wide">
                <label for="q">作品名検索</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="search-input-wide"
                    value="<?= h($q) ?>"
                    placeholder="作品名・サブタイトル・かな"
                >
            </div>

            <div class="filter-actions">
                <button type="submit">検索</button>
                <a class="button-link" href="works.php">条件リセット</a>
            </div>
        </div>
    </form>

    <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $publication_year, $q); ?>

    <?php if (empty($works)): ?>

        <p>作品データがありません。</p>

    <?php else: ?>

        <table id="worksTable">
            <thead>
                <tr>
                    <th>作品</th>
                    <th class="type">区分</th>
                    <th class="format">話数形式</th>
                    <th class="num">登録件数</th>
                    <th class="year">収録年</th>
                    <th>更新日時</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($works as $row): ?>
                <?php
                    $can_delete = ((int)$row['content_count'] === 0);

                    $format_preview = '';
                    if (!empty($row['episode_format_name'])) {
                        $format_preview = (string)($row['episode_format_prefix'] ?? '') . '1' . (string)($row['episode_format_suffix'] ?? '');
                    }
                ?>
                <tr>
                    <td class="title">
                        <div class="title-main"><?= h($row['title']) ?></div>

                        <?php if (!empty($row['title_sub'])): ?>
                            <div class="title-sub"><?= h($row['title_sub']) ?></div>
                        <?php endif; ?>

                        <div class="small">
                            <?php if (!empty($row['title_kana'])): ?>
                                かな：<?= h($row['title_kana']) ?><br>
                            <?php endif; ?>
                            ID：<?= h($row['id']) ?>
                        </div>
                    </td>

                    <td class="type">
                        <?= h($row['publication_type'] ?? '') ?>
                    </td>

                    <td class="format">
                        <?php if (!empty($row['episode_format_name'])): ?>
                            <?= h($row['episode_format_name']) ?>
                            <div class="small">
                                ID：<?= h($row['episode_format_id']) ?><br>
                                表示例：<span class="format-preview"><?= h($format_preview) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="small">未設定</span>
                        <?php endif; ?>
                    </td>

                    <td class="num">
                        <?= h($row['content_count']) ?>
                    </td>

                    <td class="year">
                        <?php if ($row['first_year'] && $row['last_year']): ?>
                            <?php if ($row['first_year'] == $row['last_year']): ?>
                                <?= h($row['first_year']) ?>年
                            <?php else: ?>
                                <?= h($row['first_year']) ?>年〜<?= h($row['last_year']) ?>年
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="small">
                            作成：<?= h($row['created_at']) ?><br>
                            更新：<?= h($row['updated_at']) ?>
                        </div>
                    </td>

                    <td class="actions">
                        <a class="button-link public" href="../work.php?work_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                        <a class="button-link" href="work_edit.php?id=<?= h($row['id']) ?>">編集</a>
                        <a class="button-link graph" href="../work_graph.php?work_id=<?= h($row['id']) ?>" target="_blank">グラフ</a>

                        <form method="post" action="works.php" style="display:inline;"
                              onsubmit="return confirm('この作品を削除しますか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="work_id" value="<?= h($row['id']) ?>">
                            <button type="submit" class="delete-button" <?= $can_delete ? '' : 'disabled' ?>>
                                削除
                            </button>
                        </form>

                        <?php if (!$can_delete): ?>
                            <div class="small">※目次に使用中</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $publication_year, $q); ?>

</div>

<script>
const workFilterForm = document.getElementById('workFilterForm');
const publisherFilter = document.getElementById('publisherFilter');
const magazineFilter = document.getElementById('magazineFilter');
const publicationYearFilter = document.getElementById('publicationYearFilter');
const filterPage = document.getElementById('filterPage');

function submitWorkFilterForm() {
    if (workFilterForm) {
        if (filterPage) {
            filterPage.value = '1';
        }
        workFilterForm.submit();
    }
}

if (workFilterForm) {
    workFilterForm.addEventListener('submit', () => {
        if (filterPage) {
            filterPage.value = '1';
        }
    });
}

if (publisherFilter) {
    publisherFilter.addEventListener('change', () => {
        if (magazineFilter) {
            magazineFilter.value = '';
        }
        submitWorkFilterForm();
    });
}

if (magazineFilter) {
    magazineFilter.addEventListener('change', submitWorkFilterForm);
}

if (publicationYearFilter) {
    publicationYearFilter.addEventListener('change', submitWorkFilterForm);
}
</script>

</body>
</html>
