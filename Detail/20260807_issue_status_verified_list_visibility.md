# 2026-08-07 雑誌号一覧 公開状態「現物確認済」表示修正

## 対象

- `indexindex/issues.php`
- 確認対象URL: `https://morizaru.net/indexindex/issues.php?magazine_id=3`
- 編集画面例: `https://morizaru.net/indexindex/admin/issue_edit.php?issue_id=17819&saved=1`

## 修正内容

`issues.php` の雑誌号一覧・絞り込み候補・年一覧・件数取得で、公開対象の条件が `mi.status = 'approved'` 固定になっていた。

そのため、管理画面で公開状態を `verified`（現物確認済）に変更すると、個別ページ側では公開対象であるにもかかわらず、雑誌号一覧では除外されていた。

公開対象条件を次のように統一した。

```sql
mi.status IN ('approved', 'in_progress', 'verified')
```

これにより、一覧で表示される状態は以下になる。

- `approved`（公開）
- `in_progress`（登録途中）
- `verified`（現物確認済）

一覧で表示されない状態は以下になる。

- `pending`（保留）
- `hidden`（非表示）

## 確認

- `issues.php` 内に残っていた `mi.status = 'approved'` 固定条件がなくなったことを確認。
- `issue.php` 側の公開対象条件 `approved / in_progress / verified` と整合するようにした。

## 備考

ローカル環境で `php -l indexindex/issues.php` を実行しようとしたが、`php` コマンドが認識されなかったため、PHP構文チェックは未実行。
