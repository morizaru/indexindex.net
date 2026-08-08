<?php
// admin/x_post_draft.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

date_default_timezone_set('Asia/Tokyo');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_site_stats_columns(PDO $pdo): array {
    $stmt = $pdo->query("SHOW COLUMNS FROM site_stats_daily");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $columns[$row['Field']] = true;
        }
    }

    return $columns;
}

function require_site_stats_columns(array $columns): void {
    $required = ['snapshot_at', 'magazine_count', 'issue_count', 'content_count', 'creator_count'];

    foreach ($required as $column) {
        if (empty($columns[$column])) {
            throw new Exception('site_stats_daily に必要なカラムがありません: ' . $column);
        }
    }
}

function get_current_site_stats(PDO $pdo): array {
    return [
        'snapshot_at' => date('Y-m-d 00:00:00'),
        'magazine_count' => (int)$pdo->query("SELECT COUNT(*) FROM magazines")->fetchColumn(),
        'issue_count' => (int)$pdo->query("SELECT COUNT(*) FROM magazine_issues")->fetchColumn(),
        'content_count' => (int)$pdo->query("SELECT COUNT(*) FROM works")->fetchColumn(),
        'creator_count' => (int)$pdo->query("
            SELECT COUNT(DISTINCT c.id)
            FROM creators c
            JOIN content_creators cc ON cc.creator_id = c.id
            WHERE cc.is_visible = 1
        ")->fetchColumn(),
    ];
}

function save_site_stats_snapshot(PDO $pdo, array $stats): void {
    $snapshot_at = (new DateTime((string)$stats['snapshot_at']))->format('Y-m-d 00:00:00');

    $stmt = $pdo->prepare("
        SELECT id
        FROM site_stats_daily
        WHERE snapshot_at = :snapshot_at
        LIMIT 1
    ");
    $stmt->execute([
        ':snapshot_at' => $snapshot_at
    ]);
    $existing_id = (int)($stmt->fetchColumn() ?: 0);

    if ($existing_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE site_stats_daily
            SET
                magazine_count = :magazine_count,
                issue_count = :issue_count,
                content_count = :content_count,
                creator_count = :creator_count
            WHERE id = :id
        ");
        $stmt->execute([
            ':magazine_count' => (int)$stats['magazine_count'],
            ':issue_count' => (int)$stats['issue_count'],
            ':content_count' => (int)$stats['content_count'],
            ':creator_count' => (int)$stats['creator_count'],
            ':id' => $existing_id,
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO site_stats_daily (
            snapshot_at,
            magazine_count,
            issue_count,
            content_count,
            creator_count
        ) VALUES (
            :snapshot_at,
            :magazine_count,
            :issue_count,
            :content_count,
            :creator_count
        )
    ");
    $stmt->execute([
        ':snapshot_at' => $snapshot_at,
        ':magazine_count' => (int)$stats['magazine_count'],
        ':issue_count' => (int)$stats['issue_count'],
        ':content_count' => (int)$stats['content_count'],
        ':creator_count' => (int)$stats['creator_count'],
    ]);
}

function get_latest_site_stats_snapshots(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            snapshot_at,
            magazine_count,
            issue_count,
            content_count,
            creator_count
        FROM site_stats_daily
        ORDER BY snapshot_at DESC, id DESC
        LIMIT 2
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function format_snapshot_datetime($snapshot_at): string {
    try {
        $date = new DateTime((string)$snapshot_at);
        return $date->format('Y/n/j H:i:s');
    } catch (Exception $e) {
        return (string)$snapshot_at;
    }
}

function diff_label($current, $previous): string {
    $diff = (int)$current - (int)$previous;

    if ($diff > 0) {
        return '▲' . number_format($diff);
    }

    if ($diff < 0) {
        return '▼' . number_format(abs($diff));
    }

    return '±0';
}

function build_x_post_draft_text(array $latest, ?array $previous): string {
    $previous_stats = $previous ?: $latest;

    $draft_text = "雑誌目次DB『INDEXINDEX』\n";
    $draft_text .= "https://morizaru.net/indexindex/\n";
    $draft_text .= format_snapshot_datetime($latest['snapshot_at']) . "現在のデータ登録件数\n\n";
    $draft_text .= "・登録雑誌数：" . number_format((int)$latest['magazine_count']) . "誌（" . diff_label($latest['magazine_count'], $previous_stats['magazine_count']) . "）\n";
    $draft_text .= "・登録雑誌号数：" . number_format((int)$latest['issue_count']) . "号（" . diff_label($latest['issue_count'], $previous_stats['issue_count']) . "）\n";
    $draft_text .= "・登録作品数：" . number_format((int)$latest['content_count']) . "件（" . diff_label($latest['content_count'], $previous_stats['content_count']) . "）\n";
    $draft_text .= "・登録人物数：" . number_format((int)$latest['creator_count']) . "人（" . diff_label($latest['creator_count'], $previous_stats['creator_count']) . "）";

    return $draft_text;
}

$message = '';
$error = '';
$current_stats = [
    'snapshot_at' => date('Y-m-d 00:00:00'),
    'magazine_count' => 0,
    'issue_count' => 0,
    'content_count' => 0,
    'creator_count' => 0,
];
$latest_snapshot = null;
$previous_snapshot = null;
$using_current_stats_for_draft = false;

try {
    $current_stats = get_current_site_stats($pdo);
} catch (Exception $e) {
    $error = '件数取得エラー：' . $e->getMessage();
}

try {
    $columns = get_site_stats_columns($pdo);
    require_site_stats_columns($columns);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_snapshot') {
        save_site_stats_snapshot($pdo, $current_stats);
        $message = '本日0:00のスナップショットを保存しました。';
    }

    $snapshots = get_latest_site_stats_snapshots($pdo);
    $latest_snapshot = $snapshots[0] ?? null;
    $previous_snapshot = $snapshots[1] ?? null;
} catch (Exception $e) {
    $error = 'スナップショット処理エラー：' . $e->getMessage();
}

if (!$latest_snapshot) {
    $latest_snapshot = $current_stats;
    $using_current_stats_for_draft = true;
}

$draft_text = build_x_post_draft_text($latest_snapshot, $previous_snapshot);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>X投稿用下書き - INDEXINDEX 管理</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 800px;
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

        .error {
            margin: 0 0 16px;
            padding: 10px 12px;
            background: #ffecec;
            border: 1px solid #e0b4b4;
            color: #b00020;
            border-radius: 6px;
        }

        .message {
            margin: 0 0 16px;
            padding: 10px 12px;
            background: #e9f7ef;
            border: 1px solid #b7e0c2;
            color: #1b6b36;
            border-radius: 6px;
        }

        .section {
            margin-top: 22px;
        }

        .section h2 {
            margin: 0 0 10px;
            font-size: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        .stats-table th,
        .stats-table td {
            border: 1px solid #ddd;
            padding: 9px 10px;
            text-align: left;
        }

        .stats-table th {
            background: #eee;
        }

        .stats-table td.num {
            text-align: right;
            white-space: nowrap;
        }

        .draft-box {
            margin-top: 16px;
        }

        textarea {
            width: 100%;
            min-height: 220px;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;
            line-height: 1.7;
            resize: vertical;
        }

        .button-row {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        button {
            padding: 9px 16px;
            background: #333;
            color: #fff;
            border: 1px solid #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        button:hover {
            background: #111;
        }

        .note {
            color: #666;
            font-size: 14px;
            line-height: 1.7;
        }

        .copy-status {
            color: #00897b;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>X投稿用下書き</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="../index.php" target="_blank">公開トップ</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <div class="section">
        <h2>現在のDB件数</h2>
        <table class="stats-table">
            <tbody>
                <tr>
                    <th>登録雑誌数</th>
                    <td class="num"><?= h(number_format((int)$current_stats['magazine_count'])) ?>誌</td>
                </tr>
                <tr>
                    <th>登録雑誌号数</th>
                    <td class="num"><?= h(number_format((int)$current_stats['issue_count'])) ?>号</td>
                </tr>
                <tr>
                    <th>登録作品数</th>
                    <td class="num"><?= h(number_format((int)$current_stats['content_count'])) ?>件</td>
                </tr>
                <tr>
                    <th>登録人物数</th>
                    <td class="num"><?= h(number_format((int)$current_stats['creator_count'])) ?>人</td>
                </tr>
            </tbody>
        </table>

        <form method="post" action="x_post_draft.php" class="button-row">
            <input type="hidden" name="action" value="save_snapshot">
            <button type="submit">本日0:00のスナップショットとして保存</button>
        </form>
    </div>

    <div class="section">
        <h2>最新スナップショット件数</h2>
        <?php if ($using_current_stats_for_draft): ?>
            <p class="note">まだスナップショットがありません。現在のDB件数を使った仮下書きを表示していますが、0:00時点で保存済みの下書きではありません。前回比は±0で表示しています。</p>
        <?php elseif (!$previous_snapshot): ?>
            <p class="note">前回データがないため、差分は±0で表示しています。</p>
        <?php endif; ?>

        <table class="stats-table">
            <tbody>
                <tr>
                    <th>snapshot_at</th>
                    <td class="num"><?= h(format_snapshot_datetime($latest_snapshot['snapshot_at'])) ?></td>
                </tr>
                <tr>
                    <th>登録雑誌数</th>
                    <td class="num"><?= h(number_format((int)$latest_snapshot['magazine_count'])) ?>誌（<?= h(diff_label($latest_snapshot['magazine_count'], ($previous_snapshot ?: $latest_snapshot)['magazine_count'])) ?>）</td>
                </tr>
                <tr>
                    <th>登録雑誌号数</th>
                    <td class="num"><?= h(number_format((int)$latest_snapshot['issue_count'])) ?>号（<?= h(diff_label($latest_snapshot['issue_count'], ($previous_snapshot ?: $latest_snapshot)['issue_count'])) ?>）</td>
                </tr>
                <tr>
                    <th>登録作品数</th>
                    <td class="num"><?= h(number_format((int)$latest_snapshot['content_count'])) ?>件（<?= h(diff_label($latest_snapshot['content_count'], ($previous_snapshot ?: $latest_snapshot)['content_count'])) ?>）</td>
                </tr>
                <tr>
                    <th>登録人物数</th>
                    <td class="num"><?= h(number_format((int)$latest_snapshot['creator_count'])) ?>人（<?= h(diff_label($latest_snapshot['creator_count'], ($previous_snapshot ?: $latest_snapshot)['creator_count'])) ?>）</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>前回スナップショット件数</h2>
        <?php if ($previous_snapshot): ?>
            <table class="stats-table">
                <tbody>
                    <tr>
                        <th>snapshot_at</th>
                        <td class="num"><?= h(format_snapshot_datetime($previous_snapshot['snapshot_at'])) ?></td>
                    </tr>
                    <tr>
                        <th>登録雑誌数</th>
                        <td class="num"><?= h(number_format((int)$previous_snapshot['magazine_count'])) ?>誌</td>
                    </tr>
                    <tr>
                        <th>登録雑誌号数</th>
                        <td class="num"><?= h(number_format((int)$previous_snapshot['issue_count'])) ?>号</td>
                    </tr>
                    <tr>
                        <th>登録作品数</th>
                        <td class="num"><?= h(number_format((int)$previous_snapshot['content_count'])) ?>件</td>
                    </tr>
                    <tr>
                        <th>登録人物数</th>
                        <td class="num"><?= h(number_format((int)$previous_snapshot['creator_count'])) ?>人</td>
                    </tr>
                </tbody>
            </table>
        <?php else: ?>
            <p class="note">前回スナップショットはまだありません。</p>
        <?php endif; ?>
    </div>

    <div class="draft-box">
        <h2>X投稿用下書き</h2>
        <textarea id="draftText" readonly><?= h($draft_text) ?></textarea>

        <div class="button-row">
            <button type="button" id="copyButton">コピー</button>
            <span class="copy-status" id="copyStatus" aria-live="polite"></span>
        </div>

        <p class="note">この内容をコピーしてXに投稿してください。X API連携や自動投稿は行いません。</p>
    </div>

</div>

<script>
const copyButton = document.getElementById('copyButton');
const draftText = document.getElementById('draftText');
const copyStatus = document.getElementById('copyStatus');

if (copyButton && draftText) {
    copyButton.addEventListener('click', async () => {
        try {
            draftText.focus();
            draftText.select();

            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(draftText.value);
            } else {
                document.execCommand('copy');
            }

            if (copyStatus) {
                copyStatus.textContent = 'コピーしました。';
            }
        } catch (e) {
            if (copyStatus) {
                copyStatus.textContent = 'コピーできませんでした。textareaから手動でコピーしてください。';
            }
        }
    });
}
</script>

</body>
</html>
