<?php
// admin/episode_formats.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function episode_format_column_exists($pdo, $column_name) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'episode_formats'
          AND column_name = :column_name
    ");
    $stmt->execute([
        ':column_name' => $column_name
    ]);
    $row = $stmt->fetch();

    return ((int)($row['cnt'] ?? 0) > 0);
}

function normalize_number_style($value) {
    $value = (string)$value;
    $allowed = ['', 'roman', 'kanji', 'old_kanji'];

    return in_array($value, $allowed, true) ? $value : '';
}

function number_to_kanji($number) {
    $number = (int)$number;
    if ($number <= 0) {
        return (string)$number;
    }

    $digits = [0 => '', 1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九'];
    $units = [100 => '百', 10 => '十'];
    $result = '';
    $remaining = $number;

    foreach ($units as $unit_value => $unit_label) {
        $digit = intdiv($remaining, $unit_value);
        if ($digit > 0) {
            if ($digit > 1) {
                $result .= $digits[$digit];
            }
            $result .= $unit_label;
            $remaining = $remaining % $unit_value;
        }
    }

    if ($remaining > 0) {
        $result .= $digits[$remaining];
    }

    return $result;
}

function number_to_old_kanji($number) {
    $number = (int)$number;
    if ($number <= 0) {
        return (string)$number;
    }

    $digits = [0 => '', 1 => '壱', 2 => '弐', 3 => '参', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九'];
    $units = [100 => '百', 10 => '拾'];
    $result = '';
    $remaining = $number;

    foreach ($units as $unit_value => $unit_label) {
        $digit = intdiv($remaining, $unit_value);
        if ($digit > 0) {
            if ($digit > 1) {
                $result .= $digits[$digit];
            }
            $result .= $unit_label;
            $remaining = $remaining % $unit_value;
        }
    }

    if ($remaining > 0) {
        $result .= $digits[$remaining];
    }

    return $result;
}

function number_to_roman($number) {
    $number = (int)$number;
    $unicode_roman = [1 => 'Ⅰ', 2 => 'Ⅱ', 3 => 'Ⅲ', 4 => 'Ⅳ', 5 => 'Ⅴ', 6 => 'Ⅵ', 7 => 'Ⅶ', 8 => 'Ⅷ', 9 => 'Ⅸ', 10 => 'Ⅹ', 11 => 'Ⅺ', 12 => 'Ⅻ'];
    if (isset($unicode_roman[$number])) {
        return $unicode_roman[$number];
    }
    if ($number <= 0) {
        return (string)$number;
    }

    $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
    $result = '';
    foreach ($map as $value => $label) {
        while ($number >= $value) {
            $result .= $label;
            $number -= $value;
        }
    }

    return $result;
}

function format_episode_number_value($episode_no, $number_style) {
    $episode_no = (string)$episode_no;
    $number_style = normalize_number_style($number_style);

    if (!preg_match('/^[0-9]+$/', $episode_no)) {
        return $episode_no;
    }

    if ($number_style === 'kanji') {
        return number_to_kanji($episode_no);
    }
    if ($number_style === 'old_kanji') {
        return number_to_old_kanji($episode_no);
    }
    if ($number_style === 'roman') {
        return number_to_roman($episode_no);
    }

    return $episode_no;
}

$message = '';
$error = '';
$has_number_style = episode_format_column_exists($pdo, 'number_style');
$number_style_select = $has_number_style ? 'ef.number_style' : 'NULL AS number_style';

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $format_id = isset($_POST['format_id']) ? (int)$_POST['format_id'] : 0;

    if ($format_id <= 0) {
        $error = '話数フォーマットIDが不正です。';
    } else {
        try {
            // 目次で使用中か確認
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM issue_contents
                WHERE episode_format_id = :episode_format_id
            ");
            $stmt->execute([
                ':episode_format_id' => $format_id
            ]);
            $row = $stmt->fetch();

            if ((int)$row['cnt'] > 0) {
                $error = 'この話数フォーマットは目次データに使用されているため削除できません。先に目次側を修正してください。';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM episode_formats
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $format_id
                ]);

                $message = '話数フォーマットを削除しました。';
            }
        } catch (Exception $e) {
            $error = '削除エラー：' . $e->getMessage();
        }
    }
}

