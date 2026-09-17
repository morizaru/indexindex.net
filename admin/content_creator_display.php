<?php
require_once __DIR__ . '/../app/content_creator_display.php';

// Admin-only visibility marker; public pages can reuse the plain-text builders separately.
function render_admin_content_creators(array $creators)
{
    $html = '';
    foreach ($creators as $creator) {
        $marker = (int)$creator['is_visible'] === 0 ? '<small>［非表示］</small> ' : '';
        $html .= '<div>' . $marker
            . htmlspecialchars(content_creator_display_label($creator), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $html;
}
