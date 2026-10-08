<?php
// recent_updates.php
// INDEXINDEX 公開 最近の更新

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('DB接続エラー: app/config.php で $pdo が作成されていません。');
}

$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('最近の更新');

date_default_timezone_set('Asia/Tokyo');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$limit = 20;

function fetch_table_columns(PDO $pdo, string $table): array {
    $allowed_tables = ['magazine_issues', 'creators', 'magazines', 'works'];
    if (!in_array($table, $allowed_tables, true)) {
        return [];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $columns[$row['Field']] = true;
            }
        }

        return $columns;
    } catch (PDOException $e) {
        error_log('recent_updates.php column check failed: ' . $table . ' ' . $e->getMessage());
        return [];
    }
}

function has_column(array $columns, string $name): bool {
    return isset($columns[$name]);
}

function select_column(array $columns, string $alias, string $column, string $as): string {
    if (!has_column($columns, $column)) {
        return "NULL AS {$as}";
    }

    return "{$alias}.`{$column}` AS {$as}";
}

function recent_datetime_expr(array $columns, string $alias): string {
    if (has_column($columns, 'updated_at') && has_column($columns, 'created_at')) {
        return "COALESCE({$alias}.updated_at, {$alias}.created_at)";
    }

    if (has_column($columns, 'updated_at')) {
        return "{$alias}.updated_at";
    }

    if (has_column($columns, 'created_at')) {
        return "{$alias}.created_at";
    }

    return 'NULL';
}

function recent_order_sql(array $columns, string $alias): string {
    $order = recent_datetime_expr($columns, $alias) . ' DESC';

    if (has_column($columns, 'id')) {
        $order .= ", {$alias}.id DESC";
    }

    return $order;
}

function fetch_recent_rows(PDO $pdo, string $sql): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('recent_updates.php query failed: ' . $e->getMessage());
        return [];
    }
}

function fetch_recent_issues(PDO $pdo, array $issue_columns, array $magazine_columns, int $limit): array {
    if (!has_column($issue_columns, 'id')) {
        return [];
    }

    $join_magazines = has_column($issue_columns, 'magazine_id') && has_column($magazine_columns, 'id');
    $magazine_id_select = has_column($issue_columns, 'magazine_id')
        ? 'mi.magazine_id AS magazine_id'
        : 'NULL AS magazine_id';
    $magazine_name_select = $join_magazines && has_column($magazine_columns, 'name')
        ? 'm.name AS magazine_name'
        : 'NULL AS magazine_name';

    $sql = "
        SELECT
            mi.id,
            {$magazine_id_select},
            {$magazine_name_select},
            " . select_column($issue_columns, 'mi', 'issue_label', 'issue_label') . "
        FROM magazine_issues mi
    ";

    if ($join_magazines) {
        $sql .= "
            LEFT JOIN magazines m
                ON m.id = mi.magazine_id
        ";
    }

    $sql .= "
        ORDER BY " . recent_order_sql($issue_columns, 'mi') . "
        LIMIT {$limit}
    ";

    return fetch_recent_rows($pdo, $sql);
}

function fetch_recent_creators(PDO $pdo, array $creator_columns, int $limit): array {
    if (!has_column($creator_columns, 'id')) {
        return [];
    }

    $sql = "
        SELECT
            c.id,
            " . select_column($creator_columns, 'c', 'name', 'name') . "
        FROM creators c
        ORDER BY " . recent_order_sql($creator_columns, 'c') . "
        LIMIT {$limit}
    ";

    return fetch_recent_rows($pdo, $sql);
}

function fetch_recent_works(PDO $pdo, array $work_columns, int $limit): array {
    if (!has_column($work_columns, 'id')) {
        return [];
    }

    $sql = "
        SELECT
            w.id,
            " . select_column($work_columns, 'w', 'title', 'title') . "
        FROM works w
        ORDER BY " . recent_order_sql($work_columns, 'w') . "
        LIMIT {$limit}
    ";

    return fetch_recent_rows($pdo, $sql);
}

function fetch_recent_magazines(PDO $pdo, array $magazine_columns, int $limit): array {
    if (!has_column($magazine_columns, 'id')) {
        return [];
    }

    $sql = "
        SELECT
            m.id,
            " . select_column($magazine_columns, 'm', 'name', 'name') . "
        FROM magazines m
        ORDER BY " . recent_order_sql($magazine_columns, 'm') . "
        LIMIT {$limit}
    ";

    return fetch_recent_rows($pdo, $sql);
}

$issue_columns = fetch_table_columns($pdo, 'magazine_issues');
$work_columns = fetch_table_columns($pdo, 'works');
$creator_columns = fetch_table_columns($pdo, 'creators');
$magazine_columns = fetch_table_columns($pdo, 'magazines');

