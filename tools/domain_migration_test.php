<?php
require __DIR__ . '/../includes/public_url.php';
require __DIR__ . '/../includes/access_logger.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } echo "PASS: $message\n"; }
$_GET = ['page'=>'2', 'title_q'=>'A & B', 'utm_source'=>'old', 'year'=>['bad']];
check(ii_public_canonical('works.php', ['title_q','year','page']) === 'https://indexindex.net/works.php?title_q=A%20%26%20B&page=2', 'Filters and paging retained, arrays/tracking excluded');
$_GET = ['year'=>''];
check(ii_public_canonical('mediamix.php', ['type','year']) === 'https://indexindex.net/mediamix.php?year=', 'Empty year means all years');
$_GET = [];
check(ii_public_canonical('index.php') === 'https://indexindex.net/index.php', 'Home canonical agrees with sitemap');
foreach (['indexindex.net','www.indexindex.net','INDEXINDEX.NET:443'] as $host) {
    check(ii_access_logical_path('/work.php', $host) === '/indexindex/work.php', 'New host retains ranking path: '.$host);
    check(ii_access_logical_path('/admin/test.php', $host) === '/indexindex/admin/test.php', 'Admin exclusion retained: '.$host);
}
check(ii_access_logical_path('/indexindex/work.php', 'morizaru.net') === '/indexindex/work.php', 'Legacy path unchanged');
check(ii_access_logical_path('/', 'morizaru.net') === '/', 'Other site home unchanged');
check(ii_access_logical_path('/work.php', 'indexindex.net.example.org') === '/work.php', 'Host match is exact');
foreach (['index','issues','issue','works','work','creators','creator','ranking','recent_updates','mediamix','search','about','how_to_use','data_policy','terms','contact','privacy','work_graph'] as $page) {
    $source = file_get_contents(__DIR__.'/../'.$page.'.php');
    check(substr_count($source, 'rel="canonical"') === 1, $page.' has exactly one canonical');
    check(strpos($source, 'https://morizaru.net/indexindex') === false, $page.' has no old public URL');
}
echo "Domain migration checks passed.\n";
