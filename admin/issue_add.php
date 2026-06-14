<?php
// admin/issue_add.php

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/config.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validate_issue_number_field($value, $label) {
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[0-9]+$/', $value)) {
        return $label . 'は数字のみで入力してください。';
    }

    $number = (int)$value;
    if ($number < 0 || $number > 99999) {
        return $label . 'は0〜99999の範囲で入力してください。';
    }

    return '';
}

function issue_number_db_value($value) {
    return ($value !== '') ? (int)$value : null;
}

function issue_status_options() {
    return [
        'approved' => '公開',
        'pending' => '保留',
        'hidden' => '非表示',
        'in_progress' => '登録途中',
        'verified' => '現物確認済',
    ];
}

function table_has_column($pdo, $table, $column) {
    static $cache = [];

    if (!isset($cache[$table])) {
        $allowed_tables = ['magazine_issues'];
        if (!in_array($table, $allowed_tables, true)) {
            return false;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cache[$table] = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }

    return isset($cache[$table][$column]);
}

$message = '';
$error = '';

$publisher_name = '';
$magazine_name = '';
$magazine_id = 0;
$magazine_code = '';

$issue_label = '';
$issue_label_special = '';
$issue_year = '';
$issue_vol = '';
$issue_no = '';
$issue_full_vol = '';

$publication_date = '';
$publication_year = '';
$publication_month = '';
$publication_day = '';

$release_date = '';
$is_digital = 0;
$note = '';
$status = 'approved';
$source_image_note = '';

// 出典画像保存先
$upload_dir = __DIR__ . '/../uploads/source_images/';
$upload_url_base = 'uploads/source_images/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publisher_name       = trim($_POST['publisher_name'] ?? '');
    $magazine_name        = trim($_POST['magazine_name'] ?? '');
    $magazine_id          = isset($_POST['magazine_id']) ? (int)$_POST['magazine_id'] : 0;
    $magazine_code        = trim($_POST['magazine_code'] ?? '');

    $issue_label          = trim($_POST['issue_label'] ?? '');
    $issue_label_special  = trim($_POST['issue_label_special'] ?? '');
    $issue_year           = trim($_POST['issue_year'] ?? '');
    $issue_vol            = trim($_POST['issue_vol'] ?? '');
    $issue_no             = trim($_POST['issue_no'] ?? '');
    $issue_full_vol       = trim($_POST['issue_full_vol'] ?? '');

    $publication_date     = trim($_POST['publication_date'] ?? '');
    $publication_year     = trim($_POST['publication_year'] ?? '');
    $publication_month    = trim($_POST['publication_month'] ?? '');
    $publication_day      = trim($_POST['publication_day'] ?? '');

    $release_date         = trim($_POST['release_date'] ?? '');
    $is_digital           = isset($_POST['is_digital']) ? 1 : 0;
    $note                 = trim($_POST['note'] ?? '');
    $status               = trim($_POST['status'] ?? 'approved');
    $source_image_note    = trim($_POST['source_image_note'] ?? '');

    if ($publisher_name === '') {
        $error = '出版社名を入力してください。';
    } elseif ($magazine_name === '') {
        $error = '雑誌名を入力してください。';
    } elseif ($issue_label === '' && $issue_label_special === '') {
        $error = '号数表示、または号数表示（増刊用）を入力してください。';
    } elseif (($number_error = validate_issue_number_field($issue_vol, '巻数')) !== '') {
        $error = $number_error;
    } elseif (($number_error = validate_issue_number_field($issue_no, '号数')) !== '') {
        $error = $number_error;
    } elseif (($number_error = validate_issue_number_field($issue_full_vol, '通巻')) !== '') {
        $error = $number_error;
    } elseif (!array_key_exists($status, issue_status_options())) {
        $error = '公開状態が正しくありません。';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. 出版社を取得、なければ追加
            $stmt = $pdo->prepare("
                SELECT id
                FROM publishers
                WHERE name = :name
                LIMIT 1
            ");
            $stmt->execute([
                ':name' => $publisher_name
            ]);
            $publisher = $stmt->fetch();

            if ($publisher) {
                $publisher_id = (int)$publisher['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO publishers (
                        name
                    ) VALUES (
                        :name
                    )
                ");
                $stmt->execute([
                    ':name' => $publisher_name
                ]);
                $publisher_id = (int)$pdo->lastInsertId();
            }

            // 2. 雑誌を取得、なければ追加
            $magazine = false;

            if ($magazine_id > 0) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM magazines
                    WHERE id = :id
                      AND publisher_id = :publisher_id
                      AND name = :name
                    LIMIT 1
                ");
                $stmt->execute([
                    ':id' => $magazine_id,
                    ':publisher_id' => $publisher_id,
                    ':name' => $magazine_name
                ]);
                $magazine = $stmt->fetch();
            }

            if (!$magazine) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM magazines
                    WHERE publisher_id = :publisher_id
                      AND name = :name
                    LIMIT 1
                ");
                $stmt->execute([
                    ':publisher_id' => $publisher_id,
                    ':name' => $magazine_name
                ]);
                $magazine = $stmt->fetch();
            }

            if ($magazine) {
                $magazine_id = (int)$magazine['id'];

                // 雑誌コードが入力されていれば雑誌マスタを更新
                if ($magazine_code !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE magazines
                        SET magazine_code = :magazine_code
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':magazine_code' => $magazine_code,
                        ':id' => $magazine_id
                    ]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO magazines (
                        publisher_id,
                        name,
                        magazine_code
                    ) VALUES (
                        :publisher_id,
                        :name,
                        :magazine_code
                    )
                ");
                $stmt->execute([
                    ':publisher_id' => $publisher_id,
                    ':name' => $magazine_name,
                    ':magazine_code' => ($magazine_code !== '') ? $magazine_code : null
                ]);
                $magazine_id = (int)$pdo->lastInsertId();
            }

            // issue_label と issue_label_special は別々に保存する
            // issue_label は NOT NULL のため、未入力時は空文字として保存
            $save_issue_label = $issue_label;
            $save_issue_label_special = ($issue_label_special !== '') ? $issue_label_special : null;

            // 3. 同じ雑誌・同じ号数表示の重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM magazine_issues
                WHERE magazine_id = :magazine_id
                  AND issue_label = :issue_label
                LIMIT 1
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id,
                ':issue_label' => $save_issue_label
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                throw new Exception('同じ雑誌の同じ号数表示がすでに登録されています。');
            }

            // 4. 雑誌号を追加
            $insert_columns = [
                'magazine_id',
                'issue_label',
                'issue_label_special',
                'issue_year',
                'issue_no',
                'publication_date',
                'publication_year',
                'publication_month',
                'publication_day',
                'release_date',
                'is_digital',
                'note',
                'status',
            ];

            $insert_params = [
                ':magazine_id' => $magazine_id,
                ':issue_label' => $save_issue_label,
                ':issue_label_special' => $save_issue_label_special,
                ':issue_year' => ($issue_year !== '') ? (int)$issue_year : null,
                ':issue_no' => issue_number_db_value($issue_no),
                ':publication_date' => ($publication_date !== '') ? $publication_date : null,
                ':publication_year' => ($publication_year !== '') ? (int)$publication_year : null,
                ':publication_month' => ($publication_month !== '') ? (int)$publication_month : null,
                ':publication_day' => ($publication_day !== '') ? (int)$publication_day : null,
                ':release_date' => ($release_date !== '') ? $release_date : null,
                ':is_digital' => $is_digital,
                ':note' => ($note !== '') ? $note : null,
                ':status' => $status
            ];

            if (table_has_column($pdo, 'magazine_issues', 'issue_vol')) {
                $insert_columns[] = 'issue_vol';
                $insert_params[':issue_vol'] = issue_number_db_value($issue_vol);
            }

            if (table_has_column($pdo, 'magazine_issues', 'issue_full_vol')) {
                $insert_columns[] = 'issue_full_vol';
                $insert_params[':issue_full_vol'] = issue_number_db_value($issue_full_vol);
            }

            $insert_placeholders = [];
            foreach ($insert_columns as $column) {
                $insert_placeholders[] = ':' . $column;
            }

            $stmt = $pdo->prepare("
                INSERT INTO magazine_issues (
                    " . implode(",\n                    ", $insert_columns) . "
                ) VALUES (
                    " . implode(",\n                    ", $insert_placeholders) . "
                )
            ");

            $stmt->execute($insert_params);

            $issue_id = (int)$pdo->lastInsertId();

            // 5. 出典画像が選択されている場合だけ追加登録
            if (isset($_FILES['source_image']) && $_FILES['source_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['source_image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('出典画像アップロードでエラーが発生しました。');
                }

                $file = $_FILES['source_image'];

                // サイズ制限：5MB
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new Exception('出典画像は5MB以下にしてください。');
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                if (!isset($allowed[$mime])) {
                    throw new Exception('出典画像は JPG / PNG / WEBP のみ登録できます。');
                }

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $ext = $allowed[$mime];

                $file_name = 'issue_' . $issue_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $save_path = $upload_dir . $file_name;
                $db_path = $upload_url_base . $file_name;

                if (!move_uploaded_file($file['tmp_name'], $save_path)) {
                    throw new Exception('出典画像を保存できませんでした。');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO source_images (
                        issue_id,
                        image_path,
                        note
                    ) VALUES (
                        :issue_id,
                        :image_path,
                        :note
                    )
                ");

                $stmt->execute([
                    ':issue_id' => $issue_id,
                    ':image_path' => $db_path,
                    ':note' => ($source_image_note !== '') ? $source_image_note : null,
                ]);
            }

            $pdo->commit();

            $message = '雑誌号を登録しました。';

            // 入力欄を初期化
            $issue_label = '';
            $issue_label_special = '';
            $issue_year = '';
            $issue_vol = '';
            $issue_no = '';
            $issue_full_vol = '';
            $publication_date = '';
            $publication_year = '';
            $publication_month = '';
            $publication_day = '';
            $release_date = '';
            $is_digital = 0;
            $note = '';
            $status = 'approved';
            $source_image_note = '';
            $magazine_id = 0;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '登録エラー：' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌号登録 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 980px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
            line-height: 1.6;
        }

        .suggest-wrap {
            position: relative;
        }

        .suggest-input-row {
            display: flex;
            gap: 6px;
            align-items: stretch;
        }

        .suggest-input-row input[type="text"] {
            flex: 1;
            min-width: 0;
        }

        .suggest-toggle {
            width: 42px;
            flex: 0 0 42px;
            padding: 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f7f7f7;
            color: #222;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
        }

        .suggest-toggle:hover,
        .suggest-toggle:focus {
            background: #eee;
            outline: none;
        }

        .suggest-list {
            display: none;
            position: absolute;
            z-index: 20;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #bbb;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .suggest-list.is-open {
            display: block;
        }

        .suggest-item {
            width: 100%;
            display: block;
            box-sizing: border-box;
            padding: 9px 12px;
            border: 0;
            border-bottom: 1px solid #eee;
            background: #fff;
            color: #222;
            text-align: left;
            font-size: 14px;
            line-height: 1.5;
            cursor: pointer;
        }

        .suggest-item:last-child {
            border-bottom: 0;
        }

        .suggest-item:hover,
        .suggest-item:focus {
            background: #f0f6ff;
            outline: none;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.js-issue-number').forEach(function (input) {
                input.addEventListener('input', function () {
                    input.value = input.value
                        .replace(/[０-９]/g, function (char) {
                            return String.fromCharCode(char.charCodeAt(0) - 0xFEE0);
                        })
                        .replace(/[^0-9]/g, '');
                });
            });

            const publisherInput = document.getElementById('publisherNameInput');
            const magazineInput = document.getElementById('magazineNameInput');
            const magazineIdInput = document.getElementById('magazineIdInput');
            const publisherSuggest = document.getElementById('publisherSuggest');
            const magazineSuggest = document.getElementById('magazineSuggest');
            const publisherToggle = document.getElementById('publisherSuggestToggle');
            const magazineToggle = document.getElementById('magazineSuggestToggle');

            function closeSuggest(list) {
                if (list) {
                    list.classList.remove('is-open');
                    list.innerHTML = '';
                }
            }

            function renderSuggest(list, items, renderItem) {
                list.innerHTML = '';

                if (!items.length) {
                    closeSuggest(list);
                    return;
                }

                items.forEach(function (item) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggest-item';
                    button.textContent = renderItem(item);
                    button.addEventListener('click', function () {
                        item.onSelect();
                        closeSuggest(list);
                    });
                    list.appendChild(button);
                });

                list.classList.add('is-open');
            }

            function fetchJson(url, callback) {
                fetch(url, {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('request failed');
                        }
                        return response.json();
                    })
                    .then(callback)
                    .catch(function () {});
            }

            let publisherTimer = null;
            let magazineTimer = null;

            function loadPublishers() {
                const q = publisherInput.value.trim();
                fetchJson('ajax_publishers.php?q=' + encodeURIComponent(q), function (items) {
                    renderSuggest(publisherSuggest, items.map(function (item) {
                        return {
                            name: item.name || '',
                            onSelect: function () {
                                publisherInput.value = item.name || '';
                                magazineIdInput.value = '';
                                magazineInput.focus();
                            }
                        };
                    }), function (item) {
                        return item.name;
                    });
                });
            }

            function loadMagazines() {
                const q = magazineInput.value.trim();
                const publisher = publisherInput.value.trim();
                const params = new URLSearchParams({
                    q: q,
                    publisher: publisher
                });

                fetchJson('ajax_magazines_by_publisher.php?' + params.toString(), function (items) {
                    renderSuggest(magazineSuggest, items.map(function (item) {
                        return {
                            label: (item.publisher ? item.publisher + ' / ' : '') + (item.magazine_name || ''),
                            onSelect: function () {
                                magazineInput.value = item.magazine_name || '';
                                publisherInput.value = item.publisher || publisherInput.value;
                                magazineIdInput.value = item.id || '';
                            }
                        };
                    }), function (item) {
                        return item.label;
                    });
                });
            }

            if (publisherInput && publisherSuggest && magazineInput && magazineIdInput) {
                publisherInput.addEventListener('input', function () {
                    magazineIdInput.value = '';
                    window.clearTimeout(publisherTimer);

                    const q = publisherInput.value.trim();
                    if (q === '') {
                        closeSuggest(publisherSuggest);
                        return;
                    }

                    publisherTimer = window.setTimeout(function () {
                        loadPublishers();
                    }, 180);
                });
            }

            if (magazineInput && magazineSuggest && magazineIdInput && publisherInput) {
                magazineInput.addEventListener('input', function () {
                    magazineIdInput.value = '';
                    window.clearTimeout(magazineTimer);

                    const q = magazineInput.value.trim();
                    const publisher = publisherInput.value.trim();
                    if (q === '') {
                        closeSuggest(magazineSuggest);
                        return;
                    }

                    magazineTimer = window.setTimeout(function () {
                        loadMagazines();
                    }, 180);
                });
            }

            if (publisherToggle && publisherInput && publisherSuggest) {
                publisherToggle.addEventListener('click', function () {
                    loadPublishers();
                });
            }

            if (magazineToggle && magazineInput && magazineSuggest) {
                magazineToggle.addEventListener('click', function () {
                    loadMagazines();
                });
            }

            document.addEventListener('click', function (event) {
                if (
                    publisherSuggest &&
                    publisherInput &&
                    publisherToggle &&
                    !publisherSuggest.contains(event.target) &&
                    event.target !== publisherInput &&
                    event.target !== publisherToggle
                ) {
                    closeSuggest(publisherSuggest);
                }

                if (
                    magazineSuggest &&
                    magazineInput &&
                    magazineToggle &&
                    !magazineSuggest.contains(event.target) &&
                    event.target !== magazineInput &&
                    event.target !== magazineToggle
                ) {
                    closeSuggest(magazineSuggest);
                }
            });
        });
    </script>
