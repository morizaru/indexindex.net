<?php
// admin/creators.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_creators_page_url(int $page, int $publisher_id, int $magazine_id, string $publication_year, string $q, bool $zero_only): string {
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

    if ($zero_only) {
        $query['zero_only'] = 1;
    }

    if ($page > 1) {
        $query['page'] = $page;
    }

    $query_string = http_build_query($query);
    return 'creators.php' . ($query_string !== '' ? '?' . $query_string : '');
}

function render_pagination(int $page, int $total_pages, int $publisher_id, int $magazine_id, string $publication_year, string $q, bool $zero_only): void {
    if ($total_pages <= 1) {
        return;
    }

    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $page + 2);
    ?>
    <div class="pagination" aria-label="ページ移動">
        <?php if ($page > 1): ?>
            <a href="<?= h(build_creators_page_url(1, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>">最初</a>
            <a href="<?= h(build_creators_page_url($page - 1, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>">前へ</a>
        <?php else: ?>
            <span class="disabled">最初</span>
            <span class="disabled">前へ</span>
        <?php endif; ?>

        <?php if ($start_page > 1): ?>
            <a href="<?= h(build_creators_page_url(1, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>">1</a>
            <?php if ($start_page > 2): ?>
                <span class="dots">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= h($i) ?></span>
            <?php else: ?>
                <a href="<?= h(build_creators_page_url($i, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>"><?= h($i) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="dots">...</span>
            <?php endif; ?>
            <a href="<?= h(build_creators_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>"><?= h($total_pages) ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= h(build_creators_page_url($page + 1, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>">次へ</a>
            <a href="<?= h(build_creators_page_url($total_pages, $publisher_id, $magazine_id, $publication_year, $q, $zero_only)) ?>">最後</a>
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
$zero_only = (($_GET['zero_only'] ?? '') === '1');
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
    $creator_id = isset($_POST['creator_id']) ? (int)$_POST['creator_id'] : 0;

    if ($creator_id <= 0) {
        $error = '人物IDが不正です。';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM content_creators
                WHERE creator_id = :creator_id
            ");
            $stmt->execute([
                ':creator_id' => $creator_id
            ]);
            $row = $stmt->fetch();

            if ((int)$row['cnt'] > 0) {
                $error = 'この人物は目次データに使用されているため削除できません。先に目次側を修正してください。';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM creators
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $creator_id
                ]);

                $message = '人物を削除しました。';
            }
        } catch (Exception $e) {
            $error = '削除エラー: ' . $e->getMessage();
        }
    }
}

// 絞り込み候補
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
        c.name LIKE :q_name
        OR c.name_kana LIKE :q_name_kana
    )";
    $params[':q_name'] = '%' . $q . '%';
    $params[':q_name_kana'] = '%' . $q . '%';
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
        FROM content_creators cc2
        JOIN issue_contents ic2 ON cc2.content_id = ic2.id
        JOIN magazine_issues mi2 ON ic2.issue_id = mi2.id
        JOIN magazines m2 ON mi2.magazine_id = m2.id
        WHERE cc2.creator_id = c.id
          AND " . implode("\n          AND ", $exists_where) . "
    )";
}

if ($zero_only) {
    $where[] = "NOT EXISTS (
        SELECT 1
        FROM content_creators cc_zero
        WHERE cc_zero.creator_id = c.id
    )";
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode("\n      AND ", $where);
}

$count_sql = "
    SELECT COUNT(DISTINCT c.id) AS total_count
    FROM creators c
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
        c.id,
        c.name,
        c.name_kana,
        COUNT(DISTINCT cc.content_id) AS content_count,
        c.created_at
    FROM creators c
    LEFT JOIN content_creators cc ON c.id = cc.creator_id
    {$where_sql}
    GROUP BY c.id
    ORDER BY c.name ASC
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
$creators = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>人物一覧 - INDEXINDEX 管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .container {
            max-width: 1100px;
        }

        .num {
            width: 90px;
            text-align: right;
        }

        .actions {
            width: 190px;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        @media (max-width: 800px) {
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
                width: 100px;
                color: #555;
            }

            td.name::before { content: "人物"; }
            td.num::before { content: "件数"; }
            td.note-cell::before { content: "備考"; }
            td.actions::before { content: "操作"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>人物一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="creator_add.php">人物を登録する</a>
        <a href="../creators.php">公開:人物一覧</a>
        <a href="../index.php">公開トップ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="count result-summary">
        全 <?= h($total_count) ?> 件中 <?= h($from_count) ?>〜<?= h($to_count) ?> 件を表示<br>
        <?= h($page) ?> / <?= h($total_pages) ?> ページ
    </div>

    <form method="get" action="creators.php" id="creatorFilterForm" class="filter-form">
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
                <label for="q">人物名検索</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="search-input-wide"
                    value="<?= h($q) ?>"
                    placeholder="人物名・かな"
                >
            </div>

            <div class="filter-item">
                <label>
                    <input type="checkbox" name="zero_only" value="1" <?= $zero_only ? 'checked' : '' ?>>
                    登録件数0のみ表示
                </label>
            </div>

            <div class="filter-actions">
                <button type="submit">検索</button>
                <a class="button-link" href="creators.php">条件リセット</a>
            </div>
        </div>
    </form>

    <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $publication_year, $q, $zero_only); ?>

    <?php if (empty($creators)): ?>

        <p>人物データがありません。</p>

    <?php else: ?>

        <table id="creatorsTable">
            <thead>
                <tr>
                    <th>人物</th>
                    <th class="num">登録件数</th>
                    <th>備考</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($creators as $row): ?>
                <?php
                    $can_delete = ((int)$row['content_count'] === 0);
                ?>
                <tr>
                    <td class="name">
                        <strong><?= h($row['name']) ?></strong>
                        <div class="small">
                            <?php if (!empty($row['name_kana'])): ?>
                                かな: <?= h($row['name_kana']) ?><br>
                            <?php endif; ?>
                            ID: <?= h($row['id']) ?><br>
                            作成: <?= h($row['created_at']) ?>
                        </div>
                    </td>

                    <td class="num"><?= h($row['content_count']) ?></td>

                    <td class="note-cell"></td>

                    <td class="actions">
                        <a class="button-link public" href="../creator.php?creator_id=<?= h($row['id']) ?>" target="_blank">公開</a>
                        <a class="button-link" href="creator_edit.php?id=<?= h($row['id']) ?>">編集</a>

                        <form method="post" action="creators.php" style="display:inline;"
                              onsubmit="return confirm('この人物を削除しますか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="creator_id" value="<?= h($row['id']) ?>">
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

    <?php render_pagination($page, $total_pages, $publisher_id, $magazine_id, $publication_year, $q, $zero_only); ?>

</div>

<script>
const creatorFilterForm = document.getElementById('creatorFilterForm');
const publisherFilter = document.getElementById('publisherFilter');
const magazineFilter = document.getElementById('magazineFilter');
const publicationYearFilter = document.getElementById('publicationYearFilter');
const filterPage = document.getElementById('filterPage');

function submitCreatorFilterForm() {
    if (creatorFilterForm) {
        if (filterPage) {
            filterPage.value = '1';
        }
        creatorFilterForm.submit();
    }
}

if (creatorFilterForm) {
    creatorFilterForm.addEventListener('submit', () => {
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
        submitCreatorFilterForm();
    });
}

if (magazineFilter) {
    magazineFilter.addEventListener('change', submitCreatorFilterForm);
}

if (publicationYearFilter) {
    publicationYearFilter.addEventListener('change', submitCreatorFilterForm);
}
</script>

</body>
</html>
