<?php

// Plain text only; callers escape for their output context.
function content_creator_display_name(array $creator)
{
    $name = (string)($creator['display_name'] ?? '');
    if ($name === '') { $name = (string)($creator['creator_name'] ?? ''); }
    $name .= (string)($creator['display_title'] ?? '');
    $affiliation = (string)($creator['display_affiliation'] ?? '');
    if ($affiliation !== '') { $name .= '（' . $affiliation . '）'; }
    return $name;
}

function content_creator_display_label(array $creator)
{
    $role = (string)($creator['role'] ?? '');
    return ($role !== '' && $role !== 'author' ? $role . '：' : '') . content_creator_display_name($creator);
}

// One query per issue, not per content. No GROUP_CONCAT truncation or delimiter parsing.
function load_issue_content_creators(PDO $pdo, $issueId)
{
    if ((int)$issueId <= 0) { return []; }
    $stmt = $pdo->prepare('SELECT cc.id, cc.content_id, cc.creator_id, c.name AS creator_name,
        cc.role, cc.display_name, cc.display_title, cc.display_affiliation, cc.display_order, cc.is_visible
        FROM issue_contents ic
        JOIN content_creators cc ON cc.content_id = ic.id
        LEFT JOIN creators c ON c.id = cc.creator_id
        WHERE ic.issue_id = ?
        ORDER BY cc.content_id, cc.display_order, cc.id');
    $stmt->execute([(int)$issueId]);
    $byContent = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $byContent[(int)$row['content_id']][] = $row; }
    return $byContent;
}