// 一覧取得
$sql = "
    SELECT
        ef.id,
        ef.name,
        ef.prefix,
        ef.suffix,
        {$number_style_select},
        ef.note,
        ef.created_at,
        ef.updated_at,
        COUNT(ic.id) AS content_count
    FROM episode_formats ef
    LEFT JOIN issue_contents ic ON ef.id = ic.episode_format_id
    GROUP BY ef.id
    ORDER BY ef.id ASC
";

$stmt = $pdo->query($sql);
$formats = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>話数フォーマット一覧 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 1150px;
        }

        .num {
            width: 90px;
            text-align: right;
        }

        .actions {
            width: 170px;
        }

        .preview {
            font-weight: bold;
        }

        .note {
            color: #b00020;
            font-size: 13px;
        }

        @media (max-width: 800px) {
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
                width: 120px;
                color: #555;
            }

            td.name::before { content: "表示名"; }
            td.preview-cell::before { content: "表示例"; }
            td.num::before { content: "使用件数"; }
            td.actions::before { content: "操作"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>話数フォーマット一覧</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="episode_format_add.php">話数フォーマット登録</a>
        <a href="content_add.php">目次登録</a>
        <a href="content_list.php">目次一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="count">
        <?= h(count($formats)) ?>件
    </div>

    <div class="filter-box">
        <input type="text" id="formatFilter" placeholder="表示名・prefix・suffix・備考で絞り込み">
    </div>

    <?php if (empty($formats)): ?>

        <p>話数フォーマットがありません。</p>

    <?php else: ?>

        <table id="formatsTable">
            <thead>
                <tr>
                    <th>表示名</th>
                    <th>prefix</th>
                    <th>suffix</th>
                    <th>番号形式</th>
                    <th>表示例</th>
                    <th class="num">使用件数</th>
                    <th>備考</th>
                    <th class="actions">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($formats as $row): ?>
                <?php
                    $can_delete = ((int)$row['content_count'] === 0);
                    $number_style = normalize_number_style($row['number_style'] ?? '');
                    $style_labels = [
                        '' => 'アラビア数字',
                        'roman' => 'ローマ数字',
                        'kanji' => '漢数字',
                        'old_kanji' => '旧漢数字',
                    ];
                    $preview = (string)($row['prefix'] ?? '') . format_episode_number_value('1', $number_style) . (string)($row['suffix'] ?? '');
                ?>
                <tr>
                    <td class="name">
                        <strong><?= h($row['name']) ?></strong>
                        <div class="small">
                            ID：<?= h($row['id']) ?><br>
                            作成：<?= h($row['created_at']) ?><br>
                            更新：<?= h($row['updated_at']) ?>
                        </div>
                    </td>

                    <td><?= h($row['prefix'] ?? '') ?></td>

                    <td><?= h($row['suffix'] ?? '') ?></td>

                    <td><?= h($style_labels[$number_style] ?? $style_labels['']) ?></td>

                    <td class="preview-cell">
                        <span class="preview"><?= h($preview) ?></span>
                    </td>

                    <td class="num"><?= h($row['content_count']) ?></td>

                    <td>
                        <?php if (!empty($row['note'])): ?>
                            <span class="note"><?= h($row['note']) ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="actions">
                        <a class="button-link" href="episode_format_edit.php?id=<?= h($row['id']) ?>">編集</a>

                        <form method="post" action="episode_formats.php" style="display:inline;"
                              onsubmit="return confirm('この話数フォーマットを削除しますか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="format_id" value="<?= h($row['id']) ?>">
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

</div>

<script>
const filterInput = document.getElementById('formatFilter');
const table = document.getElementById('formatsTable');

if (filterInput && table) {
    filterInput.addEventListener('input', () => {
        const keyword = filterInput.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = (!keyword || text.includes(keyword)) ? '' : 'none';
        });
    });
}
</script>

</body>
</html>
