# 作品人物管理・作品単体登録

## 調査とDB確認

調査対象：`AGENTS.md`、`admin/work_edit.php`、`admin/works.php`、`admin/content_add.php`、`admin/content_edit.php`、`admin/content_bulk_add.php`、`admin/content_suggest.php`、`admin/creator_add.php`、`app/content_creators.php`、公開 `work.php`、`tools/content_creators_test.php`、`Detail/20260916_work_creator_snapshots.md`。

初回は完全な構造がなく停止。今回ユーザーから提供された `works`、`work_creators`、`creators`、`creator_aliases`、`roles` の全列・インデックスと外部キー一覧を確認して実装した。`works` の入力が必要な項目はtitle、publication_type（既定値「連載」）。その他の列はNULL可または自動設定。titleのインデックスは非UNIQUE。

work_creatorsには `(work_id, creator_id, role)` のUNIQUEがあり、roleは `utf8mb4_general_ci`。外部キーは creators.debut_work_id → works.id、creator_aliases.creator_id → creators.id、works.episode_format_id → episode_formats.id の3件。これらの定義は変更していない。

エンジンは提供結果に含まれないため、保存時にinformation_schemaで対象テーブルがInnoDBであることを確認し、確認できなければ書込み前に停止する。

## 変更・追加ファイル

変更：

- `admin/work_edit.php`：人物欄、CSRF、人物と基本情報・既存掲載タイトル同期の一括トランザクション。
- `admin/works.php`：「作品を追加」リンクのみ追加。一覧・検索SQLは変更なし。

追加：

- `admin/work_add.php`：作品単体登録。
- `admin/work_creator_fields.php`：人物フォーム・候補検索UI。
- `admin/work_creator_suggest.php`：号ID不要の人物候補API。
- `app/work_creators.php`：作品人物の読込み・検索・差分保存。
- `app/work_admin.php`：作品入力検証・単体登録・CSRF・エンジン確認。
- `tools/work_creators_test.php`：独立DB・HTTPテスト。
- 本報告書。

## 表示・人物選択

work_creatorsをcreatorsとLEFT JOINし、display_order、id順に表示。人物名、人物ID、role、display_order、明示削除チェックを持つ。roleは自由入力で、既存の空文字や表記を保持する。

既存の単件目次登録・編集は名前→別名→自動作成、一括登録は名前→自動作成。content_suggestは号ID必須、同一雑誌の人物名のみを検索するため、作品単体では直接流用できない。新しい候補検索では名前一致を優先して別名も検索し、人物IDを付けて選べる。入力文字列の完全一致解決も名前→別名の順。同名や同じ別名の候補が複数ならID選択を求める。候補は20件までなので必要に応じて入力を絞る。

作品編集では人物の自動作成は行わない。未登録は既存creator_add.phpを別タブで開いて登録後、再検索する。これは入力ミス・同名人物による意図しない作成を避けるための明示的な動作差。既存目次側の動作は変更していない。

## 保存・重複・表示初期値

- 作品行と既存人物行をロックし、編集開始時の人物スナップショットと比較。他の操作による変更があれば保存を拒否。
- 無変更の人物行はUPDATEしない。ID、created_at、updated_at、role_id、default_visible等を保持。
- 人物・役割・表示順の変更は既存IDへのUPDATE。新規行はINSERT。
- 削除チェックが付いた行だけIDとwork_idを指定してDELETE。空欄やPOSTの行欠落を削除とみなさない。
- 人物または役割の交換では、キー変更対象行のroleをトランザクション内で一時値へUPDATEしてから最終値に更新。全削除・再INSERTは行わない。
- 最終行同士のcreator_idとroleをDBの照合順序で比較し、重複をエラーにする。UNIQUE制約も保持。author／まんが／漫画は別行のまま。DB上で同値になる大文字小文字などは同じ制約に従って拒否する。
- 新規行・role変更時だけ、author、漫画、まんが、マンガ、作画、画、原作、作をdefault_visible=1、それ以外を0にする。人物だけの変更や表示順変更では既存値を保持。
- role変更時だけrolesからrole_idを解決。未登録roleは文字列を保持してrole_id=NULL。rolesを自動作成しない（単件目次側と同方針）。
- 作品情報・人物・既存のissue_contents.title_main同期は同一トランザクションで保存する。

## 新規作品登録フロー

works.php → 「作品を追加」 → title（必須）、title_sub、title_kana、publication_typeを入力 → worksにINSERT → work_edit.php?id=新ID&saved=1へ遷移 → 人物・話数形式を編集。

目次行は作成しない。掲載0件のまま作品が存在できる。title/sub/kanaは各200文字まで、publication_typeは既存画面同様に連載・読切。タイトル重複は既存編集と同じBINARY完全一致で確認する。タイトルのUNIQUE追加はしていないため、別セッションの完全同時登録まで保証するものではない。

## 公開側・変更しない部分

公開work.phpは変更なし。作品上部に人物名・役割の一覧はなく、説明文の人物数と掲載一覧は表示ONのcontent_creatorsを参照している。work_creatorsの役割付き一覧を公開上部に出す対応は今回含めない。

content_add/edit/bulk_add/suggest、app/content_creators.php、content_creatorsの既存データ、目次から作品への逆登録、役割表記統合、外部キー、DB定義、app/config.phpは変更なし。既存データの一括更新も実施していない。

## 検証結果

- PHP 8.2.4で対象PHP8ファイルの構文チェック成功。
- 人物フォームのJavaScriptを抽出してNodeの構文チェック成功。
- git diff --check成功。
- 独立したローカルMariaDB（127.0.0.1:13318）で37項目成功。テストごとのDBとHTTP用一時ディレクトリは終了時に削除。
- 既存人物名・role・display_order・IDのHTML出力をDOMで検証。
- 人物追加・削除・変更、role変更、ID維持、無変更時の全列維持、role交換、重複拒否、照合順序、名前優先・別名・曖昧候補、明示ID選択、未登録人物の非作成、表示初期値、欠落行・不正ID拒否、古いフォーム拒否、ロールバック、content_creators非変更を検証。
- 実際のwork_add.phpを独立設定とともに一時配置し、HTTPで必須項目・CSRF・登録・302リダイレクト・掲載0件の永続化を検証。
- 本番DBへは接続していない。

本番相当環境で未確認：work_edit.php全体のHTTP保存、一覧・検索の実動、ブラウザ上の候補選択・追加削除・レスポンシブ表示、実DBのエンジンと権限、indexindex_testへのアップロード後の動作。本番設定がローカルのindexindex/app/config.phpに存在しないため、テスト用接続での検証に限定。アップロード・本番反映は未実施。
