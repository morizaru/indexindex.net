<?php
// admin/creator_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function work_exists(PDO $pdo, $work_id) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM works
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $work_id
    ]);

    return (bool)$stmt->fetch();
}

$stmt = $pdo->query("
    SELECT
        id,
        title,
        title_sub
    FROM works
    ORDER BY title ASC, title_sub ASC, id ASC
");
$work_options = $stmt->fetchAll();

$message = '';
$error = '';

$name = '';
$name_kana = '';
$name_search = '';
$debut_work_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $name_kana = trim($_POST['name_kana'] ?? '');
    $name_search = trim($_POST['name_search'] ?? '');
    $debut_work_id = trim($_POST['debut_work_id'] ?? '');

    if ($name === '') {
        $error = '人物名を入力してください。';
    } elseif (mb_strlen($name_search, 'UTF-8') > 255) {
        $error = '検索用表記ゆれは255文字以内で入力してください。';
    } elseif ($debut_work_id !== '' && !preg_match('/^[0-9]+$/', $debut_work_id)) {
        $error = 'デビュー作IDは数値で入力してください。';
    } else {
        try {
            $debut_work_id_value = null;
            if ($debut_work_id !== '') {
                $debut_work_id_value = (int)$debut_work_id;

                if (!work_exists($pdo, $debut_work_id_value)) {
                    $error = '指定されたデビュー作IDの作品が見つかりません。';
                }
            }

            // 重複チェック
            if ($error === '') {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM creators
                    WHERE name = :name
                    LIMIT 1
                ");
                $stmt->execute([
                    ':name' => $name
                ]);
                $exists = $stmt->fetch();

                if ($exists) {
                    $error = 'この人物はすでに登録されています。';
                }
            }

            if ($error === '') {
                $stmt = $pdo->prepare("
                    INSERT INTO creators (
                        name,
                        name_kana,
                        name_search,
                        debut_work_id
                    ) VALUES (
                        :name,
                        :name_kana,
                        :name_search,
                        :debut_work_id
                    )
                ");

                $stmt->execute([
                    ':name' => $name,
                    ':name_kana' => ($name_kana !== '') ? $name_kana : null,
                    ':name_search' => ($name_search !== '') ? $name_search : null,
                    ':debut_work_id' => $debut_work_id_value,
                ]);

                $new_id = (int)$pdo->lastInsertId();

                $message = '人物を登録しました。ID：' . $new_id;

                $name = '';
                $name_kana = '';
                $name_search = '';
                $debut_work_id = '';
            }
        } catch (Exception $e) {
            $error = '登録エラー：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>人物登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .work-select-tools {
            display: grid;
            gap: 6px;
            max-width: 520px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>人物登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="creators.php">人物一覧</a>
        <a href="content_add.php">目次登録</a>
        <a href="../creators.php">公開：人物一覧</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="creators.php">人物一覧へ</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="creator_add.php">

        <div class="form-row">
            <label>
                人物名 <span class="required">必須</span>
            </label>
            <input type="text" name="name" value="<?= h($name) ?>" placeholder="例：ちばてつや">
        </div>

        <div class="form-row">
            <label>人物名かな</label>
            <input type="text" name="name_kana" value="<?= h($name_kana) ?>" placeholder="例：ちばてつや / チバテツヤ">
            <div class="help">任意。検索・並び替え用に使えます。</div>
        </div>

        <div class="form-row">
            <label>検索用表記ゆれ</label>
            <input type="text" name="name_search" value="<?= h($name_search) ?>" placeholder="例：髙橋よしひろ / 渡邉まさこ 渡邊まさこ">
            <div class="help">任意。表示名とは別に、検索で拾いたい表記をスペース区切りで入力します。</div>
        </div>

        <div class="form-row">
            <label>デビュー作</label>
            <div class="work-select-tools">
                <input type="search" class="work-search-input" placeholder="作品名で絞り込み">
                <select name="debut_work_id" class="work-select">
                    <option value="">未登録</option>
                    <?php foreach ($work_options as $work_option): ?>
                        <?php
                            $work_label = (string)$work_option['title'];
                            if (!empty($work_option['title_sub'])) {
                                $work_label .= ' / ' . (string)$work_option['title_sub'];
                            }
                        ?>
                        <option
                            value="<?= h($work_option['id']) ?>"
                            data-search="<?= h($work_option['id'] . ' ' . $work_label) ?>"
                            <?= ((string)$debut_work_id === (string)$work_option['id']) ? 'selected' : '' ?>
                        >
                            <?= h($work_label) ?>（ID: <?= h($work_option['id']) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="help">任意。作品名で絞り込んで選択できます。未登録にする場合は「未登録」を選んでください。</div>
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
<script>
document.querySelectorAll('.work-select-tools').forEach((wrap) => {
    const input = wrap.querySelector('.work-search-input');
    const select = wrap.querySelector('.work-select');
    const options = Array.from(select.options);

    if (!input || !select) {
        return;
    }

    input.addEventListener('input', () => {
        const keyword = input.value.trim().toLowerCase();

        options.forEach((option) => {
            if (option.value === '') {
                option.hidden = false;
                option.disabled = false;
                option.style.display = '';
                return;
            }

            const isHidden = keyword !== '' && !option.dataset.search.toLowerCase().includes(keyword);
            option.hidden = isHidden;
            option.disabled = isHidden;
            option.style.display = isHidden ? 'none' : '';
        });
    });
});
</script>
</body>
</html>
