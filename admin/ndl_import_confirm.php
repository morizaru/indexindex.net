<?php
// admin/ndl_import_confirm.php
// INDEXINDEX - NDL JSON Import Confirm
// NDL Search の index/get JSON を貼り付けて、目次を確認後DB登録する管理用ツールです。

declare(strict_types=1);

mb_internal_encoding('UTF-8');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_text(?string $s): string
{
    $s = (string)$s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t　]+/u', ' ', $s);
    $s = preg_replace('/\r\n|\r/u', "\n", $s);
    return trim((string)$s);
}

function load_pdo(): PDO
{
    $candidates = [
        __DIR__ . '/../config.php',
        __DIR__ . '/../app/config.php',
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/config.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            break;
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }
    if (isset($db) && $db instanceof PDO) {
        return $db;
    }

    throw new RuntimeException('PDO接続が見つかりません。config.php 内に $pdo = new PDO(...) があるか確認してください。');
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function get_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cols = [];
    foreach ($rows as $row) {
        $name = (string)$row['Field'];
        $cols[$name] = $row;
    }

    $cache[$table] = $cols;
    return $cols;
}

function first_existing_col(PDO $pdo, string $table, array $candidates): string
{
    $cols = get_columns($pdo, $table);
    foreach ($candidates as $name) {
        if (isset($cols[$name])) {
            return $name;
        }
    }
    return '';
}

function pk_col(PDO $pdo, string $table): string
{
    $cols = get_columns($pdo, $table);
    foreach ($cols as $name => $info) {
        if (($info['Key'] ?? '') === 'PRI') {
            return $name;
        }
    }
    return 'id';
}

function insert_row(PDO $pdo, string $table, array $data): int
{
    $cols = get_columns($pdo, $table);
    $insert = [];

    foreach ($data as $key => $value) {
        if (isset($cols[$key])) {
            $insert[$key] = $value;
        }
    }

    if (!$insert) {
        throw new RuntimeException($table . ' にINSERT可能なカラムがありません。');
    }

    $names = array_keys($insert);
    $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`'
        . implode('`,`', array_map(fn($v) => str_replace('`', '``', $v), $names))
        . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insert));

    return (int)$pdo->lastInsertId();
}

