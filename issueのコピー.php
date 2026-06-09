<?php
// issue.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// 話数表示作成
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
        return
            (string)($row['content_episode_format_prefix'] ?? '') .
            $episode_no .
            (string)($row['content_episode_format_suffix'] ?? '');
    }

    // 数字だけの場合は、作品側フォーマットを適用
    if (!empty($row['work_episode_format_id'])) {
        return
            (string)($row['work_episode_format_prefix'] ?? '') .
            $episode_no .
            (string)($row['work_episode_format_suffix'] ?? '');
    }

    return $episode_no;
}

$issue_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($issue_id <= 0) {
    exit('号IDが指定されていません。');
}

// 雑誌・号情報を取得
$sql_issue = "
    SELECT
        mi.id,
        mi.issue_label,
        mi.release_date,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM magazine_issues mi
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE mi.id = :issue_id
      AND mi.status = 'approved'
";

$stmt = $pdo->prepare($sql_issue);
$stmt->execute([
    ':issue_id' => $issue_id
]);
$issue = $stmt->fetch();

if (!$issue) {
    exit('指定された号が見つかりません。');
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

        w.id AS work_id,
        w.title_sub,
        w.episode_format_id AS work_episode_format_id,

        cef.name AS content_episode_format_name,
        cef.prefix AS content_episode_format_prefix,
        cef.suffix AS content_episode_format_suffix,

        wef.name AS work_episode_format_name,
        wef.prefix AS work_episode_format_prefix,
        wef.suffix AS work_episode_format_suffix,

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
        ) AS creators
    FROM issue_contents ic
    LEFT JOIN works w ON ic.work_id = w.id
    LEFT JOIN episode_formats cef ON ic.episode_format_id = cef.id
    LEFT JOIN episode_formats wef ON w.episode_format_id = wef.id
    LEFT JOIN content_creators cc ON ic.id = cc.content_id
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title><?= h($issue['magazine_name']) ?> <?= h($issue['issue_label']) ?> - INDEXINDEX</title>
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

        .meta {
            margin-bottom: 24px;
            color: #555;
            line-height: 1.8;
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
                width: 90px;
                color: #555;
            }

            td.order::before { content: "順番"; }
            td.page::before { content: "ページ"; }
            td.title::before { content: "作品"; }
            td.episode::before { content: "話数"; }
            td.episode-title::before { content: ""; }
            td.creators::before { content: "作者"; }
            td.note-cell::before { content: "備考"; }
        }
    </style>
</head>
<body>

<?php
$current_page = 'issue';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <h1><?= h($issue['magazine_name']) ?> <?= h($issue['issue_label']) ?></h1>

    <div class="meta">
        出版社：<?= h($issue['publisher_name']) ?><br>
        <?php if (!empty($issue['issue_year'])): ?>
            年：<?= h($issue['issue_year']) ?>年<br>
        <?php endif; ?>
        <?php if (!empty($issue['issue_no'])): ?>
            号数：<?= h($issue['issue_no']) ?>号<br>
        <?php endif; ?>
        発行日：<?= h($issue['release_date']) ?>
    </div>

    <?php if (empty($contents)): ?>

        <p class="empty">この号には目次データがありません。</p>

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
                    <th>備考</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
                <?php
                    $episode_display = format_episode_no($row);
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
                        </div>

                        <?php if (!empty($row['title_sub'])): ?>
                            <div class="title-sub"><?= h($row['title_sub']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="episode"><?= h($episode_display) ?></td>

                    <td class="episode-title">
                        <?= h($row['episode_title'] ?? '') ?>
                    </td>

                    <td class="creators"><?= h($row['creators'] ?? '') ?></td>

                    <td class="note-cell">
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
