<?php
// admin/issue_edit.php

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

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
}

if ($issue_id <= 0) {
    exit('雑誌号IDが指定されていません。');
}

$message = '';
$error = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = '雑誌号を保存しました。';
}

// 出典画像保存先
$upload_dir = __DIR__ . '/../uploads/source_images/';
$upload_url_base = 'uploads/source_images/';

// 出版社一覧
$stmt = $pdo->query("
    SELECT id, name
    FROM publishers
    ORDER BY name ASC
");
$publishers = $stmt->fetchAll();

// 雑誌一覧
$stmt = $pdo->query("
    SELECT
        m.id,
        m.name,
        m.publisher_id,
        m.magazine_code,
        p.name AS publisher_name
    FROM magazines m
    JOIN publishers p ON m.publisher_id = p.id
    ORDER BY p.name ASC, m.name ASC
");
$magazines = $stmt->fetchAll();

function fetch_issue($pdo, $issue_id) {
    $issue_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_vol')
        ? 'mi.issue_vol'
        : 'NULL AS issue_vol';
    $issue_full_vol_select = table_has_column($pdo, 'magazine_issues', 'issue_full_vol')
        ? 'mi.issue_full_vol'
        : 'NULL AS issue_full_vol';

    $stmt = $pdo->prepare("
        SELECT
            mi.id,
            mi.magazine_id,
            mi.issue_label,
            mi.issue_label_special,
            mi.issue_year,
            {$issue_vol_select},
            mi.issue_no,
            {$issue_full_vol_select},
            mi.publication_date,
            mi.publication_year,
            mi.publication_month,
            mi.publication_day,
            mi.release_date,
            mi.status,
            mi.created_at,
            mi.updated_at,

            m.name AS magazine_name,
            m.publisher_id,
            m.magazine_code,

            p.name AS publisher_name
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE mi.id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $issue_id
    ]);

    return $stmt->fetch();
}

// 現在データ取得
$issue = fetch_issue($pdo, $issue_id);

if (!$issue) {
    exit('指定された雑誌号が見つかりません。');
}

$publisher_id = (int)$issue['publisher_id'];
$magazine_id = (int)$issue['magazine_id'];
$magazine_code = $issue['magazine_code'] ?? '';

$issue_label = $issue['issue_label'];
$issue_label_special = $issue['issue_label_special'];
$issue_year = $issue['issue_year'];
$issue_vol = $issue['issue_vol'];
$issue_no = $issue['issue_no'];
$issue_full_vol = $issue['issue_full_vol'];

$publication_date = $issue['publication_date'];
$publication_year = $issue['publication_year'];
$publication_month = $issue['publication_month'];
$publication_day = $issue['publication_day'];

$release_date = $issue['release_date'];
$status = $issue['status'];
$source_image_note = '';

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publisher_id = isset($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : 0;
    $magazine_id = isset($_POST['magazine_id']) ? (int)$_POST['magazine_id'] : 0;
    $magazine_code = trim($_POST['magazine_code'] ?? '');

    $issue_label = trim($_POST['issue_label'] ?? '');
    $issue_label_special = trim($_POST['issue_label_special'] ?? '');
    $issue_year = trim($_POST['issue_year'] ?? '');
    $issue_vol = trim($_POST['issue_vol'] ?? '');
    $issue_no = trim($_POST['issue_no'] ?? '');
    $issue_full_vol = trim($_POST['issue_full_vol'] ?? '');

    $publication_date = trim($_POST['publication_date'] ?? '');
    $publication_year = trim($_POST['publication_year'] ?? '');
    $publication_month = trim($_POST['publication_month'] ?? '');
    $publication_day = trim($_POST['publication_day'] ?? '');

    $release_date = trim($_POST['release_date'] ?? '');
    $status = trim($_POST['status'] ?? 'approved');
    $source_image_note = trim($_POST['source_image_note'] ?? '');

    if ($publisher_id <= 0) {
        $error = '出版社を選択してください。';
    } elseif ($magazine_id <= 0) {
        $error = '雑誌を選択してください。';
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

            // 選択された雑誌が選択出版社に属しているか確認
            $stmt = $pdo->prepare("
                SELECT id
                FROM magazines
                WHERE id = :magazine_id
                  AND publisher_id = :publisher_id
                LIMIT 1
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id,
                ':publisher_id' => $publisher_id
            ]);
            $magazine_check = $stmt->fetch();

            if (!$magazine_check) {
                throw new Exception('選択した出版社と雑誌の組み合わせが正しくありません。');
            }

            // 雑誌コード更新
            $stmt = $pdo->prepare("
                UPDATE magazines
                SET magazine_code = :magazine_code
                WHERE id = :id
            ");
            $stmt->execute([
                ':magazine_code' => ($magazine_code !== '') ? $magazine_code : null,
                ':id' => $magazine_id
            ]);

            // issue_label と issue_label_special は別々に保存する
            // ※増刊用を入力しても issue_label へコピーしない

            // 同じ雑誌・同じ号数表示の重複チェック
            $stmt = $pdo->prepare("
                SELECT id
                FROM magazine_issues
                WHERE magazine_id = :magazine_id
                  AND issue_label = :issue_label
                  AND id <> :id
                LIMIT 1
            ");
            $stmt->execute([
                ':magazine_id' => $magazine_id,
                ':issue_label' => $issue_label,
                ':id' => $issue_id
            ]);
            $exists = $stmt->fetch();

            if ($exists) {
                throw new Exception('同じ雑誌の同じ号数表示がすでに登録されています。');
            }

            // 雑誌号更新
            $update_sets = [
                'magazine_id = :magazine_id',
                'issue_label = :issue_label',
                'issue_label_special = :issue_label_special',
                'issue_year = :issue_year',
                'issue_no = :issue_no',
                'publication_date = :publication_date',
                'publication_year = :publication_year',
                'publication_month = :publication_month',
                'publication_day = :publication_day',
                'release_date = :release_date',
                'status = :status',
            ];

            $update_params = [
                ':magazine_id' => $magazine_id,
                ':issue_label' => ($issue_label !== '') ? $issue_label : null,
                ':issue_label_special' => ($issue_label_special !== '') ? $issue_label_special : null,
                ':issue_year' => ($issue_year !== '') ? (int)$issue_year : null,
                ':issue_no' => issue_number_db_value($issue_no),
                ':publication_date' => ($publication_date !== '') ? $publication_date : null,
                ':publication_year' => ($publication_year !== '') ? (int)$publication_year : null,
                ':publication_month' => ($publication_month !== '') ? (int)$publication_month : null,
                ':publication_day' => ($publication_day !== '') ? (int)$publication_day : null,
                ':release_date' => ($release_date !== '') ? $release_date : null,
                ':status' => $status,
                ':id' => $issue_id
            ];

            if (table_has_column($pdo, 'magazine_issues', 'issue_vol')) {
                $update_sets[] = 'issue_vol = :issue_vol';
                $update_params[':issue_vol'] = issue_number_db_value($issue_vol);
            }

            if (table_has_column($pdo, 'magazine_issues', 'issue_full_vol')) {
                $update_sets[] = 'issue_full_vol = :issue_full_vol';
                $update_params[':issue_full_vol'] = issue_number_db_value($issue_full_vol);
            }

            $stmt = $pdo->prepare("
                UPDATE magazine_issues
                SET
                    " . implode(",\n                    ", $update_sets) . "
                WHERE id = :id
            ");

            $stmt->execute($update_params);

            // 出典画像が選択されている場合だけ追加登録
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

            header('Location: issue_edit.php?issue_id=' . $issue_id . '&saved=1');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = '更新エラー：' . $e->getMessage();
        }
    }
}

// 関連データ件数
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM issue_contents
    WHERE issue_id = :issue_id
");
$stmt->execute([
    ':issue_id' => $issue_id
]);
$content_count = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM source_images
    WHERE issue_id = :issue_id
");
$stmt->execute([
    ':issue_id' => $issue_id
]);
$image_count = (int)$stmt->fetch()['cnt'];

// 出典画像一覧
$stmt = $pdo->prepare("
    SELECT
        id,
        image_path,
        note,
        created_at
    FROM source_images
    WHERE issue_id = :issue_id
    ORDER BY id DESC
");
$stmt->execute([
    ':issue_id' => $issue_id
]);
$source_images = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>雑誌号編集 - INDEXINDEX 管理</title>
    <link rel="stylesheet" href="../assets/admin.css">
    <style>

        .container {
            max-width: 980px;
        }

        h2 {
            margin-top: 28px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .image-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            margin-top: 12px;
        }

        .image-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            background: #fafafa;
        }

        .image-card img {
            max-width: 100%;
            height: auto;
            display: block;
            border: 1px solid #ddd;
            background: #fff;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>雑誌号編集</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issues.php">雑誌号一覧</a>
        <a href="issue_add.php">雑誌号登録</a>
        <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページ</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?><br>
            <a href="issues.php">雑誌号一覧へ</a>
            <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページを開く</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="info">
        ID：<?= h($issue['id']) ?><br>
        現在：<?= h($issue['publisher_name']) ?> / <?= h($issue['magazine_name']) ?> / <?= h($issue['issue_label']) ?><br>
        目次件数：<?= h($content_count) ?>件<br>
        出典画像：<?= h($image_count) ?>件<br>
        作成日時：<?= h($issue['created_at']) ?><br>
        更新日時：<?= h($issue['updated_at']) ?>
    </div>

    <form method="post" action="issue_edit.php?id=<?= h($issue_id) ?>" enctype="multipart/form-data">

        <input type="hidden" name="id" value="<?= h($issue_id) ?>">

        <div class="form-grid">
            <div class="form-row">
                <label>
                    出版社 <span class="required">必須</span>
                </label>
                <select name="publisher_id" id="publisher_id">
                    <option value="">選択してください</option>
                    <?php foreach ($publishers as $publisher): ?>
                        <option value="<?= h($publisher['id']) ?>" <?= ($publisher_id === (int)$publisher['id']) ? 'selected' : '' ?>>
                            <?= h($publisher['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label>
                    雑誌 <span class="required">必須</span>
                </label>
                <select name="magazine_id" id="magazine_id">
                    <option value="">選択してください</option>
                </select>
                <div class="help">出版社を選ぶと、その出版社の雑誌だけ表示します。</div>
            </div>
        </div>

        <div class="form-row">
            <label>雑誌コード</label>
            <input type="text" name="magazine_code" value="<?= h($magazine_code) ?>" placeholder="例：29931">
            <div class="help">雑誌マスタ側に保存します。</div>
        </div>

        <div class="section-title">号情報</div>

        <div class="form-row">
            <label>号数表示</label>
            <input type="text" name="issue_label" value="<?= h($issue_label) ?>" placeholder="例：1968年8月1日号">
            <div class="help">通常号の表示名です。</div>
        </div>

        <div class="form-row">
            <label>号数表示（増刊用）</label>
            <input type="text" name="issue_label_special" value="<?= h($issue_label_special) ?>" placeholder="例：少年ジャンプ1969年6月3日増刊">
            <div class="help">増刊用の表示名です。号数表示とは別項目として保存します。</div>
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
                <input type="text" name="issue_vol" value="<?= h($issue_vol) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>

            <div class="form-row">
                <label>号数</label>
                <input type="text" name="issue_no" value="<?= h($issue_no) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>

            <div class="form-row">
                <label>通巻</label>
                <input type="text" name="issue_full_vol" value="<?= h($issue_full_vol) ?>" placeholder="例：1" inputmode="numeric" maxlength="5">
            </div>
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
            <div class="help">任意。JPG / PNG / WEBP、5MB以下。既存画像は削除せず、新しく追加します。</div>
        </div>

        <div class="form-row">
            <label>出典画像の備考</label>
            <input type="text" name="source_image_note" value="<?= h($source_image_note) ?>" placeholder="例：目次ページ">
        </div>

        <button type="submit">更新する</button>

    </form>

    <h2>登録済み出典画像</h2>

    <?php if (empty($source_images)): ?>

        <p class="small">出典画像は登録されていません。</p>

    <?php else: ?>

        <div class="image-list">
            <?php foreach ($source_images as $image): ?>
                <div class="image-card">
                    <a href="../<?= h($image['image_path']) ?>" target="_blank">
                        <img src="../<?= h($image['image_path']) ?>" alt="">
                    </a>
                    <div class="small">
                        ID：<?= h($image['id']) ?><br>
                        <?php if (!empty($image['note'])): ?>
                            備考：<?= h($image['note']) ?><br>
                        <?php endif; ?>
                        登録日：<?= h($image['created_at']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <h2>関連ページ</h2>

    <div class="links">
        <a href="content_list.php?issue_id=<?= h($issue_id) ?>">登録済み目次一覧</a>
        <a href="content_bulk_add.php?issue_id=<?= h($issue_id) ?>">登録済み目次一括登録</a>
        <a href="content_add.php?issue_id=<?= h($issue_id) ?>">この号に目次を追加</a>
        <a href="source_image_add.php">出典画像登録</a>
        <a href="source_image_list.php?issue_id=<?= h($issue_id) ?>">出典画像一覧</a>
        <a href="../issue.php?id=<?= h($issue_id) ?>" target="_blank">公開ページ</a>
    </div>

</div>

<script>
const magazines = <?= json_encode($magazines, JSON_UNESCAPED_UNICODE) ?>;
const selectedPublisherId = <?= json_encode((string)$publisher_id) ?>;
const selectedMagazineId = <?= json_encode((string)$magazine_id) ?>;

const publisherSelect = document.getElementById('publisher_id');
const magazineSelect = document.getElementById('magazine_id');

function clearMagazineSelect() {
    magazineSelect.innerHTML = '';

    const option = document.createElement('option');
    option.value = '';
    option.textContent = '選択してください';
    magazineSelect.appendChild(option);
}

function fillMagazines(keepSelected = '') {
    const publisherId = publisherSelect.value;

    clearMagazineSelect();

    if (!publisherId) {
        return;
    }

    magazines.forEach(row => {
        if (String(row.publisher_id) !== String(publisherId)) {
            return;
        }

        const option = document.createElement('option');
        option.value = row.id;
        option.textContent = row.name;

        if (String(row.id) === String(keepSelected)) {
            option.selected = true;
        }

        magazineSelect.appendChild(option);
    });
}

publisherSelect.addEventListener('change', () => {
    fillMagazines();
});

if (selectedPublisherId) {
    publisherSelect.value = selectedPublisherId;
    fillMagazines(selectedMagazineId);
}
</script>

</body>
</html>