function update_row(PDO $pdo, string $table, int $id, array $data): void
{
    $pk = pk_col($pdo, $table);
    $cols = get_columns($pdo, $table);
    $set = [];
    $values = [];

    foreach ($data as $key => $value) {
        if ($key === $pk) {
            continue;
        }
        if (isset($cols[$key])) {
            $set[] = '`' . str_replace('`', '``', $key) . '` = ?';
            $values[] = $value;
        }
    }

    if (!$set) {
        return;
    }

    $values[] = $id;
    $sql = 'UPDATE `' . str_replace('`', '``', $table) . '` SET ' . implode(', ', $set) . ' WHERE `' . str_replace('`', '``', $pk) . '` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function get_magazines(PDO $pdo): array
{
    if (!table_exists($pdo, 'magazines')) {
        return [];
    }

    $pk = pk_col($pdo, 'magazines');
    $nameCol = first_existing_col($pdo, 'magazines', ['name', 'magazine_name', 'title']);

    if ($nameCol === '') {
        return [];
    }

    $sql = 'SELECT `' . $pk . '` AS id, `' . $nameCol . '` AS name FROM `magazines` ORDER BY `' . $nameCol . '`';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}


function get_issue_detail_by_row(PDO $pdo, array $row): array
{
    $issuePk = pk_col($pdo, 'magazine_issues');
    $issueCols = get_columns($pdo, 'magazine_issues');

    $magCol = first_existing_col($pdo, 'magazine_issues', ['magazine_id']);
    $issueNoCol = first_existing_col($pdo, 'magazine_issues', ['issue_label', 'issue_number', 'issue_no', 'number', 'volume', 'name', 'title']);
    $dateCol = first_existing_col($pdo, 'magazine_issues', ['release_date', 'published_date', 'issue_date', 'publication_date', 'date']);
    $extCol = first_existing_col($pdo, 'magazine_issues', ['external_issue_id', 'external_id', 'source_record_id', 'ndl_record_id']);
    $pidCol = first_existing_col($pdo, 'magazine_issues', ['external_issue_pid', 'external_pid', 'source_pid', 'ndl_pid']);
    $urlCol = first_existing_col($pdo, 'magazine_issues', ['external_issue_url', 'external_url', 'source_url', 'ndl_url']);

    $issueId = (int)($row[$issuePk] ?? $row['id'] ?? 0);
    $magazineId = $magCol !== '' ? (int)($row[$magCol] ?? 0) : 0;
    $issueNumber = $issueNoCol !== '' ? normalize_text((string)($row[$issueNoCol] ?? '')) : '';
    $releaseDate = $dateCol !== '' ? normalize_text((string)($row[$dateCol] ?? '')) : '';
    $recordId = $extCol !== '' ? normalize_text((string)($row[$extCol] ?? '')) : '';
    $issuePid = $pidCol !== '' ? normalize_text((string)($row[$pidCol] ?? '')) : '';
    $ndlUrl = $urlCol !== '' ? normalize_text((string)($row[$urlCol] ?? '')) : '';

    if ($ndlUrl === '' && $recordId !== '') {
        $ndlUrl = 'https://ndlsearch.ndl.go.jp/books/' . $recordId;
    }

    $magazineName = '';
    $publisherName = '';

    if ($magazineId > 0 && table_exists($pdo, 'magazines')) {
        $magPk = pk_col($pdo, 'magazines');
        $magNameCol = first_existing_col($pdo, 'magazines', ['name', 'magazine_name', 'title']);
        $magPublisherIdCol = first_existing_col($pdo, 'magazines', ['publisher_id']);
        $magPublisherNameCol = first_existing_col($pdo, 'magazines', ['publisher_name', 'publisher']);

        $stmt = $pdo->prepare('SELECT * FROM `magazines` WHERE `' . str_replace('`', '``', $magPk) . '` = ? LIMIT 1');
        $stmt->execute([$magazineId]);
        $mag = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mag) {
            if ($magNameCol !== '') {
                $magazineName = normalize_text((string)($mag[$magNameCol] ?? ''));
            }
            if ($magPublisherNameCol !== '') {
                $publisherName = normalize_text((string)($mag[$magPublisherNameCol] ?? ''));
            }
            if ($publisherName === '' && $magPublisherIdCol !== '' && !empty($mag[$magPublisherIdCol]) && table_exists($pdo, 'publishers')) {
                $pubPk = pk_col($pdo, 'publishers');
                $pubNameCol = first_existing_col($pdo, 'publishers', ['name', 'publisher_name', 'title']);
                if ($pubNameCol !== '') {
                    $stmt = $pdo->prepare('SELECT `' . str_replace('`', '``', $pubNameCol) . '` FROM `publishers` WHERE `' . str_replace('`', '``', $pubPk) . '` = ? LIMIT 1');
                    $stmt->execute([(int)$mag[$magPublisherIdCol]]);
                    $publisherName = normalize_text((string)$stmt->fetchColumn());
                }
            }
        }
    }

    return [
        'issue_id' => $issueId,
        'magazine_id' => $magazineId,
        'magazine_name' => $magazineName,
        'publisher_name' => $publisherName,
        'issue_number' => $issueNumber,
        'release_date' => $releaseDate,
        'record_id' => $recordId,
        'issue_pid' => $issuePid,
        'ndl_url' => $ndlUrl,
        'index_get_url' => $recordId !== '' ? 'https://ndlsearch.ndl.go.jp/api/index/get/' . rawurlencode($recordId) . '?' : '',
    ];
}

