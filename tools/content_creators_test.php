<?php
// Run only against a disposable local database, never the application database.
// php tools/content_creators_test.php mysql:host=127.0.0.1;port=13317 root
require_once __DIR__ . '/../app/content_creators.php';

$dsn = $argv[1] ?? '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+$/D', $dsn)) {
    throw new RuntimeException('Specify a disposable localhost MySQL server without a database name.');
}
$pdo = new PDO($dsn . ';charset=utf8mb4', $argv[2] ?? 'root', '', [
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
    $pdo->exec('CREATE TABLE creator_aliases (id INT AUTO_INCREMENT PRIMARY KEY, creator_id INT NOT NULL, alias_name VARCHAR(150) NOT NULL) ENGINE=InnoDB');
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
        display_name VARCHAR(255) NULL, display_title VARCHAR(255) NULL, display_affiliation VARCHAR(255) NULL,
        display_order INT NOT NULL DEFAULT 1, is_visible TINYINT(1) NOT NULL DEFAULT 1,
        INDEX(content_id)
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO creators (id, name) VALUES (1, '漫画家'), (2, '監修者'), (3, 'Ａ氏'), (4, 'ゲスト')");
    $pdo->exec("INSERT INTO creator_aliases (creator_id, alias_name) VALUES (1, '漫画家別名')");
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
    $rejected = false;
    try { eval($edit); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected && $snapshot === $pdo->query($load)->fetchAll(), 'Duplicate edit rejected without overwriting snapshots');
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
    // Snapshot cases from the request, through the real edit normalizer/save block.
    $_POST = [];
    $cases = [
        ['display_name' => 'RUMIKO TAKAHASHI', 'display_title' => '', 'display_affiliation' => ''],
        ['display_name' => '', 'display_title' => '二段', 'display_affiliation' => '日本棋院'],
        ['display_name' => 'YUKARI UMEZAWA', 'display_title' => '二段', 'display_affiliation' => '日本棋院'],
    ];
    foreach ($cases as $case) {
        $pdo->beginTransaction();
        $writer->lockContent(1);
        $stored_creator_rows = $pdo->query($load)->fetchAll();
        $posted = $stored_creator_rows;
        $posted[0] = array_merge($posted[0], $case);
        $creator_rows = normalize_creator_rows($posted);
        eval($edit);
        $pdo->commit();
        $saved = $pdo->query($load)->fetchAll()[0];
        check($saved['id'] === $posted[0]['id'], 'Snapshot edit preserves ID');
        foreach ($case as $field => $value) {
            check($saved[$field] === ($value === '' ? null : $value), 'Independent snapshot field: ' . $field);
        }
    }
    // Detect even otherwise invisible no-op UPDATEs with a test-only counter trigger.
    $pdo->exec('CREATE TABLE update_counter (n INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO update_counter VALUES (0)');
    $pdo->exec('CREATE TRIGGER count_snapshot_update AFTER UPDATE ON content_creators FOR EACH ROW UPDATE update_counter SET n=n+1');
    $pdo->beginTransaction();
    $writer->lockContent(1);
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $creator_rows = normalize_creator_rows($stored_creator_rows);
    eval($edit);
    $pdo->commit();
    check((int)$pdo->query('SELECT n FROM update_counter')->fetchColumn() === 0, 'Unchanged rows execute no UPDATE');
    check($stored_creator_rows === $pdo->query($load)->fetchAll(), 'Unchanged three fields retained');
    $empty = ContentCreatorWriter::snapshotValues(['display_name'=>'','display_title'=>'','display_affiliation'=>''],
        ['display_name'=>'','display_title'=>null,'display_affiliation'=>'']);
    check($empty === ['display_name'=>'','display_title'=>null,'display_affiliation'=>''], 'Existing empty strings and NULL retained distinctly');
    check(ContentCreatorWriter::snapshotValues([], $cases[2]) === $cases[2], 'Missing fields preserve previous values');
    $rejected = false;
    try { ContentCreatorWriter::snapshotValues(['display_title'=>str_repeat('段', 256)]); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Overlength snapshot rejected');
    $rejected = false;
    try { ContentCreatorWriter::snapshotValues(['display_affiliation'=>'（日本棋院）']); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'New affiliation requires no outer parentheses');
    check(ContentCreatorWriter::snapshotValues(['display_affiliation'=>'（既存）'], ['display_affiliation'=>'（既存）'])['display_affiliation'] === '（既存）', 'Existing affiliation not normalized on save');

    // Execute actual new-content insertion blocks; no application config or business tables needed.
    $addSource = file_get_contents(__DIR__ . '/../admin/content_add.php');
    $start = strpos($addSource, 'function normalize_creator_rows(');
    $end = strpos($addSource, 'function make_empty_creator_rows(', $start);
    eval(str_replace('function normalize_creator_rows(', 'function normalize_add_creator_rows(', substr($addSource, $start, $end-$start)));
    $start = strpos($addSource, '            // この新規目次への手入力');
    $end = strpos($addSource, '            // 5.', $start);
    $addBlock = substr($addSource, $start, $end-$start);
    $content_id = 2; $work_id = 10;
    $creator_rows = normalize_add_creator_rows([array_merge($cases[2], ['creator_name'=>'漫画家別名', 'role'=>'author', 'is_visible'=>'0', 'display_order'=>'8'])]);
    $pdo->beginTransaction(); eval($addBlock); $pdo->commit();
    $new = $pdo->query('SELECT * FROM content_creators WHERE content_id=2 AND creator_id=1 AND role="author"')->fetch();
    check($new['display_name'] === $cases[2]['display_name'] && $new['display_title'] === '二段' && $new['display_affiliation'] === '日本棋院', 'Add page saves all three fields before copying work creators');
    check((int)$new['creator_id'] === 1 && (int)$pdo->query('SELECT COUNT(*) FROM creators')->fetchColumn() === 4, 'Add page resolves alias without creating another person');
    check((int)$new['is_visible'] === 0 && (int)$new['display_order'] === 8, 'Add page preserves manual visibility and order');
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id=2')->fetchColumn() === 4, 'Add page copies only missing standard creators');
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id=2 AND creator_id=2 AND display_name IS NULL AND display_title IS NULL AND display_affiliation IS NULL')->fetchColumn() === 1, 'Work copy uses three NULL fields');
    $pdo->beginTransaction();
    $writer->insertIfMissing(2, 1, 1, 'author', 1, 1, $cases[0]);
    $pdo->commit();
    check($new === $pdo->query('SELECT * FROM content_creators WHERE id=' . (int)$new['id'])->fetch(), 'insertIfMissing does not overwrite existing metadata');

    $content_id = 4; $work_id = 999;
    $creator_rows = normalize_add_creator_rows([array_merge($cases[0], ['creator_name'=>'新規人物', 'role'=>'author', 'display_order'=>'1', 'is_visible'=>'1'])]);
    $pdo->beginTransaction(); eval($addBlock); $pdo->commit();
    check((int)$pdo->query("SELECT COUNT(*) FROM content_creators cc JOIN creators c ON c.id=cc.creator_id WHERE cc.content_id=4 AND c.name='新規人物' AND cc.display_name='RUMIKO TAKAHASHI'")->fetchColumn() === 1, 'Add page retains unregistered person creation');

    $bulkSource = file_get_contents(__DIR__ . '/../admin/content_bulk_add.php');
    $start = strpos($bulkSource, 'function get_or_create_creator_id(');
    $end = strpos($bulkSource, 'function send_creator_lookup_json(', $start);
    eval(substr($bulkSource, $start, $end-$start));
    $start = strpos($bulkSource, '            // 新規目次のロックはINSERT時に取得済み');
    $end = strpos($bulkSource, '            $inserted_count++;', $start);
    $bulkBlock = substr($bulkSource, $start, $end-$start);
    $content_id = 3; $work_id = 10;
    $row = ['input_order'=>1, 'creator_rows'=>normalize_structured_creators([array_merge($cases[2], ['name'=>'漫画家', 'role'=>'author'])])];
    $pdo->beginTransaction(); eval($bulkBlock); $pdo->commit();
    $new = $pdo->query('SELECT * FROM content_creators WHERE content_id=3 AND creator_id=1 AND role="author"')->fetch();
    check($new['display_name'] === $cases[2]['display_name'] && $new['display_title'] === '二段' && $new['display_affiliation'] === '日本棋院', 'Bulk page saves independent metadata from structured JSON');
    check((int)$pdo->query('SELECT COUNT(*) FROM content_creators WHERE content_id=3')->fetchColumn() === 4, 'Bulk page still copies missing work creators');
    $workBefore = $pdo->query('SELECT * FROM work_creators ORDER BY id')->fetchAll();
    $pdo->beginTransaction();
    $stored_creator_rows = $pdo->query($load)->fetchAll();
    $creator_rows = normalize_creator_rows($stored_creator_rows);
    $creator_rows[0]['display_title'] = '九段'; $content_id = 1;
    eval($edit);
    $pdo->rollBack();
    check($stored_creator_rows === $pdo->query($load)->fetchAll(), 'Snapshot edit rolls back with transaction');
    check($workBefore === $pdo->query('SELECT * FROM work_creators ORDER BY id')->fetchAll(), 'No work creator changes');
    echo "PASS: $checks checks\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("DROP DATABASE `$database`");
}
