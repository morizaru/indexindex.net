<?php
// Disposable local MySQL/MariaDB only. No application config or production connection.
require_once __DIR__ . '/../app/work_creators.php';
require_once __DIR__ . '/../app/work_admin.php';
$dsn = $argv[1] ?? '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+$/D', $dsn)) {
    throw new RuntimeException('Specify a disposable localhost MySQL server without a database name.');
}
$pdo = new PDO($dsn . ';charset=utf8mb4', $argv[2] ?? 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'test_work_editor_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `$database`");
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}
function rejected(callable $fn, $part) {
    try { $fn(); } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), $part) === false) { throw $e; }
        return true;
    }
    throw new RuntimeException('Expected rejection: ' . $part);
}
function saveRows($rows, $fingerprint = null) {
    global $pdo, $editor, $workId;
    $fingerprint = $fingerprint ?? WorkCreatorEditor::fingerprint($editor->rows($workId));
    $pdo->beginTransaction();
    try { $editor->save($workId, $rows, $fingerprint); $pdo->commit(); }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
try {
    // Relevant columns from the supplied structures; fixtures are not migrations.
    $pdo->exec("CREATE TABLE works (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, title_sub VARCHAR(200) NULL,
        title_kana VARCHAR(200) NULL, publication_type VARCHAR(20) NOT NULL DEFAULT '連載',
        episode_format_id INT NULL, created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(title), INDEX(episode_format_id)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE creators (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, INDEX(name)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE creator_aliases (id INT AUTO_INCREMENT PRIMARY KEY, creator_id INT NOT NULL,
        alias_name VARCHAR(150) NOT NULL, UNIQUE(creator_id, alias_name), INDEX(alias_name),
        FOREIGN KEY(creator_id) REFERENCES creators(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE work_creators (
        id INT AUTO_INCREMENT PRIMARY KEY, work_id INT NOT NULL, creator_id INT NOT NULL,
        role_id INT NULL, role VARCHAR(50) NOT NULL DEFAULT 'author', display_order INT NOT NULL DEFAULT 1,
        default_visible TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE(work_id, creator_id, role)) ENGINE=InnoDB");
    // Sentinels verify that the editor never changes listing-side data.
    $pdo->exec('CREATE TABLE issue_contents (id INT PRIMARY KEY, work_id INT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE content_creators (id INT PRIMARY KEY, role VARCHAR(50)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO content_creators VALUES (1, '掲載人物')");
    $workId = work_admin_create($pdo, '単独作品', '', '', '連載');
    check($workId > 0 && (int)$pdo->query('SELECT COUNT(*) FROM issue_contents')->fetchColumn() === 0, 'Standalone work with zero contents');
    check($pdo->query('SELECT title_sub FROM works')->fetchColumn() === null, 'Optional fields stored as NULL');
    check(rejected(function () use ($pdo) { work_admin_create($pdo, '', '', '', '連載'); }, '作品名'), 'Required title');
    check(rejected(function () use ($pdo) { work_admin_create($pdo, '別作品', '', '', 'invalid'); }, '掲載フラグ'), 'Invalid publication type');
    check(rejected(function () use ($pdo) { work_admin_create($pdo, str_repeat('あ', 201), '', '', '連載'); }, '200文字'), 'Title length');
    check(rejected(function () use ($pdo) { work_admin_create($pdo, '単独作品', '', '', '連載'); }, '同じ作品名'), 'Duplicate title rejected');
    $pdo->exec("INSERT INTO creators VALUES (1,'水木しげる'),(2,'原作者'),(3,'同名'),(4,'同名'),(5,'別名と同名'),(6,'別名のみ'),(7,'100%作者')");
    $pdo->exec("INSERT INTO creator_aliases (creator_id,alias_name) VALUES (1,'別名と同名'),(1,'水木別名'),(2,'共有別名'),(1,'共有別名'),(6,'水木検索別名')");
    $pdo->exec("INSERT INTO roles VALUES (1,'author'),(2,'原作'),(3,'監修')");
    $editor = new WorkCreatorEditor($pdo);
    $row = ['id' => 0, 'creator_id' => 0, 'creator_name' => '水木別名', 'role' => 'author', 'display_order' => 0];
    saveRows([$row]);
    $stored = $editor->rows($workId);
    $id = (int)$stored[0]['id'];
    check((int)$stored[0]['creator_id'] === 1 && (int)$stored[0]['default_visible'] === 1 && (int)$stored[0]['display_order'] === 0, 'Alias resolution, default visibility and order zero');
    $pdo->exec("UPDATE work_creators SET default_visible=0, updated_at='2001-01-01 00:00:00' WHERE id=$id");
    $before = $editor->rows($workId);
    saveRows($before);
    check($editor->rows($workId) === $before, 'No-op preserves ID, default_visible, timestamps and all columns');
    $rows = $before; $rows[0]['creator_name'] = '原作者'; $rows[0]['creator_id'] = 0;
    saveRows($rows);
    $rows = $editor->rows($workId);
    check((int)$rows[0]['id'] === $id && (int)$rows[0]['creator_id'] === 2 && (int)$rows[0]['default_visible'] === 0, 'Person change preserves ID and visibility');
    $rows[0]['role'] = '原作'; unset($rows[0]['default_visible']); saveRows($rows); $rows = $editor->rows($workId);
    check((int)$rows[0]['default_visible'] === 1 && (int)$rows[0]['role_id'] === 2, 'Changed role recomputes visibility and role_id');
    $rows[0]['role'] = '未登録役割'; unset($rows[0]['default_visible']); saveRows($rows); $rows = $editor->rows($workId);
    check((int)$rows[0]['default_visible'] === 0 && $rows[0]['role_id'] === null, 'Unknown role remains text with NULL role_id');
    $rows[0]['role'] = ''; $rows[0]['display_order'] = -1; saveRows($rows);
    $before = $editor->rows($workId); saveRows($before);
    check($before === $editor->rows($workId), 'Empty role and negative order preserved');
    $rows = $before;
    foreach (['author','まんが','漫画'] as $role) { $new = $row; $new['role'] = $role; $rows[] = $new; }
    saveRows($rows); $rows = $editor->rows($workId);
    check(count($rows) === 4, 'Role spelling variants remain separate');
    $bad = $rows; $bad[] = $row;
    check(rejected(function () use ($bad) { saveRows($bad); }, '重複'), 'Exact duplicate rejected');
    $bad = $rows; $new = $row; $new['role'] = 'AUTHOR'; $bad[] = $new;
    check(rejected(function () use ($bad) { saveRows($bad); }, '重複'), 'UNIQUE collation duplicate rejected');
    $bad = $rows; $bad[0]['creator_name'] = ''; $bad[0]['creator_id'] = 0;
    check(rejected(function () use ($bad) { saveRows($bad); }, '人物名'), 'Blank person does not silently delete');
    $bad = $rows; array_pop($bad);
    check(rejected(function () use ($bad) { saveRows($bad); }, '不足'), 'Missing submitted row rejected');
    $bad = $rows; $bad[0]['id'] = 999999;
    check(rejected(function () use ($bad) { saveRows($bad); }, '人物行ID'), 'Foreign/invalid row ID rejected');
    $bad = $rows; $new = $row; $new['creator_name'] = '同名'; $bad[] = $new;
    check(rejected(function () use ($bad) { saveRows($bad); }, '複数'), 'Ambiguous name rejected');
    $bad = $rows; $new['creator_name'] = '共有別名'; $bad[] = $new;
    check(rejected(function () use ($bad) { saveRows($bad); }, '複数'), 'Ambiguous alias rejected');
    $bad = $rows; $new['creator_name'] = '未登録人物'; $bad[] = $new;
    check(rejected(function () use ($bad) { saveRows($bad); }, '未登録'), 'Unknown person is not auto-created');
    check((int)$pdo->query('SELECT COUNT(*) FROM creators')->fetchColumn() === 7, 'Creator master unchanged');
    $new['creator_name'] = '同名'; $new['creator_id'] = 4; $rows[] = $new; saveRows($rows);
    $rows = $editor->rows($workId);
    check((int)$rows[count($rows)-1]['creator_id'] === 4, 'Explicit ID disambiguates same name');
    $new = $row; $new['creator_name'] = '別名と同名'; $rows[] = $new; saveRows($rows);
    $rows = $editor->rows($workId);
    check((int)$rows[count($rows)-1]['creator_id'] === 5, 'Canonical name takes precedence over alias');
    $matches = $editor->search('水木');
    check((int)$matches[0]['id'] === 1 && (int)$matches[1]['id'] === 6, 'Suggestions rank name before alias');
    check(count($editor->search('%')) === 1, 'Suggestion LIKE wildcard escaped');
    $byRole = [];
    foreach ($rows as $i => $r) { if ((int)$r['creator_id'] === 1) { $byRole[$r['role']] = $i; } }
    $first = $byRole['author']; $second = $byRole['漫画'];
    $rows[$first]['role'] = '漫画'; $rows[$second]['role'] = 'author';
    $ids = array_column($rows, 'id'); saveRows($rows);
    check(array_column($editor->rows($workId), 'id') === $ids, 'Role swap preserves all IDs without UNIQUE conflict');
    $rows = $editor->rows($workId); $fingerprint = WorkCreatorEditor::fingerprint($rows);
    $pdo->exec("UPDATE work_creators SET display_order=42 WHERE id=$id");
    check(rejected(function () use ($rows,$fingerprint) { saveRows($rows,$fingerprint); }, '他の操作'), 'Stale edit rejected');
    $rows = $editor->rows($workId); $rows[0]['delete'] = '1'; $deletedId = $rows[0]['id']; saveRows($rows);
    check(!in_array($deletedId, array_column($editor->rows($workId), 'id'), true), 'Explicit deletion removes only selected row');
    $before = $editor->rows($workId); $changed = $before; $changed[0]['display_order'] = 99;
    $pdo->beginTransaction();
    $editor->save($workId, $changed, WorkCreatorEditor::fingerprint($before));
    $pdo->exec("UPDATE works SET title='rollback' WHERE id=$workId");
    $pdo->rollBack();
    check($editor->rows($workId) === $before && $pdo->query("SELECT title FROM works WHERE id=$workId")->fetchColumn() === '単独作品', 'Transaction rolls back work and creator changes');
    check($pdo->query('SELECT role FROM content_creators')->fetchColumn() === '掲載人物', 'Content creators unchanged');
    foreach (['author','漫画','まんが','マンガ','作画','画','原作','作'] as $role) {
        if (WorkCreatorEditor::defaultVisible($role) !== 1) { throw new RuntimeException('Visibility role: ' . $role); }
    }
    check(WorkCreatorEditor::defaultVisible('監修') === 0, 'All specified visible roles and other-role default');

    $rows = $editor->rows($workId);
    $changedId = $rows[0]['id'];
    $rows[0]['default_visible'] = 1 - (int)$rows[0]['default_visible'];
    $manualVisible = $rows[0]['default_visible']; saveRows($rows);
    $rows = $editor->rows($workId);
    check($rows[0]['id'] === $changedId && (int)$rows[0]['default_visible'] === $manualVisible, 'Visibility-only change updates existing ID');
    $rows[0]['role'] = '作画'; $rows[0]['default_visible'] = '0'; saveRows($rows);
    $rows = $editor->rows($workId);
    check((int)$rows[0]['default_visible'] === 0, 'Manual OFF overrides visible role after role change');
    $rows[0]['role'] = '監修'; $rows[0]['default_visible'] = '1'; saveRows($rows);
    $rows = $editor->rows($workId);
    check((int)$rows[0]['default_visible'] === 1, 'Manual ON overrides hidden role after role change');
    $rows[0]['default_visible'] = '0'; saveRows($rows); $rows = $editor->rows($workId);
    $before = $rows; saveRows($rows);
    check($editor->rows($workId) === $before, 'Explicit unchanged OFF does not update any column');
    $bad = $rows; $bad[0]['default_visible'] = '2';
    check(rejected(function () use ($bad) { saveRows($bad); }, '表示チェック'), 'Invalid visibility rejected');
    $new = ['id' => 0, 'creator_id' => 7, 'creator_name' => '100%作者', 'role' => 'author', 'display_order' => 100, 'default_visible' => '0'];
    $rows[] = $new; saveRows($rows); $rows = $editor->rows($workId);
    check((int)$rows[count($rows)-1]['default_visible'] === 0, 'New author row honors explicit OFF');
    // Deleted UI rows submit only hidden fields; existing ID must still be accounted for.
    $last = count($rows)-1; $deletedId = $rows[$last]['id'];
    $rows[$last] = ['id' => $deletedId, 'delete' => '1']; saveRows($rows);
    check(!in_array($deletedId, array_column($editor->rows($workId), 'id'), true), 'Hidden deletion flag works without disabled inputs');

    $creator_rows = $editor->rows($workId);
    $creators_fingerprint = WorkCreatorEditor::fingerprint($creator_rows);
    function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    ob_start();
    require __DIR__ . '/../admin/work_creator_fields.php';
    $html = ob_get_clean();
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($document);
    foreach ($creator_rows as $i => $row) {
        $checkbox = $xpath->query('//input[@type="checkbox" and @name="work_creators[' . $i . '][default_visible]"]');
        if ($checkbox->length !== 1 || $checkbox[0]->hasAttribute('checked') !== ((int)$row['default_visible'] === 1)) {
            throw new RuntimeException('Rendered visibility mismatch');
        }
        foreach (['id','creator_id','creator_name','role','display_order'] as $field) {
            $nodes = $xpath->query('//input[@name="work_creators[' . $i . '][' . $field . ']"]');
            if ($nodes->length !== 1 || $nodes[0]->getAttribute('value') !== (string)$row[$field]) {
                throw new RuntimeException('Rendered field mismatch: ' . $field);
            }
        }
    }
    check(true, 'Existing person, role, order and IDs rendered correctly');

    // Exercise the actual add controller over HTTP using isolated copies and config.
    $httpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database . '_http';
    mkdir($httpRoot); mkdir($httpRoot . '/app'); mkdir($httpRoot . '/admin');
    copy(__DIR__ . '/../admin/work_add.php', $httpRoot . '/admin/work_add.php');
    copy(__DIR__ . '/../app/work_admin.php', $httpRoot . '/app/work_admin.php');
    $config = '<?php $pdo = new PDO(' . var_export($dsn . ';dbname=' . $database . ';charset=utf8mb4', true)
        . ', ' . var_export($argv[2] ?? 'root', true) . ", '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);";
    file_put_contents($httpRoot . '/app/config.php', $config);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) { throw new RuntimeException($errstr); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $log = $httpRoot . '/server.log';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $httpRoot, '-S', $address, '-t', $httpRoot],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($server)) { throw new RuntimeException('Could not start isolated HTTP server'); }
    fclose($pipes[0]);
    try {
        $curl = curl_init('http://' . $address . '/admin/work_add.php');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 5]);
        $response = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = curl_exec($curl);
            if ($response !== false) { break; }
            usleep(100000);
        }
        if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', (string)$response, $match)) {
            throw new RuntimeException('Missing CSRF token on HTTP form');
        }
        $token = $match[1];
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => $token, 'title' => '', 'publication_type' => '連載']));
        $response = curl_exec($curl);
        check(strpos($response, '作品名を入力してください') !== false && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200, 'HTTP add rejects missing title');
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => 'invalid', 'title' => '拒否作品', 'publication_type' => '連載']));
        $response = curl_exec($curl);
        check(strpos($response, '有効期限') !== false, 'HTTP add rejects invalid CSRF');
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => $token, 'title' => 'HTTP単独作品', 'publication_type' => '読切']));
        $response = curl_exec($curl);
        check(curl_getinfo($curl, CURLINFO_HTTP_CODE) === 302 && preg_match('/Location: work_edit\.php\?id=([0-9]+)&saved=1/i', $response, $redirect), 'HTTP add redirects to edit after registration');
        $stmt = $pdo->prepare('SELECT title, publication_type FROM works WHERE id=?');
        $stmt->execute([(int)$redirect[1]]); $created = $stmt->fetch();
        check($created['title'] === 'HTTP単独作品' && $created['publication_type'] === '読切'
            && (int)$pdo->query('SELECT COUNT(*) FROM issue_contents')->fetchColumn() === 0, 'HTTP-created work exists without contents');
        curl_close($curl);
    } finally {
        proc_terminate($server); proc_close($server);
        // Only remove files in the unique directory created by this test.
        foreach (['app/config.php','app/work_admin.php','admin/work_add.php','server.log'] as $file) { unlink($httpRoot . '/' . $file); }
        foreach (glob($httpRoot . '/sess_*') as $file) { unlink($file); }
        rmdir($httpRoot . '/app'); rmdir($httpRoot . '/admin'); rmdir($httpRoot);
    }
    echo "Completed $checks checks.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec("DROP DATABASE `$database`");
}
