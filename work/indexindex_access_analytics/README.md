# INDEXINDEX アクセス解析

## ファイル
- sql/access_logs.sql
- includes/access_logger.php
- admin/access_analytics.php

## 設置
1. sql/access_logs.sql を phpMyAdmin などで実行
2. includes/access_logger.php を /indexindex/includes/ に設置
3. admin/access_analytics.php を /indexindex/admin/ に設置
4. 公開側PHPの先頭付近に以下を追加

```php
require_once __DIR__ . '/includes/access_logger.php';
ii_access_log('ページタイトル');
```

## 注意
- admin配下、css/js画像などの静的ファイルはログ対象外
- IPアドレスは平文保存せず匿名ハッシュ保存
- botは記録するが管理画面集計からは除外
