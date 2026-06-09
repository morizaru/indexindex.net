<?php
if (!isset($current_page)) {
    $current_page = '';
}

$nav_items = [
    'issues' => ['label' => '雑誌', 'url' => 'issues.php'],
    'works' => ['label' => '作品', 'url' => 'works.php'],
    'creators' => ['label' => '人物', 'url' => 'creators.php'],
    'search' => ['label' => '詳細検索', 'url' => 'search.php'],
    'graph' => ['label' => 'グラフ', 'url' => 'work_graph.php'],
    'ranking' => ['label' => 'ランキング', 'url' => 'ranking.php'],
    'recent_updates' => ['label' => '最近の更新', 'url' => 'recent_updates.php'],
];
?>

<script type="text/javascript" src="//code.typesquare.com/static/ZDbTe4IzCko%253D/ts106f.js" charset="utf-8"></script>

<style>
.site-header {
    max-width: 1148px;
    margin: 18px auto 14px;
    padding: 14px 18px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
    display: flex;
    align-items: center;
    gap: 24px;
    box-sizing: border-box;
}

.site-header-logo {
    position: relative;
    display: inline-block;
    font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #111;
    text-decoration: none;
    white-space: nowrap;
    line-height: 1;
    z-index: 1;
}

.site-header-logo::before {
    content: "INDEXINDEX";
    position: absolute;
    left: 3px;
    top: -3px;
    color: #cfcfcf;
    z-index: -1;
    pointer-events: none;
}

.site-header-logo:hover {
    opacity: 0.85;
    text-decoration: none;
}

.site-header-logo:visited,
.site-header-logo:active,
.site-header-logo:focus {
    text-decoration: none;
}

.site-header-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.site-header-nav a,
.site-header-nav span {
    font-family: "GSanSerif-B", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    display: inline-flex;
    align-items: center;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 14px;
    text-decoration: none;
    color: #333;
}

.site-header-nav a:hover {
    background: #f1f3f5;
    text-decoration: none;
}

.site-header-nav .is-current {
    background: #222;
    color: #fff;
    font-weight: 700;
}

@media (max-width: 700px) {
    .site-header {
        margin: 12px 10px;
        padding: 13px 14px;
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }

    .site-header-logo {
        font-size: 24px;
    }

    .site-header-logo::before {
        left: 2px;
        top: -2px;
    }

    .site-header-nav a,
    .site-header-nav span {
        padding: 6px 10px;
        font-size: 13px;
    }
}
</style>

<header class="site-header">
    <a class="site-header-logo" href="index.php">INDEXINDEX</a>

    <nav class="site-header-nav" aria-label="メインメニュー">
        <?php foreach ($nav_items as $key => $item): ?>
            <?php if ($current_page === $key): ?>
                <span class="is-current"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</header>
