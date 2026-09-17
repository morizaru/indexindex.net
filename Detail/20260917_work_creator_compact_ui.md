# 作品人物欄のコンパクト化

この変更は `20260917_work_editor_and_add.md` の人物UI・default_visible保存仕様を更新する。

## ファイル

- 変更：`admin/work_creator_fields.php`、`app/work_creators.php`、`tools/work_creators_test.php`。
- 追加：`tools/work_creator_ui_test.php`、本報告書。
- `admin/work_edit.php` は既存のincludeを通して新しい人物欄を使用するため、今回直接の変更なし。
- `admin/content_edit.php` は参照のみ。DB構造、既存データの一括更新、content_creatorsへの変更はなし。

## UI

content_edit.phpのcreator-list／creator-row、5列グリッド、8pxの間隔、役割と人物名の1:2の幅比、表示順90px、creator-visibleチェックボックス、creator-removeボタン、creator-addボタンの配置と余白を参考にした。CSSは作品人物欄内に限定し、掲載一覧等への影響を避けた。

PCでは「表示・役割・人物名・表示順・削除」の1行。人物IDは小さい補助文字。候補は人物名の直下に表示。700px以下で人物名を次行へ、400px以下では表示順と削除も折り返す。役割は自由入力のまま、空文字や表記を変換しない。名前→別名検索、候補の人物ID保持も維持。

## 表示チェック

既存default_visible=1をON、0をOFFとして描画。content_editと同じhidden=0＋checkbox=1の組み合わせで、OFFも明示送信する。エラー再表示ではPOSTされた値を保持する。

サーバーは明示された0/1を優先し、role変更時もチェック状態を再計算で上書きしない。表示チェックだけの変更も差分UPDATE対象。無変更ならUPDATEしない。旧フォーム等でフィールド自体がない場合のみ、従来の新規・role変更時の自動判定へフォールバックする。不正な表示値は拒否する。

## 削除・追加

削除ボタンは行のhidden削除フラグを1にし、DOM内に残して非表示にする。入力コントロールはdisabledにしてブラウザ検証を回避し、行IDと削除フラグのhiddenは送信する。新規行にも同じ処理を使う。サーバーは既存の明示削除分岐で処理する。保存エラー後も削除フラグ・非表示を保持する。

新規行はrole=author、default_visible=1、表示順は削除対象を除く入力済み表示順の最大値＋1（最小1、INT上限まで）。既存行の順序は書き換えない。フォーム添字は再利用せず増加させる。

## 保存処理への影響

表示値の検証・優先処理と、差分判定へのdefault_visible追加のみ。既存ID保持、無変更行の非更新、新規INSERT、明示DELETE、役割の交換処理、重複チェック、alias解決、role_id解決、optimistic lock、transactionは維持。全削除・再INSERTなし。

## 検証

- PHP 8.2.4：変更・追加PHP4ファイルの構文チェック成功。
- 人物UIのJavaScriptおよびUIテストのJavaScriptをNodeで構文チェック成功。
- git diff --check成功。
- 独立ローカルMariaDBの既存テストを拡張し44項目成功。表示だけの変更、role変更時の手動OFF/ON、新規authorの手動OFF、不正表示値拒否、無変更維持、hidden行削除、既存表示値の描画を追加確認。
- 実際の人物フォームとwork_edit.phpのCSSを読み込んだHTMLを生成し、ヘッドレスChromeで検証。候補APIレスポンスのみ固定値で代用。
- PC幅1184px：16項目成功。5列の順序・同じ行・数値欄90px・人物名の幅、横はみ出しなしを確認。
- 狭い幅500px：14項目成功。折り返しと横はみ出しなしを確認。
- 両方で表示チェックの送信値、新規初期値・次の表示順、手動OFF保持、候補選択・ID保持・入力変更時のID解除、削除フラグ送信・削除入力の検証除外、保存エラー後の削除状態を確認。
- テストDBは終了時に削除し、専用MariaDBも停止。本番DBへ接続していない。

未確認：実DBを使うwork_edit.php全体のHTTP保存と実API検索、本番相当環境のブラウザ操作、400px未満の実描画、他ブラウザ・モバイル実機。indexindex_testへのアップロード・本番反映は未実施。
