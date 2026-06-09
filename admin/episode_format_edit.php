<?php
// admin/episode_format_edit.php

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

$format_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $format_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($format_id <= 0) {
    exit('話数フォーマットIDが指定されていません。');
}

$message = '';
$error = '';
$has_number_style = episode_format_column_exists($pdo, 'number_style');
$number_style_select = $has_number_style ? 'number_style' : 'NULL AS number_style';

// 現在データ取得
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        prefix,
        suffix,
        {$number_style_select},
        note,
        created_at,
        updated_at
    FROM episode_formats
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([
    ':id' => $format_id
]);
$format = $stmt->fetch();

if (!$format) {
    exit('指定された話数フォーマットが見つかりません。');
}

$name = $format['name'];
$prefix = $format['prefix'] ?? '';
$suffix = $format['suffix'] ?? '';
$number_style = normalize_number_style($format['number_style'] ?? '');
$note = $format['note'] ?? '';

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $number_style = normalize_number_style($_POST['number_style'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($name === '') {
        $error = '表示名を入力してください。';
    } else {
        try {
            // 重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM episode_formats
                WHERE name = :name
                  AND id <> :id
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $name,
                ':id' => $format_id
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = '同じ表示名の話数フォーマットがすでに登録されています。';
            } else {
                $number_style_update = $has_number_style ? ",\n                        number_style = :number_style" : '';
                $sql_update = "
                    UPDATE episode_formats
                    SET
                        name = :name,
                        prefix = :prefix,
                        suffix = :suffix,
                        note = :note{$number_style_update}
                    WHERE id = :id
                ";
                $stmt = $pdo->prepare($sql_update);
                $params = [
                    ':name' => $name,
                    ':prefix' => ($prefix !== '') ? $prefix : null,
                    ':suffix' => ($suffix !== '') ? $suffix : null,
                    ':note' => ($note !== '') ? $note : null,
                    ':id' => $format_id
                ];

                if ($has_number_style) {
                    $params[':number_style'] = ($number_style !== '') ? $number_style : null;
                }

                $stmt->execute($params);

                $message = '話数フォーマットを更新しました。';

                // 再取得
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        name,
                        prefix,
                        suffix,
                        {$number_style_select},
                        note,
                        created_at,
                        updated_at
                    FROM episode_formats
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':id' => $format_id
                ]);
                $format = $stmt->fetch();

                $name = $format['name'];
                $prefix = $format['prefix'] ?? '';
                $suffix = $format['suffix'] ?? '';
                $number_style = normalize_number_style($format['number_style'] ?? '');
                $note = $format['note'] ?? '';
            }
        } catch (Exception $e) {
            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 使用中の目次一覧
$stmt = $pdo->prepare("
    SELECT
        ic.id AS content_id,
        ic.issue_id,
        ic.title_main,
        ic.episode_no,
        ic.episode_title,
        ic.sort_order,
        mi.issue_label,
        mi.issue_year,
        mi.issue_no,
        m.name AS magazine_name,
        p.name AS publisher_name
    FROM issue_contents ic
    JOIN magazine_issues mi ON ic.issue_id = mi.id
    JOIN magazines m ON mi.magazine_id = m.id
    JOIN publishers p ON m.publisher_id = p.id
    WHERE ic.episode_format_id = :episode_format_id
    ORDER BY
        mi.issue_year ASC,
        CASE
            WHEN mi.issue_no REGEXP '^[0-9]+$' THEN CAST(mi.issue_no AS UNSIGNED)
            ELSE 999999
        END ASC,
        mi.id ASC,
        ic.sort_order ASC
");
$stmt->execute([
    ':episode_format_id' => $format_id
]);
$contents = $stmt->fetchAll();

$preview = (string)$prefix . format_episode_number_value('1', $number_style) . (string)$suffix;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>話数フォーマット編集 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .container {
            max-width: 1000px;
        }

        h2 {
            margin-top: 28px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .info,
        .preview-box {
            padding: 12px;
            background: #f3f3f3;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-radius: 4px;
            line-height: 1.7;
            color: #555;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .empty {
            color: #666;
        }

        .preview {
            font-weight: bold;
        }

        .order {
            width: 70px;
            text-align: center;
        }

        .episode {
            width: 100px;
            text-align: center;
        }

        @media (max-width: 800px) {
            body {
                margin: 8px;
            }

            .container {
                padding: 12px;
            }

            .form-grid {
                display: block;
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
                width: 100px;
                color: #555;
            }

            td.issue::before { content: "雑誌号"; }
            td.order::before { content: "掲載順"; }
            td.title::before { content: "作品"; }
            td.episode::before { content: "話数"; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1>話数フォーマット編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="episode_formats.php">話数フォーマット一覧</a>
        <a href="episode_format_add.php">話数フォーマット登録</a>
        <a href="content_add.php">目次登録</a>
        <a href="content_list.php">目次一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="episode_formats.php">話数フォーマット一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="info">
        ID：<?= h($format['id']) ?><br>
        作成日時：<?= h($format['created_at']) ?><br>
        更新日時：<?= h($format['updated_at']) ?>
    </div>

    <div class="preview-box">
        表示例：<span class="preview"><?= h($preview) ?></span>
    </div>

    <form method="post" action="episode_format_edit.php?id=<?= h($format_id) ?>">

        <input type="hidden" name="id" value="<?= h($format_id) ?>">

        <div class="form-row">
            <label>
                表示名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：第○話">
            <div class="help">管理画面で選ぶ時の名前です。数字部分は「1」で表示例を確認できます。</div>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>prefix</label>
                <input type="text" name="prefix" value="<?= h($prefix) ?>" placeholder="例：第 / ROUND / No.">
                <div class="help">話数の前につける文字です。</div>
            </div>

            <div class="form-row">
                <label>suffix</label>
                <input type="text" name="suffix" value="<?= h($suffix) ?>" placeholder="例：話 / 回">
                <div class="help">話数の後ろにつける文字です。</div>
            </div>
        </div>

        <div class="form-row">
            <label>番号表示形式</label>
            <select name="number_style">
                <option value="" <?= ($number_style === '') ? 'selected' : '' ?>>アラビア数字（1,2,3...）</option>
                <option value="roman" <?= ($number_style === 'roman') ? 'selected' : '' ?>>ローマ数字（Ⅰ,Ⅱ,Ⅲ...）</option>
                <option value="kanji" <?= ($number_style === 'kanji') ? 'selected' : '' ?>>漢数字（一,二,三...）</option>
                <option value="old_kanji" <?= ($number_style === 'old_kanji') ? 'selected' : '' ?>>旧漢数字（壱,弐,参...）</option>
            </select>
            <?php if (!$has_number_style): ?>
                <div class="help">
                    number_style カラムが無いため、この項目はまだ保存されません。必要なSQL:
                    <code>ALTER TABLE episode_formats ADD COLUMN number_style VARCHAR(20) NULL DEFAULT NULL AFTER format_text;</code>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：通常の話数表示に使用"><?= h($note) ?></textarea>
        </div>

        <button type="submit">更新する</button>

    </form>

    <h2>この話数フォーマットを使用中の目次</h2>

    <?php if (empty($contents)): ?>

        <p class="empty">この話数フォーマットを使用している目次データはありません。</p>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>雑誌号</th>
                    <th class="order">掲載順</th>
                    <th>作品</th>
                    <th class="episode">話数</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contents as $row): ?>
                <tr>
                    <td class="issue">
                        <a href="content_edit.php?id=<?= h($row['content_id']) ?>">
                            <?= h($row['magazine_name']) ?>
                            <?= h($row['issue_label']) ?>
                        </a>
                        <div class="small">
                            <?= h($row['publisher_name']) ?>
                            <?php if (!empty($row['issue_no'])): ?>
                                / <?= h($row['issue_no']) ?>号
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="order"><?= h($row['sort_order']) ?></td>

                    <td class="title">
                        <?= h($row['title_main']) ?>
                        <?php if (!empty($row['episode_title'])): ?>
                            <div class="small"><?= h($row['episode_title']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="episode">
                        <?php if ((string)($row['episode_no'] ?? '') !== ''): ?>
                            <?= h((string)$prefix . format_episode_number_value($row['episode_no'], $number_style) . (string)$suffix) ?>
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