function get_issue_detail_by_id(PDO $pdo, int $issueId): array
{
    if ($issueId <= 0 || !table_exists($pdo, 'magazine_issues')) {
        return [];
    }

    $pk = pk_col($pdo, 'magazine_issues');
    $stmt = $pdo->prepare('SELECT * FROM `magazine_issues` WHERE `' . str_replace('`', '``', $pk) . '` = ? LIMIT 1');
    $stmt->execute([$issueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? get_issue_detail_by_row($pdo, $row) : [];
}

function get_issue_detail_by_external(PDO $pdo, string $recordId, string $pid): array
{
    if (!table_exists($pdo, 'magazine_issues')) {
        return [];
    }

    $cols = get_columns($pdo, 'magazine_issues');
    $extCol = first_existing_col($pdo, 'magazine_issues', ['external_issue_id', 'external_id', 'source_record_id', 'ndl_record_id']);
    $pidCol = first_existing_col($pdo, 'magazine_issues', ['external_issue_pid', 'external_pid', 'source_pid', 'ndl_pid']);

    if ($recordId !== '' && $extCol !== '') {
        $stmt = $pdo->prepare('SELECT * FROM `magazine_issues` WHERE `' . str_replace('`', '``', $extCol) . '` = ? LIMIT 1');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return get_issue_detail_by_row($pdo, $row);
    }

    if ($pid !== '' && $pidCol !== '') {
        $stmt = $pdo->prepare('SELECT * FROM `magazine_issues` WHERE `' . str_replace('`', '``', $pidCol) . '` = ? LIMIT 1');
        $stmt->execute([$pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return get_issue_detail_by_row($pdo, $row);
    }

    return [];
}

function parse_index_v_c_item(array $row): ?array
{
    $v = normalize_text($row['v'] ?? '');
    $c = normalize_text($row['c'] ?? '');

    if ($v === '' && $c === '') {
        return null;
    }

    $title = $v;
    $creator = '';
    $page = '';

    // 通常: v=タイトル / c=作者/349 または 作者/14,120
    if ($c !== '' && preg_match('/^(.*?)\/([0-9]{1,4}(?:,[0-9]{1,4})*)$/u', $c, $m)) {
        $creator = normalize_text($m[1]);
        $page = normalize_text($m[2]);
    }

    // 作者なし: v=タイトル //115
    if ($c === '' && preg_match('/^(.*?)\s*\/\/\s*([0-9]{1,4}(?:,[0-9]{1,4})*)$/u', $v, $m)) {
        $title = normalize_text($m[1]);
        $creator = '';
        $page = normalize_text($m[2]);
    }

    // 保険: v=タイトル / 作者/349
    if ($page === '' && preg_match('/^(.*?)\s*\/\s*(.*?)\/([0-9]{1,4}(?:,[0-9]{1,4})*)$/u', $v, $m)) {
        $title = normalize_text($m[1]);
        $creator = normalize_text($m[2]);
        $page = normalize_text($m[3]);
    }

    return [
        'page' => $page,
        'title' => $title,
        'creator' => $creator,
        'raw' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ];
}

function collect_index_lists($data): array
{
    $rows = [];

    if (!is_array($data)) {
        return $rows;
    }

    if (isset($data['list']) && is_array($data['list'])) {
        foreach ($data['list'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $rows = array_merge($rows, collect_index_lists($value));
        }
    }

    return $rows;
}

function parse_index_json(string $jsonText): array
{
    $jsonText = trim($jsonText);
    if ($jsonText === '') {
        return [[], 'JSONが空です。'];
    }

    $json = json_decode($jsonText, true);
    if (!is_array($json)) {
        return [[], 'JSONとして読み込めませんでした。'];
    }

    $rows = collect_index_lists($json);
    $items = [];

    foreach ($rows as $row) {
        $item = parse_index_v_c_item($row);
        if ($item) {
            $items[] = $item;
        }
    }

    return [dedupe_toc_items($items), ''];
}

function dedupe_toc_items(array $items): array
{
    $seen = [];
    $out = [];

    foreach ($items as $item) {
        $page = normalize_text($item['page'] ?? '');
        $title = normalize_text($item['title'] ?? '');
        $creator = normalize_text($item['creator'] ?? '');
        $raw = normalize_text($item['raw'] ?? '');

        if ($page === '' && $title === '' && $creator === '') {
            continue;
        }

        $key = md5($page . '|' . $title . '|' . $creator);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $out[] = [
            'page' => $page,
            'title' => $title,
            'creator' => $creator,
            'raw' => $raw,
        ];
    }

    usort($out, function (array $a, array $b): int {
        $pa = (int)preg_replace('/[^0-9].*$/', '', (string)($a['page'] ?? '0'));
        $pb = (int)preg_replace('/[^0-9].*$/', '', (string)($b['page'] ?? '0'));

        if ($pa === 0 && $pb === 0) {
            return 0;
        }
        if ($pa === 0) {
            return 1;
        }
        if ($pb === 0) {
            return -1;
        }
        return $pa <=> $pb;
    });

    return $out;
}

function split_creators(string $creator): array
{
    $creator = normalize_text($creator);
    if ($creator === '') {
        return [];
    }

    // NDLで複数作者は「原康史 ; みのもけんじ」のように入ることが多い
    $parts = preg_split('/\s*[;；]\s*/u', $creator) ?: [];
    $out = [];

    foreach ($parts as $part) {
        $part = normalize_text($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

function find_or_create_issue(PDO $pdo, int $magazineId, string $issueNumber, string $releaseDate, string $ndlUrl): int
{
    if (!table_exists($pdo, 'magazine_issues')) {
        throw new RuntimeException('magazine_issues テーブルが見つかりません。');
    }

    $pk = pk_col($pdo, 'magazine_issues');
    $magCol = first_existing_col($pdo, 'magazine_issues', ['magazine_id']);
    $issueCol = first_existing_col($pdo, 'magazine_issues', ['issue_number', 'issue_no', 'number', 'name', 'title']);
    $dateCol = first_existing_col($pdo, 'magazine_issues', ['release_date', 'published_date', 'issue_date', 'publication_date']);

    if ($magCol === '') {
        throw new RuntimeException('magazine_issues に magazine_id カラムが見つかりません。');
    }

    // 既存号を探す
    $where = ['`' . $magCol . '` = ?'];
    $params = [$magazineId];

    if ($issueCol !== '' && $issueNumber !== '') {
        $where[] = '`' . $issueCol . '` = ?';
        $params[] = $issueNumber;
    }
    if ($dateCol !== '' && $releaseDate !== '') {
        $where[] = '`' . $dateCol . '` = ?';
        $params[] = $releaseDate;
    }

    if (count($where) >= 2) {
        $sql = 'SELECT `' . $pk . '` FROM `magazine_issues` WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $found = $stmt->fetchColumn();
        if ($found) {
            return (int)$found;
        }
    }

    $data = [
        'magazine_id' => $magazineId,
        'issue_number' => $issueNumber,
        'issue_no' => $issueNumber,
        'number' => $issueNumber,
        'name' => $issueNumber,
        'title' => $issueNumber,
        'release_date' => $releaseDate !== '' ? $releaseDate : null,
        'published_date' => $releaseDate !== '' ? $releaseDate : null,
        'issue_date' => $releaseDate !== '' ? $releaseDate : null,
        'publication_date' => $releaseDate !== '' ? $releaseDate : null,
        'ndl_url' => $ndlUrl,
        'source_url' => $ndlUrl,
        'note' => $ndlUrl !== '' ? 'NDL Search JSON import: ' . $ndlUrl : '',
        'notes' => $ndlUrl !== '' ? 'NDL Search JSON import: ' . $ndlUrl : '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return insert_row($pdo, 'magazine_issues', $data);
}

function find_or_create_creator(PDO $pdo, string $name): int
{
    $name = normalize_text($name);
    if ($name === '') {
        return 0;
    }

    if (!table_exists($pdo, 'creators')) {
        return 0;
    }

    $pk = pk_col($pdo, 'creators');
    $nameCol = first_existing_col($pdo, 'creators', ['name', 'creator_name', 'display_name']);

    if ($nameCol === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT `' . $pk . '` FROM `creators` WHERE `' . $nameCol . '` = ? LIMIT 1');
    $stmt->execute([$name]);
    $found = $stmt->fetchColumn();

    if ($found) {
        return (int)$found;
    }

    $data = [
        'name' => $name,
        'creator_name' => $name,
        'display_name' => $name,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return insert_row($pdo, 'creators', $data);
}

function create_issue_content(PDO $pdo, int $issueId, array $item, int $sortOrder): int
{
    if (!table_exists($pdo, 'issue_contents')) {
        throw new RuntimeException('issue_contents テーブルが見つかりません。');
    }

    $page = normalize_text($item['page'] ?? '');
    $title = normalize_text($item['title'] ?? '');
    $creator = normalize_text($item['creator'] ?? '');
    $raw = normalize_text($item['raw'] ?? '');

    $data = [
        'issue_id' => $issueId,
        'magazine_issue_id' => $issueId,
        'page' => $page,
        'page_number' => $page,
        'pages' => $page,
        'title' => $title,
        'title_main' => $title,
        'work_title' => $title,
        'content_title' => $title,
        'creator' => $creator,
        'creator_name' => $creator,
        'author' => $creator,
        'raw_text' => $raw,
        'source_text' => $raw,
        'note' => '',
        'notes' => '',
        'sort_order' => $sortOrder,
        'display_order' => $sortOrder,
        'content_order' => $sortOrder,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return insert_row($pdo, 'issue_contents', $data);
}

function link_content_creator(PDO $pdo, int $contentId, int $creatorId, int $sortOrder): void
{
    if ($contentId <= 0 || $creatorId <= 0) {
        return;
    }

    if (!table_exists($pdo, 'content_creators')) {
        return;
    }

    $contentCol = first_existing_col($pdo, 'content_creators', ['content_id', 'issue_content_id']);
    $creatorCol = first_existing_col($pdo, 'content_creators', ['creator_id']);

    if ($contentCol === '' || $creatorCol === '') {
        return;
    }

    // 重複防止
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `content_creators` WHERE `' . $contentCol . '` = ? AND `' . $creatorCol . '` = ?');
    $stmt->execute([$contentId, $creatorId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $data = [
        $contentCol => $contentId,
        $creatorCol => $creatorId,
        'role' => '',
        'sort_order' => $sortOrder,
        'display_order' => $sortOrder,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    insert_row($pdo, 'content_creators', $data);
}

$errors = [];
$messages = [];
$pdo = null;
$magazines = [];
$parsedItems = [];
$parseError = '';
$registered = false;
$issueDetail = [];
$issueId = (int)($_POST['issue_id'] ?? ($_GET['issue_id'] ?? 0));
$getRecordId = normalize_text((string)($_GET['record_id'] ?? ''));
$getPid = normalize_text((string)($_GET['pid'] ?? ''));

$ndlUrl = (string)($_POST['ndl_url'] ?? '');
$magazineId = (int)($_POST['magazine_id'] ?? 0);
$issueNumber = (string)($_POST['issue_number'] ?? '');
$releaseDate = (string)($_POST['release_date'] ?? '');
$indexJson = (string)($_POST['index_json'] ?? '');
$action = (string)($_POST['action'] ?? 'confirm');

try {
    $pdo = load_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $magazines = get_magazines($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($issueId > 0) {
            $issueDetail = get_issue_detail_by_id($pdo, $issueId);
        } elseif ($getRecordId !== '' || $getPid !== '') {
            $issueDetail = get_issue_detail_by_external($pdo, $getRecordId, $getPid);
        }

        if ($issueDetail) {
            $issueId = (int)($issueDetail['issue_id'] ?? 0);
            $magazineId = (int)($issueDetail['magazine_id'] ?? 0);
            $issueNumber = (string)($issueDetail['issue_number'] ?? '');
            $releaseDate = (string)($issueDetail['release_date'] ?? '');
            $ndlUrl = (string)($issueDetail['ndl_url'] ?? '');
        }
    } else {
        if ($issueId > 0) {
            $issueDetail = get_issue_detail_by_id($pdo, $issueId);
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$parsedItems, $parseError] = parse_index_json($indexJson);

    if ($parseError !== '') {
        $errors[] = $parseError;
    }

    if ($action === 'register' && !$errors) {
        if (!$pdo instanceof PDO) {
            $errors[] = 'DB接続がありません。';
        }
        if ($issueId <= 0 && $magazineId <= 0) {
            $errors[] = '登録済み雑誌号が見つかりません。先に ndl_import_year.php で雑誌号を登録するか、雑誌を選択してください。';
        }
        if ($issueId <= 0 && trim($issueNumber) === '') {
            $errors[] = '号数を入力してください。';
        }
        if ($releaseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
            $errors[] = '発売日は YYYY-MM-DD 形式で入力してください。';
        }
        if (count($parsedItems) === 0) {
            $errors[] = '登録できる目次がありません。';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $targetIssueId = $issueId > 0 ? $issueId : find_or_create_issue($pdo, $magazineId, normalize_text($issueNumber), normalize_text($releaseDate), normalize_text($ndlUrl));

                $registeredContentCount = 0;
                $registeredCreatorCount = 0;

                foreach ($parsedItems as $i => $item) {
                    $contentId = create_issue_content($pdo, $targetIssueId, $item, $i + 1);
                    $registeredContentCount++;

                    $creatorNames = split_creators((string)($item['creator'] ?? ''));
                    foreach ($creatorNames as $j => $creatorName) {
                        $creatorId = find_or_create_creator($pdo, $creatorName);
                        if ($creatorId > 0) {
                            link_content_creator($pdo, $contentId, $creatorId, $j + 1);
                            $registeredCreatorCount++;
                        }
                    }
                }

                $pdo->commit();
                $registered = true;
                $messages[] = '登録完了：issue_id=' . $targetIssueId . ' / 目次 ' . $registeredContentCount . ' 件 / 作者リンク ' . $registeredCreatorCount . ' 件';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>NDL目次インポート確認 | INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.7;
            margin: 24px;
            color: #222;
            background: #f7f7f7;
        }
        h1 { margin: 0 0 16px; font-size: 24px; }
        h2 { margin-top: 28px; font-size: 20px; }
        .box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 18px;
        }
        label { display: block; margin-top: 12px; font-weight: 700; }
        input[type="text"], input[type="date"], select, textarea {
            width: 100%;
            max-width: 980px;
            padding: 10px;
            font-size: 15px;
            box-sizing: border-box;
        }
        textarea {
            height: 260px;
            font-family: Consolas, Menlo, monospace;
            font-size: 12px;
        }
        button {
            padding: 10px 18px;
            cursor: pointer;
            font-weight: 700;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            vertical-align: top;
        }
        th { background: #eee; text-align: left; white-space: nowrap; }
        .ok { color: #067d17; font-weight: bold; }
        .ng { color: #b00020; font-weight: bold; }
        .small { color: #666; font-size: 13px; }
        .raw { color: #666; font-size: 12px; max-width: 520px; word-break: break-all; }
        .actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    </style>
</head>
<body>

<h1>NDL目次インポート確認</h1>

<?php if ($messages): ?>
    <div class="box">
        <?php foreach ($messages as $message): ?>
            <p class="ok"><?= h($message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="box">
        <?php foreach ($errors as $error): ?>
            <p class="ng"><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="box">
    <form method="post">
        <input type="hidden" name="issue_id" value="<?= h((string)$issueId) ?>">

        <?php if ($issueDetail): ?>
            <div class="box" style="background:#f3f8ff; margin:0 0 14px 0;">
                <strong>登録済み雑誌号</strong><br>
                issue_id：<?= h((string)($issueDetail['issue_id'] ?? '')) ?><br>
                雑誌：<?= h((string)($issueDetail['publisher_name'] ?? '')) ?> <?= h((string)($issueDetail['magazine_name'] ?? '')) ?><br>
                号数：<?= h((string)($issueDetail['issue_number'] ?? '')) ?><br>
                発売日：<?= h((string)($issueDetail['release_date'] ?? '')) ?><br>
                record_id：<?= h((string)($issueDetail['record_id'] ?? '')) ?><br>
                各号pid：<?= h((string)($issueDetail['issue_pid'] ?? '')) ?><br>
                <?php if (!empty($issueDetail['index_get_url'])): ?>
                    index/get：<a href="<?= h((string)$issueDetail['index_get_url']) ?>" target="_blank" rel="noopener">開く</a>
                <?php endif; ?>
            </div>
        <?php elseif ($getRecordId !== '' || $getPid !== ''): ?>
            <div class="box" style="background:#fff8e1; margin:0 0 14px 0;">
                <strong>登録済み雑誌号が見つかりません。</strong><br>
                先に <code>ndl_import_year.php</code> で雑誌号を登録してから、この画面を開いてください。
            </div>
        <?php endif; ?>

        <label>NDL Search URL</label>
        <input type="text" name="ndl_url" value="<?= h($ndlUrl) ?>" placeholder="例：https://ndlsearch.ndl.go.jp/books/R100000002-I000000039263-d1831410">

        <label>雑誌</label>
        <select name="magazine_id">
            <option value="0">選択してください</option>
            <?php foreach ($magazines as $mag): ?>
                <option value="<?= (int)$mag['id'] ?>" <?= ((int)$mag['id'] === $magazineId) ? 'selected' : '' ?>>
                    <?= h($mag['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>号数</label>
        <input type="text" name="issue_number" value="<?= h($issueNumber) ?>" placeholder="例：1987年1月号 / 1(1) / 1987-01">

        <label>発売日</label>
        <input type="date" name="release_date" value="<?= h($releaseDate) ?>">

        <label>index/get JSON</label>
        <textarea name="index_json" placeholder='ここに index/get API で取れたJSONを貼り付けます。'><?= h($indexJson) ?></textarea>

        <p class="actions">
            <button type="submit" name="action" value="confirm">確認する</button>
            <?php if (count($parsedItems) > 0 && !$registered): ?>
                <button type="submit" name="action" value="register" onclick="return confirm('この内容でDB登録します。よろしいですか？');">DB登録する</button>
            <?php endif; ?>
        </p>

        <p class="small">
            ※まず「確認する」で分解結果を確認し、問題なければ「DB登録する」を押してください。
        </p>
    </form>
</div>

<?php if (count($parsedItems) > 0): ?>
<div class="box">
    <h2>分解結果</h2>

    <p class="ok">
        目次候補が <?= count($parsedItems) ?> 件見つかりました。
    </p>

    <table>
        <tr>
            <th>No</th>
            <th>ページ</th>
            <th>タイトル</th>
            <th>作者</th>
            <th>元データ</th>
        </tr>

        <?php foreach ($parsedItems as $i => $item): ?>
            <tr>
                <td><?= (int)($i + 1) ?></td>
                <td><?= h($item['page']) ?></td>
                <td><?= h($item['title']) ?></td>
                <td><?= h($item['creator']) ?></td>
                <td class="raw"><?= h($item['raw']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$parseError): ?>
<div class="box">
    <p class="ng">目次候補が見つかりませんでした。</p>
</div>
<?php endif; ?>

<div class="box">
    <h2>使い方</h2>
    <ol>
        <li>NDL Search URLを入力します。</li>
        <li>雑誌・号数・発売日を入力します。</li>
        <li>index/get APIで取れたJSONを貼り付けます。</li>
        <li>「確認する」で32件などの分解結果を確認します。</li>
        <li>問題なければ「DB登録する」を押します。</li>
    </ol>
</div>

</body>
</html>
