<?php
// Only pages without an existing canonical use this helper.
// Preserve content filters and paging, while excluding unrelated tracking parameters.
function ii_public_canonical(string $page, array $parameters = []): string {
    $query = [];
    foreach ($parameters as $name) {
        if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
            $query[$name] = (string)$_GET[$name];
        }
    }
    $suffix = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return 'https://indexindex.net/' . $page . ($suffix !== '' ? '?' . $suffix : '');
}