$recent_issues = fetch_recent_issues($pdo, $issue_columns, $magazine_columns, $limit);
$recent_works = fetch_recent_works($pdo, $work_columns, $limit);
$recent_creators = fetch_recent_creators($pdo, $creator_columns, $limit);
$recent_magazines = fetch_recent_magazines($pdo, $magazine_columns, $limit);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php require_once __DIR__ . '/includes/public_url.php'; ?>
    <link rel="canonical" href="<?= htmlspecialchars(ii_public_canonical('recent_updates.php'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="UTF-8">
    <title>最近の更新 - INDEXINDEX</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            margin-bottom: 8px;
        }

        h2 {
            margin: 0 0 14px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .lead {
            color: #666;
            margin: 0 0 18px;
            line-height: 1.7;
        }

        .updates-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr) minmax(0, 0.75fr) minmax(0, 1fr);
            gap: 14px;
            align-items: start;
            margin-top: 24px;
        }

        .updates-panel {
            min-width: 0;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #fff;
            overflow: hidden;
        }

        .updates-panel-head {
            padding: 12px 14px;
            background: #f7f7f7;
            border-bottom: 1px solid #e0e0e0;
        }

        .updates-list {
            display: grid;
        }

        .updates-item {
            padding: 12px 14px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }

        .updates-item:nth-child(odd) {
            background: #fcfcfc;
        }

        .updates-item:last-child {
            border-bottom: 0;
        }

        .issue-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 10px;
            align-items: start;
        }

        .link-main {
            font-size: 15px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .empty {
            color: #666;
            padding: 14px;
            background: #fafafa;
        }

        .top-button {
            position: fixed;
            right: 22px;
            bottom: 22px;
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #333, #111);
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 0.04em;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
            z-index: 1000;
        }

        .top-button.is-visible {
            opacity: 0.92;
            transform: translateY(0);
            pointer-events: auto;
        }

        .top-button:hover {
            opacity: 1;
            text-decoration: none;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 1100px) {
            .updates-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .updates-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .issue-row {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .top-button {
                right: 14px;
                bottom: 14px;
                width: 48px;
                height: 48px;
                font-size: 13px;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/adsense_head.php'; ?>

    <link rel="stylesheet" href="assets/css/common.css">
</head>
<body id="top">

<?php
$current_page = 'recent_updates';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <h1>最近の更新</h1>
    <p class="lead">最近更新された雑誌号・作品・人物・雑誌を表示しています。</p>

    <div class="updates-grid">
        <section class="updates-panel">
            <div class="updates-panel-head">
                <h2>雑誌号</h2>
            </div>

            <?php if (empty($recent_issues)): ?>
                <div class="empty">更新データがありません。</div>
            <?php else: ?>
                <div class="updates-list">
                    <?php foreach ($recent_issues as $row): ?>
                        <div class="updates-item">
                            <div class="issue-row">
                                <div class="link-main">
                                    <?php if (!empty($row['magazine_id'])): ?>
                                        <a href="issues.php?magazine_id=<?= h($row['magazine_id']) ?>">
                                            <?= h($row['magazine_name'] ?? '') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= h($row['magazine_name'] ?? '') ?>
                                    <?php endif; ?>
                                </div>
                                <div class="link-main">
                                    <a href="issue.php?id=<?= h($row['id'] ?? '') ?>">
                                        <?= h($row['issue_label'] ?? '') ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="updates-panel">
            <div class="updates-panel-head">
                <h2>作品</h2>
            </div>

            <?php if (empty($recent_works)): ?>
                <div class="empty">更新データがありません。</div>
            <?php else: ?>
                <div class="updates-list">
                    <?php foreach ($recent_works as $row): ?>
                        <div class="updates-item">
                            <div class="link-main">
                                <a href="work.php?work_id=<?= h($row['id'] ?? '') ?>">
                                    <?= h($row['title'] ?? '') ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="updates-panel">
            <div class="updates-panel-head">
                <h2>人物</h2>
            </div>

            <?php if (empty($recent_creators)): ?>
                <div class="empty">更新データがありません。</div>
            <?php else: ?>
                <div class="updates-list">
                    <?php foreach ($recent_creators as $row): ?>
                        <div class="updates-item">
                            <div class="link-main">
                                <a href="creator.php?creator_id=<?= h($row['id'] ?? '') ?>">
                                    <?= h($row['name'] ?? '') ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="updates-panel">
            <div class="updates-panel-head">
                <h2>雑誌</h2>
            </div>

            <?php if (empty($recent_magazines)): ?>
                <div class="empty">更新データがありません。</div>
            <?php else: ?>
                <div class="updates-list">
                    <?php foreach ($recent_magazines as $row): ?>
                        <div class="updates-item">
                            <div class="link-main">
                                <a href="issues.php?magazine_id=<?= h($row['id'] ?? '') ?>">
                                    <?= h($row['name'] ?? '') ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<a href="#top" class="top-button" id="topButton" aria-label="TOPへ戻る">TOP</a>

<script>
const topButton = document.getElementById('topButton');

function toggleTopButton() {
    if (!topButton) {
        return;
    }

    if (window.scrollY > 240) {
        topButton.classList.add('is-visible');
    } else {
        topButton.classList.remove('is-visible');
    }
}

window.addEventListener('scroll', toggleTopButton, { passive: true });
toggleTopButton();
</script>

</body>
</html>
