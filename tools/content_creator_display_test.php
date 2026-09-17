<?php
// Display/query tests on a disposable local MySQL/MariaDB; no application config.
// php tools/content_creator_display_test.php mysql:host=127.0.0.1;port=13318 root
require_once __DIR__ . '/../admin/content_creator_display.php';
require_once __DIR__ . '/../app/content_creators.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}
$base = ['creator_name'=>'梅沢由香里', 'role'=>'author', 'display_name'=>null,
    'display_title'=>null, 'display_affiliation'=>null, 'is_visible'=>1];
foreach ([
    [[], '梅沢由香里'],
    [['display_name'=>'YUKARI UMEZAWA'], 'YUKARI UMEZAWA'],
    [['display_title'=>'二段'], '梅沢由香里二段'],
    [['display_affiliation'=>'日本棋院'], '梅沢由香里（日本棋院）'],
    [['display_name'=>'YUKARI UMEZAWA','display_title'=>'二段','display_affiliation'=>'日本棋院'], 'YUKARI UMEZAWA二段（日本棋院）'],
    [['display_name'=>'','display_title'=>'','display_affiliation'=>''], '梅沢由香里'],
    [['role'=>'まんが','creator_name'=>'高橋留美子'], 'まんが：高橋留美子'],
    [['role'=>'まんが','display_name'=>'RUMIKO TAKAHASHI'], 'まんが：RUMIKO TAKAHASHI'],
    [['role'=>'キャラクター原案','creator_name'=>'人物C','display_affiliation'=>'○○プロ'], 'キャラクター原案：人物C（○○プロ）'],
    [['role'=>''], '梅沢由香里'],
    [['role'=>'AUTHOR'], 'AUTHOR：梅沢由香里'],
    [['display_name'=>'0'], '0'],
] as [$changes, $expected]) {
    check(content_creator_display_label(array_merge($base, $changes)) === $expected, $expected);
}
$html = render_admin_content_creators([array_merge($base, ['is_visible'=>0,'display_title'=>'二段'])]);
check(strpos($html, '非表示') !== false && strpos($html, '梅沢由香里二段') !== false && strpos($html, 'author：') === false, 'Hidden author retained and marked without author prefix');
$malicious = array_merge($base, ['role'=>'<script>', 'display_name'=>'<img src=x onerror=alert(1)>', 'display_title'=>'<&', 'display_affiliation'=>'"<b>']);
$html = render_admin_content_creators([$malicious]);
check(strpos($html, '<script>') === false && strpos($html, '<img') === false && strpos($html, '&lt;img') !== false && strpos($html, '&lt;b&gt;') !== false, 'All displayed fields HTML escaped');
check(render_admin_content_creators([]) === '', 'Empty creator list');

class CountingDisplayPDO extends PDO {
    public $prepares = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}
