# 管理側目次一覧の掲載時人物表示

## 対象の特定

主な号別目次確認画面は `admin/content_list.php`。`admin/issue_edit.php` の「登録済み目次一覧」リンクから開け、各行に `content_edit.php` への編集導線がある。

同じ号の登録済み目次を表示する `admin/content_bulk_add.php` 下部と `admin/content_edit.php` 下部も同じ表示に揃えた。編集フォームや保存処理は今回変更していない。

調査ファイル：`AGENTS.md`、`admin/issue_edit.php`、`admin/index.php`、`admin/content_list.php`、`admin/content_add.php`、`admin/content_edit.php`、`admin/content_bulk_add.php`、既存の `app/content_creators.php`。管理画面全体の目次リンク・人物表示参照も検索した。

## 変更・追加

変更：`admin/content_list.php`、`admin/content_edit.php`、`admin/content_bulk_add.php`。

追加：

- `app/content_creator_display.php`：人物表示名・役割付きラベルの組み立て、号単位の人物一括取得。
- `admin/content_creator_display.php`：管理側のHTML表示。非表示マークとHTMLエスケープ。
- `tools/content_creator_display_test.php`：独立DBでの表示・取得・保存後の再取得テスト。
- 本報告書。

再開時点で上記の表示処理は実装済みだったため、重複実装はしていない。未完了だったテストを修正して完走した。停止原因は、MySQL用保存処理のFOR UPDATEをSQLiteテスト環境でprepareしようとしたこと。テストを独立MariaDBで実行する方式に直した。

## 表示仕様

- display_nameがNULL／空文字ならcreators.name、そうでなければdisplay_name。
- display_titleは名前の直後へそのまま連結。
- display_affiliationがNULL／空文字でなければ全角括弧で囲んで末尾へ追加。
- roleが厳密にauthor、またはNULL／空文字の場合は役割ラベルを付けない。それ以外は「role：人物表示名」。保存値・表記は正規化しない。
- is_visible=0も表示し、小さい文字で「［非表示］」を付ける。authorの非表示人物にも不要なauthorラベルは付けない。
- 人物ごとに1行、display_order順。同順位では行ID順で安定させる。
- 出力時に全ての人物文字列をHTMLエスケープする。

たとえば `梅沢由香里二段（日本棋院）`、`まんが：RUMIKO TAKAHASHI`、`［非表示］キャラクター原案：人物C（○○プロ）` と表示する。

## 取得方法・保存後の確認

従来の人物GROUP_CONCATと人物JOIN・GROUP BYを一覧本体のSQLから除き、号内の人物情報を共通関数で一括取得してcontent_idごとにまとめる。人物取得は目次数にかかわらず追加1クエリ。is_visibleによる除外、DISTINCTによる行の省略、GROUP_CONCATの長さ制限や区切り文字の解析はない。

編集画面には従来どおり号別目次一覧へ戻るリンクがあり、一覧を開くたびに保存済みDB値を再取得する。フォームの未保存値を一覧へ表示する処理ではない。

## 変更しないもの

公開work.php・issue.php・creator.php、作品編集・追加画面、work_creators処理は変更なし。DB定義変更・実DBへの接続・既存content_creatorsの更新はなし。今回の本体追加処理は読取りと表示のみ。表示名からの肩書抽出や補完も行わない。

## 検証

- PHP 8.2.4で変更・追加PHP6ファイルの構文チェック成功。
- JavaScriptは変更なし。3画面のscriptブロックが変更前と一致することを確認。
- git diff --check成功。
- 公開work.php・issue.php・creator.php、作品側3ファイルに差分がないことを確認。
- 独立ローカルMariaDBで31項目成功。全NULL、空文字、表示名のみ、肩書のみ、所属のみ、3項目併用、roleのラベル、非表示人物、HTMLエスケープ、表示順、他号の除外、空リスト、一括取得を検証。
- 3画面の実際の一覧SELECTと人物セルのPHPを抽出して実行し、表示結果を確認。
- 実際のContentCreatorWriterで表示名・肩書・所属を保存し、一覧用共通取得・描画処理を再実行して変更後の値と既存IDの維持を確認。
- テストDBは終了時に削除し、専用MariaDBも停止。本番DBは使用していない。

未確認：本番相当環境での画面全体のHTTP遷移、ブラウザ上で編集して一覧へ戻る通し操作、実データ量での性能・表示レイアウト。indexindex_testへのアップロード・本番反映は未実施。
