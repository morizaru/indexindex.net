<?php
// aucfan_search.php
// オークファン検索リダイレクト
// 設置場所: /indexindex/aucfan_search.php
//
// オークファンの検索URLは、日本語キーワードをEUC-JPの16進数に変換した
// q-~xxxxxxxx/s-mix/?o=de 形式を使う。

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

$keyword = $_GET['q'] ?? '';
$keyword = trim((string)$keyword);

if ($keyword === '') {
    header('Location: https://aucfan.com/');
    exit;
}

// 改行などURL検索に不要な制御文字を除去
$keyword = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $keyword);
$keyword = preg_replace('/\s+/u', ' ', $keyword);
$keyword = trim($keyword);

if ($keyword === '') {
    header('Location: https://aucfan.com/');
    exit;
}

// 念のため長すぎる検索語を制限
if (mb_strlen($keyword, 'UTF-8') > 100) {
    $keyword = mb_substr($keyword, 0, 100, 'UTF-8');
}

// オークファン用: UTF-8 → EUC-JP → 16進数
// 例: ドラゴンボール 初版
// https://aucfan.com/search1/q-~a5c9a5e9a5b4a5f3a5dca1bca5eb20bde9c8c7/s-mix/?o=de
$eucjp = mb_convert_encoding($keyword, 'EUC-JP', 'UTF-8');
$hex = bin2hex($eucjp);

$url = 'https://aucfan.com/search1/q-~' . $hex . '/s-mix/?o=de';

header('Location: ' . $url, true, 302);
exit;
