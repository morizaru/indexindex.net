<?php
// Local in-memory checks only; never load application config or connect to production.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/work_creators.php';
require_once __DIR__ . '/../app/content_creators.php';
class NewWorkTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace([' FOR UPDATE', 'BINARY '], '', $query);
        $query = str_replace('CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci', '? COLLATE NOCASE', $query);
        return parent::prepare($query, $options);
    }
}
function verify($ok, $label) {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
$pdo = new NewWorkTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE works (id INTEGER PRIMARY KEY, title TEXT, publication_type TEXT);
CREATE TABLE creators (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE creator_aliases (creator_id INTEGER, alias_name TEXT);
CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE work_creators (id INTEGER PRIMARY KEY, work_id INTEGER, creator_id INTEGER, role_id INTEGER, role TEXT, display_order INTEGER, default_visible INTEGER, UNIQUE(work_id,creator_id,role));
CREATE TABLE issue_contents (id INTEGER PRIMARY KEY, work_id INTEGER);
CREATE TABLE content_creators (id INTEGER PRIMARY KEY, content_id INTEGER, creator_id INTEGER, role_id INTEGER, role TEXT, display_order INTEGER, is_visible INTEGER, display_name TEXT, display_title TEXT, display_affiliation TEXT);
INSERT INTO creators VALUES (1,"A"),(2,"B");
INSERT INTO creator_aliases VALUES (1,"別名A");
INSERT INTO roles VALUES (1,"author"),(2,"まんが"),(3,"原作"),(4,"漫画");
INSERT INTO content_creators (id,role) VALUES (1,"過去号人物");');
$source = file_get_contents(__DIR__ . '/../admin/content_bulk_add.php');
$start = strpos($source, 'function get_or_create_work_id(');
$end = strpos($source, 'function get_or_create_role_id_bulk(', $start);
eval(substr($source, $start, $end - $start));
$editor = new WorkCreatorEditor($pdo);
$past = $pdo->query('SELECT * FROM content_creators WHERE id=1')->fetchAll();
function initialize($title, array $credits) {
    global $pdo, $editor;
    $pdo->beginTransaction();
    try {
        $work = get_or_create_work_id($pdo, $title, '連載', 0, $created);
        $pdo->prepare('INSERT INTO issue_contents (work_id) VALUES (?)')->execute([$work]);
        $content = (int)$pdo->lastInsertId();
        $writer = new ContentCreatorWriter($pdo);
        $rows = [];
        foreach ($credits as $i => [$name, $role]) {
            $id = get_or_create_creator_id($pdo, $name);
            $writer->insertIfMissing($content, $id, $writer->findRoleId($role), $role, $i + 1, 1);
            $rows[] = ['creator_id' => $id, 'creator_name' => $name, 'role' => $role, 'display_order' => $i + 1];
        }
        if ($created) { $editor->initializeNewWork($work, $rows); }
        $pdo->commit();
        return $work;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
$a = initialize('author作品', [['NewAuthor', 'author']]);
verify(count($editor->rows($a)) === 1 && $editor->rows($a)[0]['role'] === 'author', 'new work, one author');
$b = initialize('複数人物', [['NewManga', 'まんが'], ['NewOriginal', '原作']]);
$rows = $editor->rows($b);
verify(array_column($rows, 'role') === ['まんが', '原作'] && array_column($rows, 'display_order') === [1,2], 'two exact roles and order');
verify(array_column($rows, 'default_visible') === [1,1], 'work visibility uses existing role defaults');
$c = initialize('重複防止', [['A', 'author'], ['A', '漫画']]);
verify(count($editor->rows($c)) === 1 && $editor->rows($c)[0]['role'] === '漫画', 'same ID keeps actual role without author');
initialize('複数人物', [['A','author']]);
verify($editor->rows($b) === $rows, 'existing work is never initialized again');
function listingIds($work) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT cc.creator_id FROM content_creators cc JOIN issue_contents ic ON ic.id=cc.content_id WHERE ic.work_id=? ORDER BY cc.id');
    $stmt->execute([$work]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
verify(listingIds($a) === array_column($editor->rows($a), 'creator_id'), 'new author uses same ID in both tables');
verify(array_slice(listingIds($b),0,2) === array_column($editor->rows($b), 'creator_id'), 'two new people use same IDs in both tables');
verify(count(listingIds($b)) === 3, 'existing work still receives listing credits');
$mixed = initialize('Mixed', [['A','まんが'], ['NewMixed','原作']]);
verify(listingIds($mixed) === array_column($editor->rows($mixed), 'creator_id') && listingIds($mixed)[0] === 1, 'existing/new mixed IDs match');
verify((int)$pdo->query('SELECT COUNT(*) FROM creators')->fetchColumn() === 6, 'only missing people created');
$counts = [];
foreach (['works','creators','issue_contents','content_creators','work_creators'] as $table) { $counts[$table] = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
$pdo->exec("CREATE TRIGGER fail_standard BEFORE INSERT ON work_creators BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
try { initialize('Failure', [['NewFailure','author']]); throw new LogicException('Expected failure'); }
catch (PDOException $e) { verify(str_contains($e->getMessage(),'forced failure'), 'work credits failure injected'); }
$pdo->exec('DROP TRIGGER fail_standard');
foreach ($counts as $table=>$count) { verify($pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === $count, "rollback $table"); }
$pdo->beginTransaction();
$rows[0]['role'] = '漫画'; $rows[0]['display_order'] = 5; $rows[0]['default_visible'] = 0;
$rows[1]['delete'] = '1';
$rows[] = ['creator_name'=>'B','role'=>'作画','display_order'=>2,'default_visible'=>1];
$editor->save($b, $rows, WorkCreatorEditor::fingerprint($editor->rows($b)));
$pdo->commit();
$updated = $editor->rows($b);
verify(array_column($updated,'role') === ['作画','漫画'] && $updated[1]['default_visible'] === 0, 'existing editor add/delete/role/order/visibility');
verify($pdo->query('SELECT * FROM content_creators WHERE id=1')->fetchAll() === $past, 'past listing credits untouched');
$public = file_get_contents(__DIR__ . '/../work.php');
verify(str_contains($public, 'FROM work_creators wc') && str_contains($public, 'ORDER BY wc.display_order ASC, wc.id ASC'), 'public display still reads ordered work credits');
