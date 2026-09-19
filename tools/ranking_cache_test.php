<?php
// CLI-only regression checks. Uses an isolated temporary directory, never the app DB.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/ranking_cache.php';
function cache_check($condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
function cache_fixture(): array {
    $data = ['rankings' => [], 'comparisonRankings' => []];
    foreach (['works', 'magazines', 'creators'] as $category) {
        $row = ['id' => '1', 'name' => '作品<&>', 'pv' => '20', 'uu' => '10'];
        if ($category === 'magazines') { $row['publisher_name'] = null; }
        foreach (['total', 'month', 'today'] as $period) { $data['rankings'][$category][$period] = [$row]; }
        foreach (['month', 'today'] as $period) { $data['comparisonRankings'][$category][$period] = [$row]; }
    }
    return $data;
}
$directory = sys_get_temp_dir() . '/ranking_cache_test_' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
try {
    $fixture = cache_fixture();
    $calls = 0;
    $loader = function () use (&$calls, $fixture) { $calls++; return ['data' => $fixture, 'cacheable' => true]; };
    $date = new DateTimeImmutable('2026-09-30 23:59:59', new DateTimeZone('Asia/Tokyo'));
    foreach ([10, 20, 50] as $limit) {
        $key = ranking_cache_key('/site/indexindex', $date, $limit);
        $path = $directory . '/' . $key . '.json';
        $n = $calls;
        cache_check(ranking_cache_remember($directory, $key, $limit, $loader) === $fixture && $calls === $n + 1, "limit $limit miss");
        cache_check(ranking_cache_remember($directory, $key, $limit, $loader) === $fixture && $calls === $n + 1, "limit $limit hit");
        $entry = json_decode(file_get_contents($path), true);
        cache_check(ranking_cache_read($path, $key, $limit, $entry['created_at'] + 299) === $fixture, 'TTL 299 valid');
        cache_check(ranking_cache_read($path, $key, $limit, $entry['created_at'] + 300) === null, 'TTL 300 expired');
        foreach (['expired', 'broken_json', 'missing_category', 'wrong_type', 'wrong_version', 'wrong_limit', 'wrong_date', 'wrong_month', 'future'] as $case) {
            $bad = $entry;
            if ($case === 'expired') { $bad['created_at'] = time() - RANKING_CACHE_TTL; }
            if ($case === 'missing_category') { unset($bad['data']['rankings']['works']); }
            if ($case === 'wrong_version') { $bad['key'] = 'ranking_v0'; }
            if ($case === 'wrong_type') { $bad['data']['rankings']['works']['total'][0]['pv'] = []; }
            if ($case === 'wrong_limit') { $bad['key'] = ranking_cache_key('/site/indexindex', $date, $limit === 10 ? 20 : 10); }
            if ($case === 'wrong_date') { $bad['key'] = ranking_cache_key('/site/indexindex', $date->modify('-1 day'), $limit); }
            if ($case === 'wrong_month') { $bad['key'] = str_replace('_202609_limit', '_202608_limit', $key); }
            if ($case === 'future') { $bad['created_at'] = time() + 3600; }
            file_put_contents($path, $case === 'broken_json' ? '{broken' : json_encode($bad));
            $n = $calls;
            cache_check(ranking_cache_remember($directory, $key, $limit, $loader) === $fixture && $calls === $n + 1, "$case regenerates");
        }
        unlink($path);
        $failed = function () use ($fixture) { return ['data' => $fixture, 'cacheable' => false]; };
        cache_check(ranking_cache_remember($directory, $key, $limit, $failed) === $fixture && !file_exists($path), 'SQL failure result not cached');
        try { ranking_cache_remember($directory, $key, $limit, function () { throw new RuntimeException('loader failed'); }); }
        catch (RuntimeException $e) { /* legacy exception path, never a successful cache */ }
        cache_check(!file_exists($path), 'loader exception not cached');
        cache_check(ranking_cache_remember($directory, $key, $limit, $loader) === $fixture, 'lock released after exception');
        unlink($path);
        mkdir($path); // Force atomic rename failure without depending on the test user's permissions.
        cache_check(ranking_cache_remember($directory, $key, $limit, $loader) === $fixture && is_dir($path), 'save failure still returns DB data');
        rmdir($path);
    }
    $key = ranking_cache_key('/site/indexindex', $date, 10);
    cache_check($key !== ranking_cache_key('/site/indexindex', $date->modify('+1 second'), 10), 'month/day boundary key');
    cache_check($key !== ranking_cache_key('/site/indexindex', $date->modify('-1 day'), 10), 'day boundary within same month');
    cache_check($key !== ranking_cache_key('/site/indexindex_test', $date, 10), 'environment isolation');
    cache_check($key === ranking_cache_key('/site/indexindex', $date->setTimezone(new DateTimeZone('UTC')), 10), 'Tokyo timezone normalization');
    $n = $calls;
    cache_check(ranking_cache_remember($directory . '/absent', $key, 10, $loader) === $fixture && $calls === $n + 1, 'missing directory fallback');
    $invalid = $fixture;
    $invalid['rankings']['works']['total'][0]['visitor_hash'] = 'must not be stored';
    cache_check(!ranking_cache_valid_data($invalid, 10), 'raw data rejected');
    $empty = $fixture;
    foreach ($empty as &$categories) { foreach ($categories as &$periods) { foreach ($periods as &$rows) { $rows = []; } unset($rows); } unset($periods); } unset($categories);
    cache_check(ranking_cache_valid_data($empty, 10), 'legitimate zero results accepted');
    cache_check(glob($directory . '/*.tmp') === [], 'no temporary files remain');
} finally {
    foreach (glob($directory . '/*') as $path) { if (is_dir($path)) { rmdir($path); } else { unlink($path); } }
    rmdir($directory);
}
