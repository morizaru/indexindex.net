<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/work_admin.php';
work_admin_start_session();
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$title = $title_sub = $title_kana = $error = '';
$publication_type = '連載';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $title_sub = trim((string)($_POST['title_sub'] ?? ''));
    $title_kana = trim((string)($_POST['title_kana'] ?? ''));
    $publication_type = (string)($_POST['publication_type'] ?? '');
    try {
        work_admin_check_csrf($_POST['csrf_token'] ?? null);
        $id = work_admin_create($pdo, $title, $title_sub, $title_kana, $publication_type);
        header('Location: work_edit.php?id=' . $id . '&saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = '登録エラー：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>作品追加 - INDEXINDEX 管理</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; background: #f5f5f5; color: #222; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        h1 { margin-top: 0; }
        .nav { margin-bottom: 20px; }
        a { color: #0066cc; font-weight: bold; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .nav a { margin-right: 12px; }
        .form-row { margin-bottom: 16px; }
        label { display: block; font-weight: bold; margin-bottom: 6px; }
        input[type="text"], select { width: 100%; box-sizing: border-box; padding: 10px; font-size: 16px; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 10px 20px; font-size: 16px; border: 1px solid #333; background: #333; color: #fff; border-radius: 4px; cursor: pointer; }
        button:hover { background: #111; }
        .required { color: #b00020; font-size: 13px; }
        .help { color: #666; font-size: 13px; line-height: 1.6; }
        .error { padding: 12px; background: #ffebee; border: 1px solid #e57373; color: #b00020; margin-bottom: 16px; border-radius: 4px; }
        @media (max-width: 800px) { body { margin: 8px; } .container { padding: 12px; } }
    </style>
</head>
<body><div class="container">
    <h1>作品追加</h1>
    <div class="nav"><a href="index.php">管理トップ</a><a href="works.php">作品一覧</a></div>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <p class="help">掲載号がなくても登録できます。登録後の編集画面で人物・役割・話数フォーマットを設定できます。</p>
    <form method="post" action="work_add.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['work_admin_csrf']) ?>">
        <div class="form-row"><label for="title">作品名 <span class="required">必須</span></label><input id="title" type="text" name="title" maxlength="200" required value="<?= h($title) ?>"></div>
        <div class="form-row"><label for="title_sub">作品サブタイトル</label><input id="title_sub" type="text" name="title_sub" maxlength="200" value="<?= h($title_sub) ?>"></div>
        <div class="form-row"><label for="title_kana">作品名かな</label><input id="title_kana" type="text" name="title_kana" maxlength="200" value="<?= h($title_kana) ?>"></div>
        <div class="form-row"><label for="publication_type">掲載フラグ</label><select id="publication_type" name="publication_type">
            <?php foreach (['連載', '読切'] as $type): ?><option value="<?= h($type) ?>" <?= $publication_type === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?>
        </select></div>
        <button type="submit">登録して編集へ</button>
    </form>
</div></body></html>
