<?php
// Only aggregate result arrays belong in this cache, never HTML or access log rows.
const RANKING_CACHE_TTL = 300;
const RANKING_CACHE_VERSION = 'v1';

function ranking_cache_key(string $applicationDirectory, DateTimeImmutable $date, int $limit): string {
    $date = $date->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return 'ranking_' . RANKING_CACHE_VERSION . '_' . substr(hash('sha256', $applicationDirectory), 0, 16)
        . '_' . $date->format('Ymd_Ym') . '_limit' . $limit;
}

function ranking_cache_has_keys(array $value, array $keys): bool {
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    return $actual === $keys;
}

function ranking_cache_valid_data($data, int $limit): bool {
    if (!is_array($data) || !ranking_cache_has_keys($data, ['rankings', 'comparisonRankings'])) {
        return false;
    }
    foreach (['rankings' => ['total', 'month', 'today'], 'comparisonRankings' => ['month', 'today']] as $group => $periods) {
        if (!is_array($data[$group]) || !ranking_cache_has_keys($data[$group], ['works', 'magazines', 'creators'])) {
            return false;
        }
        foreach ($data[$group] as $category => $results) {
            if (!is_array($results) || !ranking_cache_has_keys($results, $periods)) {
                return false;
            }
            foreach ($results as $rows) {
                if (!is_array($rows) || count($rows) > $limit
                    || ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1))) {
                    return false;
                }
                $keys = $category === 'magazines' ? ['id', 'name', 'pv', 'uu', 'publisher_name'] : ['id', 'name', 'pv', 'uu'];
                foreach ($rows as $row) {
                    if (!is_array($row) || !ranking_cache_has_keys($row, $keys) || !is_string($row['name'])) {
                        return false;
                    }
                    if ($category === 'magazines' && $row['publisher_name'] !== null && !is_string($row['publisher_name'])) {
                        return false;
                    }
                    foreach (['id', 'pv', 'uu'] as $field) {
                        if (!(is_int($row[$field]) && $row[$field] >= 0)
                            && !(is_string($row[$field]) && ctype_digit($row[$field]))) {
                            return false;
                        }
                    }
                }
            }
        }
    }
    return true;
}

function ranking_cache_read(string $path, string $key, int $limit, int $now): ?array {
    $json = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
    if ($json === false) {
        return null;
    }
    $entry = json_decode($json, true);
    if (!is_array($entry) || ($entry['key'] ?? null) !== $key
        || !isset($entry['created_at']) || !is_int($entry['created_at'])
        || $entry['created_at'] > $now || $now - $entry['created_at'] >= RANKING_CACHE_TTL
        || !ranking_cache_valid_data($entry['data'] ?? null, $limit)) {
        return null;
    }
    return $entry['data'];
}

function ranking_cache_write(string $path, string $key, array $data): void {
    $temporary = null;
    $handle = false;
    try {
        $json = json_encode(['key' => $key, 'created_at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $candidate = $path . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($candidate, 'x+b');
        if ($handle === false) {
            return;
        }
        $temporary = $candidate;
        if (!@chmod($temporary, 0600) || @fwrite($handle, $json) !== strlen($json) || !@fflush($handle)) {
            return;
        }
        fclose($handle);
        $handle = false;
        @rename($temporary, $path);
    } catch (Throwable $e) {
        // Cache storage failure must not discard successfully fetched rankings.
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($temporary !== null && is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function ranking_cache_remember(string $directory, string $key, int $limit, callable $loader): array {
    $path = $directory . '/' . $key . '.json';
    $cached = ranking_cache_read($path, $key, $limit, time());
    if ($cached !== null) {
        return $cached;
    }
    // Lock per cache key; never unlink a lock while requests may hold it.
    $lock = @fopen($directory . '/' . $key . '.lock', 'c');
    if ($lock === false) {
        return $loader()['data'];
    }
    try {
        if (!@flock($lock, LOCK_EX)) {
            return $loader()['data'];
        }
        $cached = ranking_cache_read($path, $key, $limit, time());
        if ($cached !== null) {
            return $cached;
        }
        $result = $loader();
        if ($result['cacheable'] && ranking_cache_valid_data($result['data'], $limit)) {
            ranking_cache_write($path, $key, $result['data']);
        }
        return $result['data'];
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
