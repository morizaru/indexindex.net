# 2026-08-08 X投稿下書きの件数集計修正

## 対象

- [indexindex/admin/x_post_draft.php](../admin/x_post_draft.php)

## 確認内容

管理画面の X 投稿用下書き画面で表示・保存されている件数が、トップページの「現在の収録状況」や管理画面の登録件数と一致しないケースがあることを確認した。

## 原因

[indexindex/admin/x_post_draft.php](../admin/x_post_draft.php) では、現在のサイト統計を取得するために SQL で件数を集計していたが、雑誌・雑誌号・目次件数については公開状態 `approved` を前提にした条件で絞り込んでいた。

その結果、登録件数として扱うべき値とずれて、下書き文面やスナップショットに反映される件数が実登録数と一致しない状態になっていた。

## 修正内容

X 投稿下書きで使う集計 SQL を、登録済みデータの総数をカウントするように変更した。

具体的には、次のように変更した。

- 登録雑誌数: `SELECT COUNT(*) FROM magazines`
- 登録雑誌号数: `SELECT COUNT(*) FROM magazine_issues`
- 登録目次件数: `SELECT COUNT(*) FROM issue_contents`
- 登録人物数: `creators` と `content_creators` を結合し、`is_visible = 1` を満たす件数をカウント

## 確認結果

- 対象ファイル [indexindex/admin/x_post_draft.php](../admin/x_post_draft.php) にエディタ上の構文エラーはない。
- PHP CLI がこの環境に存在しないため、`php -l` の実行は未実施。

## 備考

今回の修正は、X 投稿下書きの値もトップページや管理画面の登録件数と同じ基準で表示・保存するためのもの。
