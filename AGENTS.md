# INDEXINDEX Codex 作業ルール

## プロジェクト概要
INDEXINDEX は、漫画雑誌の目次データを管理・公開する PHP/MySQL サイトです。

## 重要な運用ルール
- 本番URL: /indexindex/
- テストURL: /indexindex_test/
- ローカルでは indexindex フォルダのみを編集する
- 変更後は indexindex_test にアップロードして確認する
- 問題なければ indexindex にアップロードする

## 編集対象
主に以下を編集対象とする。

- admin/
- includes/
- app/
- public側PHP

## 注意事項
- app/config.php は編集しない
- DB接続情報、パスワード、APIキーをGitに含めない
- uploads/ 配下をGitに含めない
- 既存の画面デザインと操作感を大きく変えない
- 1回の変更は小さくする
- 修正後は差分を確認してからコミットする

## admin系ページの方針
- 一覧ページ、追加ページ、編集ページの見た目と操作感を揃える
- 共通化できるCSSやヘッダー・フッターは共通ファイル化する
- ただし、動作しているDB処理は不用意に変更しない
- 既存機能を壊さないことを最優先にする

## 文字コード
- PHPファイルは UTF-8 を基本とする