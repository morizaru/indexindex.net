<?php
// sitemap_urls.php

const II_SITEMAP_BASE_URL = 'https://morizaru.net/indexindex';
const II_SITEMAP_URL_LIMIT = 45000;

function ii_sitemap_xml($value): string {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function ii_sitemap_require_pdo(): PDO {
    ob_start();
    require __DIR__ . '/app/config.php';
    ob_end_clean();

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO connection is not available.');
    }

    return $pdo;
}

function ii_sitemap_static_paths(): array {
    return [
        '/index.php',
        '/issues.php',
        '/works.php',
        '/creators.php',
        '/ranking.php',
        '/recent_updates.php',
        '/mediamix.php',
        '/about.php',
        '/how_to_use.php',
        '/data_policy.php',
        '/terms.php',
        '/contact.php',
        '/privacy.php',
    ];
}

function ii_sitemap_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    $allowed = ['magazines', 'magazine_issues', 'works', 'creators'];

    if (!in_array($table, $allowed, true)) {
        return [];
    }

    if (!isset($cache[$table])) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cache[$table] = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }

    return $cache[$table];
}

function ii_sitemap_lastmod_select(PDO $pdo, string $table, string $alias): string {
    $columns = ii_sitemap_table_columns($pdo, $table);

    if (isset($columns['updated_at'])) {
        return "MAX(DATE({$alias}.`updated_at`)) AS lastmod";
    }

    if (isset($columns['created_at'])) {
        return "MAX(DATE({$alias}.`created_at`)) AS lastmod";
    }

    return "NULL AS lastmod";
}

function ii_sitemap_valid_lastmod($value): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value)) {
        return str_replace(' ', 'T', substr($value, 0, 19)) . '+09:00';
    }

    return '';
}

function ii_sitemap_query(PDO $pdo, string $type, int $page): array {
    $offset = ($page - 1) * II_SITEMAP_URL_LIMIT;

    if ($type === 'static') {
        return [
            'static_paths' => $page === 1 ? ii_sitemap_static_paths() : [],
            'stmt' => null,
            'path' => '',
        ];
    }

    if ($type === 'magazines') {
        $lastmod = ii_sitemap_lastmod_select($pdo, 'magazines', 'm');
        $sql = "
            SELECT
                m.id,
                {$lastmod}
            FROM magazines m
            JOIN magazine_issues mi ON mi.magazine_id = m.id
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
            GROUP BY m.id
            ORDER BY m.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $path = '/issues.php?magazine_id=';
    } elseif ($type === 'issues') {
        $lastmod = ii_sitemap_lastmod_select($pdo, 'magazine_issues', 'mi');
        $sql = "
            SELECT
                mi.id,
                {$lastmod}
            FROM magazine_issues mi
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status IN ('approved', 'in_progress', 'verified')
            GROUP BY mi.id
            ORDER BY mi.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $path = '/issue.php?id=';
    } elseif ($type === 'works') {
        $lastmod = ii_sitemap_lastmod_select($pdo, 'works', 'w');
        $sql = "
            SELECT
                w.id,
                {$lastmod}
            FROM works w
            JOIN issue_contents ic ON ic.work_id = w.id
            GROUP BY w.id
            ORDER BY w.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $path = '/work.php?work_id=';
    } elseif ($type === 'creators') {
        $lastmod = ii_sitemap_lastmod_select($pdo, 'creators', 'c');
        $sql = "
            SELECT
                c.id,
                {$lastmod}
            FROM creators c
            JOIN content_creators cc ON cc.creator_id = c.id
            JOIN issue_contents ic ON ic.id = cc.content_id
            GROUP BY c.id
            ORDER BY c.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $path = '/creator.php?creator_id=';
    } else {
        return [];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', II_SITEMAP_URL_LIMIT, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'static_paths' => [],
        'stmt' => $stmt,
        'path' => $path,
    ];
}

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$page_raw = isset($_GET['page']) ? trim((string)$_GET['page']) : '';
$allowed_types = ['static', 'magazines', 'issues', 'works', 'creators'];

if (!in_array($type, $allowed_types, true) || !preg_match('/^[1-9][0-9]*$/', $page_raw)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Bad Request');
}

$page = (int)$page_raw;

try {
    $pdo = ii_sitemap_require_pdo();
    $query = ii_sitemap_query($pdo, $type, $page);
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sitemap generation failed.');
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($query['static_paths'] as $path): ?>
  <url>
    <loc><?= ii_sitemap_xml(II_SITEMAP_BASE_URL . $path) ?></loc>
  </url>
<?php endforeach; ?>
<?php if ($query['stmt'] instanceof PDOStatement): ?>
<?php while ($row = $query['stmt']->fetch(PDO::FETCH_ASSOC)): ?>
  <url>
    <loc><?= ii_sitemap_xml(II_SITEMAP_BASE_URL . $query['path'] . (int)$row['id']) ?></loc>
<?php $lastmod = ii_sitemap_valid_lastmod($row['lastmod'] ?? ''); ?>
<?php if ($lastmod !== ''): ?>
    <lastmod><?= ii_sitemap_xml($lastmod) ?></lastmod>
<?php endif; ?>
  </url>
<?php endwhile; ?>
<?php endif; ?>
</urlset>
