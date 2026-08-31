<?php
// test_author_split.php
// 作者名の分割ロジックをテストするスクリプト

// split_creator_names 関数のコピー（テスト用）
function creator_role_label_pattern_bulk() {
    return '(?:' . implode('|', ['著', '作', '絵', '画', '文']) . ')';
}

function split_creator_names_original($creator_name) {
    $creator_name = trim((string)$creator_name);
    if ($creator_name === '') {
        return [];
    }

    $creator_name = str_replace(['／', '・'], ['/', '・'], $creator_name);

    // 修正前（スペースで分割）
    $parts = preg_split('/\s*(?:[,、\/]|\s+)\s*/u', $creator_name);
    $names = [];

    foreach ($parts as $part) {
        $part = trim((string)$part);
        $part = preg_replace('/^(' . creator_role_label_pattern_bulk() . ')\s*[:：]\s*/u', '', $part);
        $part = preg_replace('/^[\s,，,\/／、]+|[\s,，,\/／、]+$/u', '', $part);
        $part = trim((string)$part);

        if ($part !== '') {
            $names[] = $part;
        }
    }

    return array_values(array_unique($names));
}

function split_creator_names_fixed($creator_name) {
    $creator_name = trim((string)$creator_name);
    if ($creator_name === '') {
        return [];
    }

    $creator_name = str_replace(['／', '・'], ['/', '・'], $creator_name);

    // 修正後（スペースでは分割しない）
    $parts = preg_split('/\s*[,、\/]\s*/u', $creator_name);
    $names = [];

    foreach ($parts as $part) {
        $part = trim((string)$part);
        $part = preg_replace('/^(' . creator_role_label_pattern_bulk() . ')\s*[:：]\s*/u', '', $part);
        $part = preg_replace('/^[\s,，,\/／、]+|[\s,，,\/／、]+$/u', '', $part);
        $part = trim((string)$part);

        if ($part !== '') {
            $names[] = $part;
        }
    }

    return array_values(array_unique($names));
}

// テストケース
$test_cases = [
    '小林哲也、DJ SPACE',
    '小林哲也,DJ SPACE',
    '小林哲也／DJ SPACE',
    '小林哲也/DJ SPACE',
    '小林哲也、DJ SPACE、太郎',
    'Author One, Author Two, Author Three',
    '著:小林哲也、DJ SPACE',
];

echo "<!DOCTYPE html>\n";
echo "<html>\n<head>\n";
echo "<meta charset=\"UTF-8\">\n";
echo "<title>作者名分割ロジックテスト</title>\n";
echo "<style>\n";
echo "  body { font-family: Arial, sans-serif; padding: 20px; }\n";
echo "  .test-case { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }\n";
echo "  .original { background-color: #ffcccc; }\n";
echo "  .fixed { background-color: #ccffcc; }\n";
echo "  .input { font-weight: bold; color: blue; }\n";
echo "  .result { margin: 5px 0; }\n";
echo "  .result-item { padding-left: 20px; }\n";
echo "</style>\n";
echo "</head>\n<body>\n";
echo "<h1>作者名分割ロジックテスト</h1>\n";
echo "<p>修正前後の比較です。修正後（緑）でスペースを区切り文字としないことを確認します。</p>\n";

foreach ($test_cases as $input) {
    echo "<div class=\"test-case\">\n";
    echo "<div class=\"input\">入力: " . htmlspecialchars($input, ENT_QUOTES, 'UTF-8') . "</div>\n";
    
    $original = split_creator_names_original($input);
    $fixed = split_creator_names_fixed($input);
    
    echo "<div class=\"original\">\n";
    echo "<strong>修正前（問題あり）:</strong>\n";
    echo "<div class=\"result\">\n";
    foreach ($original as $author) {
        echo "<div class=\"result-item\">author:" . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . "</div>\n";
    }
    echo "</div>\n";
    echo "</div>\n";
    
    echo "<div class=\"fixed\">\n";
    echo "<strong>修正後（正常）:</strong>\n";
    echo "<div class=\"result\">\n";
    foreach ($fixed as $author) {
        echo "<div class=\"result-item\">author:" . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . "</div>\n";
    }
    echo "</div>\n";
    echo "</div>\n";
    
    echo "</div>\n";
}

echo "</body>\n</html>\n";
?>
