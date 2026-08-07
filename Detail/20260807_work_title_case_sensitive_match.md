# 2026-08-07 作品タイトル 大文字小文字区別の登録・編集対応

## 対象

- `indexindex/admin/work_edit.php`
- `indexindex/admin/content_add.php`
- `indexindex/admin/content_edit.php`
- `indexindex/admin/content_bulk_add.php`
- `indexindex/admin/madb_import.php`
- `indexindex/admin/madb_import_year.php`

## 要望

作品タイトルで、次のような大文字小文字だけが異なるタイトルを別作品として登録・修正できるようにする。

```text
Switch
switch
```

## 確認結果

ローカル環境には `indexindex/admin/work_add.php` は存在しなかった。

作品の新規作成は、主に以下の画面・処理で行われていた。

- 目次追加: `content_add.php`
- 目次編集: `content_edit.php`
- 目次一括追加: `content_bulk_add.php`
- MADB取込: `madb_import.php`
- MADB年別取込: `madb_import_year.php`

作品編集画面 `work_edit.php` では、タイトル変更時の重複チェックが行われていた。

## 原因

各処理で、既存作品の検索・重複チェックに次の条件を使っていた。

```sql
WHERE title = :title
```

MySQL の照合順序が大文字小文字を区別しない設定の場合、この比較では `Switch` と `switch` が同じ値として扱われる。

そのため、大文字小文字だけが異なる作品タイトルを別作品として扱えなかった。

## 修正内容

既存作品の検索・重複チェックを、大文字小文字を区別する比較に変更した。

```sql
WHERE BINARY title = BINARY :title
```

これにより、`Switch` と `switch` は別タイトルとして扱われる。

## 修正箇所

- `work_edit.php`
  - タイトル変更時の重複チェックを `BINARY` 比較に変更。
- `content_add.php`
  - 目次追加時の既存作品検索を `BINARY` 比較に変更。
- `content_edit.php`
  - 目次編集時の既存作品検索を `BINARY` 比較に変更。
- `content_bulk_add.php`
  - 一括追加時の `get_or_create_work_id()` 内の既存作品検索を `BINARY` 比較に変更。
- `madb_import.php`
  - MADB取込時の既存作品検索を `BINARY` 比較に変更。
- `madb_import_year.php`
  - MADB年別取込時の既存作品検索を `BINARY` 比較に変更。

## 確認

- `indexindex/admin` 配下で `WHERE title = :title` が残っていないことを確認。
- `BINARY title = BINARY :title` に置き換わっていることを確認。

## 備考

DB側で `works.title` に大文字小文字を区別しない UNIQUE 制約が設定されている場合、アプリ側の修正だけでは登録時にDB制約エラーになる可能性がある。その場合は、DBインデックスまたは照合順序側の確認・変更が必要。

ローカル環境では `php` コマンドが認識されないため、`php -l` による構文チェックは未実行。
