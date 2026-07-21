<?php
// sitemap.php

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

function ii_sitemap_count(PDO $pdo, string $type): int {
    if ($type === 'static') {
        return count(ii_sitemap_static_paths());
    }

    $sql_by_type = [
        'issues' => "
            SELECT COUNT(DISTINCT mi.id)
            FROM magazine_issues mi
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status IN ('approved', 'in_progress', 'verified')
        ",
        'magazines' => "
            SELECT COUNT(DISTINCT m.id)
            FROM magazines m
            JOIN magazine_issues mi ON mi.magazine_id = m.id
            JOIN issue_contents ic ON ic.issue_id = mi.id
            WHERE mi.status = 'approved'
        ",
        'works' => "
            SELECT COUNT(DISTINCT w.id)
            FROM works w
            JOIN issue_contents ic ON ic.work_id = w.id
        ",
        'creators' => "
            SELECT COUNT(DISTINCT c.id)
            FROM creators c
            JOIN content_creators cc ON cc.creator_id = c.id
            JOIN issue_contents ic ON ic.id = cc.content_id
        ",
    ];

    if (!isset($sql_by_type[$type])) {
        return 0;
    }

    $stmt = $pdo->prepare($sql_by_type[$type]);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
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

try {
    $pdo = ii_sitemap_require_pdo();
    $types = ['static', 'magazines', 'issues', 'works', 'creators'];
    $sitemaps = [];

    foreach ($types as $type) {
        $count = ii_sitemap_count($pdo, $type);
        if ($count <= 0) {
            continue;
        }

        $pages = (int)ceil($count / II_SITEMAP_URL_LIMIT);
        for ($page = 1; $page <= $pages; $page++) {
            $sitemaps[] = II_SITEMAP_BASE_URL . '/sitemap_urls.php?type=' . rawurlencode($type) . '&page=' . $page;
        }
    }
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
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($sitemaps as $loc): ?>
  <sitemap>
    <loc><?= ii_sitemap_xml($loc) ?></loc>
  </sitemap>
<?php endforeach; ?>
</sitemapindex>
