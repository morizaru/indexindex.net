<?php

function work_admin_start_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['work_admin_csrf'])) { $_SESSION['work_admin_csrf'] = bin2hex(random_bytes(32)); }
}

function work_admin_check_csrf($token)
{
    if (!is_string($token) || !hash_equals($_SESSION['work_admin_csrf'], $token)) {
        throw new RuntimeException('画面の有効期限が切れています。再読み込みしてください。');
    }
}

function work_admin_require_transactions(PDO $pdo, array $tables)
{
    // Engine was not part of the supplied column/index export. Verify it before writing.
    $stmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($tables as $table) {
        $stmt->execute([$table]);
        if (strtoupper((string)$stmt->fetchColumn()) !== 'INNODB') {
            throw new RuntimeException('保存を停止しました。' . $table . ' のストレージエンジンがInnoDBであることを確認できません。');
        }
    }
}

function work_admin_validate_title($title, $sub, $kana, $type)
{
    if ($title === '') { throw new RuntimeException('作品名を入力してください。'); }
    foreach ([$title, $sub, $kana] as $text) {
        if (mb_strlen($text, 'UTF-8') > 200) { throw new RuntimeException('作品名・サブタイトル・かなはそれぞれ200文字以内で入力してください。'); }
    }
    if (!in_array($type, ['連載', '読切'], true)) { throw new RuntimeException('掲載フラグが不正です。'); }
}

function work_admin_create(PDO $pdo, $title, $sub, $kana, $type)
{
    work_admin_validate_title($title, $sub, $kana, $type);
    work_admin_require_transactions($pdo, ['works']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM works WHERE BINARY title = BINARY ? LIMIT 1');
        $stmt->execute([$title]);
        if ($stmt->fetchColumn() !== false) { throw new RuntimeException('同じ作品名がすでに登録されています。'); }
        $stmt = $pdo->prepare('INSERT INTO works (title, title_sub, title_kana, publication_type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$title, $sub === '' ? null : $sub, $kana === '' ? null : $kana, $type]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