</head>
<body>
<div class="container">

    <h1>雑誌号登録</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issues.php">雑誌号一覧</a>
        <a href="issue_add.php">雑誌号登録</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <?php if (!empty($issue_id)): ?>
                <a href="issue_edit.php?id=<?= h($issue_id) ?>">登録した雑誌号を編集</a>
                <a href="toc_extract_tool_ai.php?issue_id=<?= h($issue_id) ?>">目次抽出TOOL</a>
                <a href="content_bulk_add.php?issue_id=<?= h($issue_id) ?>">目次を一括登録</a>
                <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="issue_add.php" enctype="multipart/form-data">

        <div class="form-grid">
            <div class="form-row">
                <label>
                    出版社名 <span class="required">必須</span>
                </label>
                <div class="suggest-wrap">
                    <div class="suggest-input-row">
                        <input type="text" id="publisherNameInput" name="publisher_name" value="<?= h($publisher_name) ?>" placeholder="例：集英社" autocomplete="off">
                        <button type="button" id="publisherSuggestToggle" class="suggest-toggle" aria-label="出版社候補を表示">▼</button>
                    </div>
                    <div id="publisherSuggest" class="suggest-list"></div>
                </div>
            </div>

            <div class="form-row">
                <label>
                    雑誌名 <span class="required">必須</span>
                </label>
                <div class="suggest-wrap">
                    <div class="suggest-input-row">
                        <input type="text" id="magazineNameInput" name="magazine_name" value="<?= h($magazine_name) ?>" placeholder="例：週刊少年ジャンプ" autocomplete="off">
                        <button type="button" id="magazineSuggestToggle" class="suggest-toggle" aria-label="雑誌候補を表示">▼</button>
                    </div>
                    <input type="hidden" id="magazineIdInput" name="magazine_id" value="<?= h($magazine_id) ?>">
                    <div id="magazineSuggest" class="suggest-list"></div>
                </div>
            </div>
        </div>

        <div class="form-row">
            <label>雑誌コード</label>
            <input type="text" name="magazine_code" value="<?= h($magazine_code) ?>" placeholder="例：29931">
            <div class="help">雑誌マスタ側に保存します。既存雑誌の場合は、入力時のみ更新します。</div>
        </div>

        <div class="section-title">号情報</div>

        <div class="form-row">
            <label>号数表示</label>
            <input type="text" name="issue_label" value="<?= h($issue_label) ?>" placeholder="例：1968年8月1日号">
            <div class="help">通常号の表示名です。並び順や公開ページの基本表示にも使います。</div>
        </div>

        <div class="form-row">
            <label>号数表示（増刊用）</label>
            <input type="text" name="issue_label_special" value="<?= h($issue_label_special) ?>" placeholder="例：少年ジャンプ1969年6月3日増刊">
            <div class="help">増刊など、補足的な号数表示が必要な場合に入力します。号数表示へコピーせず、別項目として保存します。</div>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>年</label>
                <input type="number" name="issue_year" value="<?= h($issue_year) ?>" placeholder="例：1968">
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-row">
                <label>巻数</label>
                <input type="text" class="js-issue-number" name="issue_vol" value="<?= h($issue_vol) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>

            <div class="form-row">
                <label>号数</label>
                <input type="text" class="js-issue-number" name="issue_no" value="<?= h($issue_no) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>

            <div class="form-row">
                <label>通巻</label>
                <input type="text" class="js-issue-number" name="issue_full_vol" value="<?= h($issue_full_vol) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>
        </div>

        <div class="form-row">
            <label class="checkbox-label">
                <input type="checkbox" name="is_digital" value="1" <?= ((int)$is_digital === 1) ? 'checked' : '' ?>>
                電子版
            </label>
            <div class="help">電子版の雑誌号の場合にチェックします。</div>
        </div>

        <div class="form-row">
            <label>備考</label>
            <textarea name="note" placeholder="例：電子限定配信 / 紙版未確認 / 特別編集号など"><?= h($note) ?></textarea>
            <div class="help">雑誌号単位のメモを保存します。</div>
        </div>

        <div class="section-title">発行日</div>

        <div class="form-row">
            <label>発行日</label>
            <input type="date" name="publication_date" value="<?= h($publication_date) ?>">
            <div class="help">日付として扱える場合はこちらに入力します。</div>
        </div>

        <div class="form-grid-3">
            <div class="form-row">
                <label>年</label>
                <input type="number" name="publication_year" value="<?= h($publication_year) ?>" placeholder="例：1968">
            </div>

            <div class="form-row">
                <label>月</label>
                <input type="number" name="publication_month" value="<?= h($publication_month) ?>" placeholder="例：8" min="1" max="12">
            </div>

            <div class="form-row">
                <label>日</label>
                <input type="number" name="publication_day" value="<?= h($publication_day) ?>" placeholder="例：1" min="1" max="31">
            </div>
        </div>

        <div class="section-title">発売日・公開状態</div>

        <div class="form-grid">
            <div class="form-row">
                <label>発売日</label>
                <input type="date" name="release_date" value="<?= h($release_date) ?>">
            </div>

            <div class="form-row">
                <label>公開状態</label>
                <select name="status">
                    <?php foreach (issue_status_options() as $status_value => $status_label): ?>
                        <option value="<?= h($status_value) ?>" <?= ($status === $status_value) ? 'selected' : '' ?>>
                            <?= h($status_value) ?>（<?= h($status_label) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section-title">出典画像</div>

        <div class="form-row">
            <label>出典画像を追加</label>
            <input type="file" name="source_image" accept="image/jpeg,image/png,image/webp">
            <div class="help">任意。JPG / PNG / WEBP、5MB以下。</div>
        </div>

        <div class="form-row">
            <label>出典画像の備考</label>
            <input type="text" name="source_image_note" value="<?= h($source_image_note) ?>" placeholder="例：目次ページ">
        </div>

        <button type="submit">登録する</button>

    </form>

</div>
</body>
</html>
