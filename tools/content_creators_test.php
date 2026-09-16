<?php
// Run only against a disposable local database, never the application database.
// php tools/content_creators_test.php mysql:host=127.0.0.1;port=13317 root
require_once __DIR__ . '/../app/content_creators.php';

$dsn = $argv[1] ?? '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+$/D', $dsn)) {
    throw new RuntimeException('Specify a disposable localhost MySQL server without a database name.');
}
$pdo = new PDO($dsn, $argv[2] ?? 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database = 'test_content_creators_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `$database`");
$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

try {
    // Minimal fixtures use the column definitions supplied by the DB owner.
    $pdo->exec('CREATE TABLE issue_contents (id INT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE creators (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE work_creators (
        id INT AUTO_INCREMENT PRIMARY KEY, work_id INT NOT NULL, creator_id INT NOT NULL,
        role_id INT NULL, role VARCHAR(50) NOT NULL DEFAULT 'author',
        display_order INT NOT NULL DEFAULT 1, default_visible TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE(work_id, creator_id, role)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE content_creators (
        id INT AUTO_INCREMENT PRIMARY KEY, content_id INT NOT NULL, creator_id INT NOT NULL,
        role_id INT NULL, role VARCHAR(50) NOT NULL DEFAULT 'author',
        display_name VARCHAR(255) NULL, display_affiliation VARCHAR(255) NULL,
        display_order INT NOT NULL DEFAULT 1, is_visible TINYINT(1) NOT NULL DEFAULT 1,
        INDEX(content_id)
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO creators (id, name) VALUES (1, '漫画家'), (2, '監修者'), (3, 'Ａ氏'), (4, 'ゲスト')");
    $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'author'), (2, '監修')");
    $pdo->exec('INSERT INTO issue_contents VALUES (1), (2), (3), (4)');
    $pdo->exec("INSERT INTO work_creators (work_id, creator_id, role_id, role, display_order, default_visible) VALUES
        (10, 1, 1, 'author', 2, 1), (10, 2, 2, '監修', 3, 0),
        (10, 3, NULL, '', 0, 1), (10, 1, NULL, '原作', 4, 1),
        (20, 4, NULL, 'ゲスト', 1, 0)");
    $writer = new ContentCreatorWriter($pdo);
    $pdo->beginTransaction();
    $writer->lockContent(1);
    $writer->syncWorkCreatorsToContentCreators(10, 1);
    $expected = $pdo->query('SELECT creator_id, role_id, role, display_order, default_visible AS is_visible FROM work_creators WHERE work_id = 10 ORDER BY creator_id, role')->fetchAll();
    $actual = $pdo->query('SELECT creator_id, role_id, role, display_order, is_visible FROM content_creators WHERE content_id = 1 ORDER BY creator_id, role')->fetchAll();
    check($expected === $actual, 'Copy all five values including hidden, NULL role_id, empty role and zero order');
    $writer->syncWorkCreatorsToContentCreators(10, 1);
    $writer->insertIfMissing(1, 2, null, '監修', 99, 1);
    $writer->insertIfMissing(1, 1, 1, 'AUTHOR', 99, 0);
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id = 1')->fetchColumn() === 4, 'Idempotency and collation-aware duplicate prevention');
    check((int)$pdo->query('SELECT is_visible FROM content_creators WHERE content_id = 1 AND creator_id = 2')->fetchColumn() === 0, 'Manual duplicate does not overwrite visibility');
    $writer->insertIfMissing(1, 4, null, 'ゲスト', 5, 1);
    check((int)$pdo->query('SELECT COUNT(*) FROM work_creators')->fetchColumn() === 5, 'No reverse registration');
    $pdo->exec("UPDATE content_creators SET display_name = '掲載名', display_affiliation = '掲載所属' WHERE content_id = 1");
    $pdo->commit();

    // Execute the actual edit-page normalization and persistence block without its config or HTML.
    $source = file_get_contents(__DIR__ . '/../admin/content_edit.php');
    $start = strpos($source, 'function normalize_creator_rows(');
    $end = strpos($source, '$content_id = isset($_GET');
    eval(substr($source, $start, $end - $start));
    $start = strpos($source, '            // 行IDを維持し');
    $end = strpos($source, '            // 開始ページ順で掲載順を振り直す', $start);
    $edit = substr($source, $start, $end - $start);
    $load = "SELECT cc.*, c.name AS creator_name FROM content_creators cc JOIN creators c ON c.id = cc.creator_id WHERE content_id = 1 ORDER BY cc.id";
    $before = $pdo->query($load)->fetchAll();
    $content_id = 1;
    $work_id = 20; // Changing work alone must retain the old snapshot.
    $creator_writer = $writer;
    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $before;
    $creator_rows = normalize_creator_rows($before);
    $_POST = [];
    eval($edit);
    $pdo->commit();
    check($before === $pdo->query($load)->fetchAll(), 'Ordinary save and work change preserve IDs, spelling, blank role, order, display name and affiliation');

    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $posted = $stored_creator_rows;
    $removed = array_pop($posted); // Remove only the manually added guest.
    $posted[0]['is_visible'] = 0;
    $creator_rows = normalize_creator_rows($posted);
    eval($edit);
    $pdo->commit();
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id = 1')->fetchColumn() === 4, 'Explicit deletion only');
    check((int)$pdo->query('SELECT is_visible FROM content_creators WHERE id = ' . (int)$posted[0]['id'])->fetchColumn() === 0, 'Visibility update');

    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $creator_rows = normalize_creator_rows($stored_creator_rows);
    $_POST = ['import_work_creators' => '1'];
    eval($edit);
    eval($edit);
    $pdo->commit();
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id = 1')->fetchColumn() === 5, 'Explicit import adds only missing rows');
    check((int)$pdo->query('SELECT is_visible FROM content_creators WHERE content_id = 1 AND creator_id = 4')->fetchColumn() === 0, 'Explicit import uses new work default');
    check((int)$pdo->query("SELECT COUNT(*) FROM content_creators WHERE content_id = 1 AND display_name = '掲載名' AND display_affiliation = '掲載所属'")->fetchColumn() === 4, 'Existing snapshot metadata preserved');

    $snapshot = $pdo->query($load)->fetchAll();
    $pdo->exec('UPDATE work_creators SET default_visible = 0 WHERE work_id = 10');
    check($snapshot === $pdo->query($load)->fetchAll(), 'Master edits do not change published snapshots');

    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $posted = $stored_creator_rows;
    $posted[] = ['creator_name' => '監修者', 'role' => '監修', 'is_visible' => 1];
    $creator_rows = normalize_creator_rows($posted);
    $_POST = [];
    eval($edit);
    check($snapshot === $pdo->query($load)->fetchAll(), 'Duplicate manually added during edit does not alter the snapshot');
    $pdo->rollBack();

    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $posted = $stored_creator_rows;
    $posted[0]['id'] = 999999;
    $creator_rows = normalize_creator_rows($posted);
    $rejected = false;
    try {
        eval($edit);
    } catch (Exception $e) {
        $rejected = true;
    }
    $pdo->rollBack();
    check($rejected && $snapshot === $pdo->query($load)->fetchAll(), 'Invalid row ownership rejects and rolls back deletions');

    $pdo->beginTransaction();
    foreach ([2, 3] as $id) {
        $writer->lockContent($id);
        $writer->syncWorkCreatorsToContentCreators(10, $id);
    }
    $writer->lockContent(4);
    $writer->syncWorkCreatorsToContentCreators(999, 4);
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id IN (2, 3)')->fetchColumn() === 8, 'Bulk statement reuse');
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id = 4')->fetchColumn() === 0, 'Work without creators is a no-op');
    $pdo->rollBack();
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id IN (2, 3)')->fetchColumn() === 0, 'Rollback removes all copied rows');
    echo "PASS: $checks checks\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("DROP DATABASE `$database`");
}
