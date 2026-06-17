<?php
// admin/index.php

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>管理ページ - INDEXINDEX</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 24px;
            background: #f5f5f5;
            color: #222;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
        }

        h1 {
            margin-top: 0;
        }

        ul {
            line-height: 2;
        }

        .link-group-title {
            margin: 24px 0 6px;
            font-weight: bold;
        }

        a {
            color: #0066cc;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .note {
            color: #666;
            font-size: 14px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>INDEXINDEX 管理ページ</h1>

    <div class="link-group-title">登録</div>
    <ul>
        <li><a href="issue_add.php">雑誌を登録する</a></li>
        <li><a href="magazine_add.php">雑誌（単体）を登録する</a></li>
        <li><a href="content_add.php">目次を登録する</a></li>
        <li><a href="content_bulk_add.php">目次を登録する（一括）</a></li>
        <li><a href="creator_add.php">人物を登録する</a></li>
        <li><a href="publisher_add.php">出版社を登録する</a></li>
        <li><a href="episode_format_add.php">話数フォーマットを登録する</a></li>
        <li><a href="source_image_add.php">出典画像を登録する</a></li>
        <li><a href="work_mediamix_add.php">メディアミックスを登録する</a></li>
    </ul>

    <div class="link-group-title">一覧</div>
    <ul>
        <li><a href="issues.php">雑誌一覧</a></li>
        <li><a href="magazines.php">雑誌（単体）一覧</a></li>
        <li><a href="works.php">作品一覧</a></li>
        <li><a href="content_list.php">目次一覧</a></li>
        <li><a href="creators.php">人物一覧</a></li>
        <li><a href="publishers.php">出版社一覧</a></li>
        <li><a href="episode_formats.php">話数フォーマット一覧</a></li>
        <li><a href="source_image_list.php">出典画像一覧</a></li>
        <li><a href="work_mediamix_list.php">メディアミックス一覧</a></li>
    </ul>

    <div class="link-group-title">TOOL</div>
    <ul>
        <li><a href="access_analytics.php">アクセスログ</a></li>
        <li><a href="x_post_draft.php">X投稿用下書き</a></li>
        <li><a href="duplicate_check.php">重複チェック</a></li>
        <li><a href="ndl_toc_url_finder.php">NDL漫画ジャンル目次URL抽出</a></li>
    </ul>

    <div class="note">
        
    </div>

</div>
</body>
</html>