$dsn = $argv[1] ?? '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+$/D', $dsn)) {
    throw new RuntimeException('Specify a disposable localhost MySQL server without a database name.');
}
$pdo = new CountingDisplayPDO($dsn . ';charset=utf8mb4', $argv[2] ?? 'root', '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false]);
$database = 'test_creator_display_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `$database`");
try {
$pdo->exec('CREATE TABLE issue_contents (id INTEGER PRIMARY KEY, issue_id INTEGER, work_id INTEGER, sort_order INTEGER, page_start INTEGER, page_end INTEGER, title_main TEXT, episode_title TEXT, episode_no TEXT, note TEXT, publication_type TEXT, is_new_series INTEGER, is_final_episode INTEGER)');
$pdo->exec('CREATE TABLE works (id INTEGER PRIMARY KEY, title_sub TEXT)');
$pdo->exec('CREATE TABLE creators (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE work_creators (id INTEGER PRIMARY KEY, work_id INTEGER, creator_id INTEGER, role_id INTEGER, role TEXT, display_order INTEGER, default_visible INTEGER)');
$pdo->exec('CREATE TABLE content_creators (id INTEGER PRIMARY KEY, content_id INTEGER, creator_id INTEGER, role_id INTEGER, role TEXT, display_name TEXT, display_title TEXT, display_affiliation TEXT, display_order INTEGER, is_visible INTEGER)');
$pdo->exec("INSERT INTO issue_contents (id,issue_id,sort_order,title_main) VALUES (10,1,1,'作品A'), (11,1,2,'作品B'),(12,2,1,'別の号')");
$pdo->exec("INSERT INTO creators VALUES (1,'梅沢由香里'),(2,'高橋留美子')");
$pdo->exec("INSERT INTO content_creators VALUES (1,10,1,NULL,'author',NULL,'二段','日本棋院',2,1), (2,10,2,NULL,'まんが','RUMIKO TAKAHASHI',NULL,NULL,1,0), (3,11,1,NULL,'監修',NULL,NULL,NULL,1,1), (4,12,1,NULL,'author','別の号のみ',NULL,NULL,1,1)");
$map = load_issue_content_creators($pdo, 1);
check($pdo->prepares === 1 && count($map) === 2, 'One query loads all issue creators');
check(array_column($map[10], 'id') === [2,1], 'Display order retained, including hidden row');
check(!isset($map[12]), 'Other issue excluded');
check(strpos(render_admin_content_creators($map[10]), '［非表示］') !== false, 'Database hidden state visible in admin');
$count = $pdo->prepares;
check(load_issue_content_creators($pdo, 0) === [] && $pdo->prepares === $count, 'No issue selected does not query');

// Run the actual list SELECTs and render each page's actual creator cell.
$issue_id = 1;
foreach (['content_list.php','content_edit.php','content_bulk_add.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../admin/' . $file);
    if ($file === 'content_list.php') {
        preg_match('/\$sql_contents = "(.*?)";/s', $source, $match);
    } else {
        $marker = $file === 'content_edit.php' ? '// 同じ雑誌号の登録済み目次' : '// 登録済み目次一覧';
        preg_match('/\$pdo->prepare\("(.*?)"\)/s', substr($source, strpos($source, $marker)), $match);
    }
    $stmt = $pdo->prepare($match[1]); $stmt->execute([':issue_id'=>$issue_id]);
    $contents = $stmt->fetchAll();
    check(array_column($contents, 'id') === [10,11], $file . ' listing SQL preserves content rows');
    $content_creators_by_id = load_issue_content_creators($pdo, $issue_id);
    check(substr_count($source, 'load_issue_content_creators($pdo, $issue_id)') === 1, $file . ' loads once outside row rendering');
    preg_match('/<td[^>]*><\?= render_admin_content_creators\(.*?\?><\/td>/', $source, $cell);
    $row = $content = $contents[0]; ob_start(); eval('?>' . $cell[0]); $html = ob_get_clean();
    check(strpos($html,'RUMIKO TAKAHASHI') !== false && strpos($html,'梅沢由香里二段（日本棋院）') !== false && strpos($html,'非表示') !== false, $file . ' renders saved snapshots');
}

// Use the real edit writer, then reload exactly as a return to the list does.
$writer = new ContentCreatorWriter($pdo);
$old = $map[10][1]; $old['role_id'] = null;
$pdo->beginTransaction();
$writer->updateIfChanged(10, $old, 1, null, 'author', 2, 1,
    ['display_name'=>'YUKARI UMEZAWA','display_title'=>'九段','display_affiliation'=>'更新後所属']);
$pdo->commit();
$fresh = load_issue_content_creators($pdo, 1);
check(strpos(render_admin_content_creators($fresh[10]), 'YUKARI UMEZAWA九段（更新後所属）') !== false, 'Saved edit reflected on fresh list load');
check($fresh[10][1]['id'] === 1, 'Read/display path retains stored ID');
echo "Completed $checks checks.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec("DROP DATABASE `$database`");
}
