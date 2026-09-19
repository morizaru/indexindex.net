# 新規作品の人物保存経路調査

## 状態

実装完了。ユーザーから「目次の一括追加（content_bulk_add）で、標準人物として入力」と回答を受けたため、当該経路の新規作品作成時だけ修正した。複数画面の一括修正、掲載人物からの自動同期は行っていない。

## 追加修正後の実装・検証結果（最新）

ユーザーの追加指示に従い、**未登録人物の自動作成仕様は維持した**。以下が最終仕様であり、後段の「未登録人物拒否」は追加指示前の履歴。

- content_bulk_add.phpでは新規・既存作品とも従来のget_or_create_creator_idを使用。既存人物のIDを再利用し、未登録人物はcreatorsへ自動登録する。content_creatorsへの登録も従来どおり。
- 新規作品をINSERTした場合だけ、初回入力の同じcreator_idをwork_creatorsへ保存する。既存作品、一括内で既に作成した作品への後続行は対象外。
- initializeNewWorkはIDを名前で再検索し直さず、指定IDの正式名を読み込んで既存saveへ渡す。DB照合による表記差があっても掲載側と同じIDを保持する。
- 実際のroleと表示順を保持。同一人物に実役割がある場合は作品側の不要なauthorだけ除外する。掲載側の入力人物は自動整理しない。
- works、creators、issue_contents、content_creators、work_creatorsは既存一括登録トランザクション内で処理。work_creators保存失敗時にすべてロールバックする。
- 変更：admin/content_bulk_add.php、app/work_creators.php、tools/new_work_creators_test.php、本報告書。content_edit.php、work_edit.php、公開work.php、DB構造・INDEXは未変更。
- ローカル回帰テスト19項目成功。未登録1名author、未登録2名まんが/原作、既存作品への掲載人物追加と標準人物非変更、既存・未登録混在の同一ID保存を確認。work_creators INSERTに強制エラーを発生させ、5テーブルすべての件数がロールバックされることも確認。
- 既存人物編集の追加・削除・役割・順序・表示、過去掲載非変更も確認。テストは実際の取得/作成関数・ContentCreatorWriter・WorkCreatorEditorをSQLiteメモリDBで実行するもの。MySQLのロックと照合式はテスト用に置換しており、本番MySQLや管理画面POST全体は未確認。
- 対象PHP3ファイルのphp -l、git diff --checkは成功。
- 本番反映後は、新規作品＋未登録人物の一括登録、混在入力、既存作品への追加、公開人物表示を確認する。既存35072の自動補完は行わない。

## 初回実装の記録（追加指示前）

- 変更：admin/content_bulk_add.php、app/work_creators.php。本報告書を更新し、tools/new_work_creators_test.phpを追加。
- get_or_create_work_idに新規作成フラグを追加。毎回falseに初期化し、実際にworksをINSERTした場合だけtrue。既存作品・同一一括内の同作品の再登場では標準人物を初期登録しない。
- 新規作品に限り、既存WorkCreatorEditorの名前・別名解決で人物IDを取得。未登録・曖昧な人物は拒否して登録を促し、旧INSERT INTO creatorsを呼ばない。既存作品への目次追加や単件追加・目次編集の旧挙動は今回変更しない。
- 入力行の人物ID・名前・実際のrole・表示順からinitializeNewWorkで保存。content_creatorsを読み戻して同期する処理ではない。同じトランザクション内で既存saveを使用し、失敗時は一括全体をロールバックする。トランザクション対応テーブルであることが前提。
- 同一creator_idに空でない実役割があればauthor行を作品側に追加しない。別名経由もIDで判定。実役割を置換しない。同一人物・同一役割の重複は既存saveの照合順序に沿った検査で拒否する。
- 空のrevisionを要求して既存標準人物の上書きを拒否。display_orderは一括入力順、default_visibleは既存WorkCreatorEditor::defaultVisibleの役割別初期値を使用。掲載側の表示名・肩書・所属はコピーしない。
- 新規作品では手入力済みの掲載人物に作品人物を再コピーしない。既存作品への不足分コピーは維持。掲載側に入力されたauthor/実役割の自動整理は行わない。
- work_add/work_edit/content_add/content_edit/work.phpは未変更。DB構造・INDEX・UNIQUE変更、既存データ一括更新なし。過去content_creatorsへの同期なし。
- php -l：調査対象7ファイルと追加テストの構文チェック成功。git diff --check成功。
- php indexindex/tools/new_work_creators_test.php：11項目成功。新規1名author、2名まんが/原作、表示順と表示初期値、同一人物の別名を含むauthor＋漫画抑止、既存作品非再初期化、未登録人物拒否・作品ロールバック・人物非作成、既存編集の追加/削除/役割/順序/表示、過去掲載非変更、公開側の取得元・順序を確認。
- 実際のget_or_create_work_idとWorkCreatorEditorをSQLiteメモリDBで実行。MySQL固有のFOR UPDATE/BINARY/照合式をテスト用PDOで置換しているため、本番MySQLのロック・照合順序そのものの検証ではない。専用MySQLがなく既存MySQL用テストは未実行。管理画面POST全体・公開HTMLの実ブラウザ確認も未実施。
- indexindex_test反映後、一括追加で未使用タイトル＋登録済み人物を登録し、公開work.phpの上部表示を確認する。既存作品への追加で標準人物が変わらないこと、未知人物では一括全体がロールバックされることも確認する。
- 本番反映・35072の実DB確認は未実施。既存35072は自動補完しない。必要ならwork_editで標準人物を明示登録する。

## 以下は回答・実装前の調査記録

## 症状・実データ確認

新規作品の人物が公開work.php上部に表示されないとの報告。ローカルにapp/config.phpがなく、本番DB接続情報もないため、35072のworks/work_creators/content_creatorsは実測していない。公開URLの取得も使用したWebツールでは失敗した。したがって「35072のwork_creatorsが0件」は未確認。

## 調査したファイル

- admin/work_add.php、admin/work_edit.php
- admin/content_add.php、admin/content_bulk_add.php、admin/content_edit.php
- app/work_admin.php、app/work_creators.php、app/content_creators.php
- admin/work_creator_fields.php、admin/work_creator_suggest.php、admin/content_suggest.php
- admin/madb_import.php、admin/madb_import_year.php（自動作成経路の追加確認）
- work.php、tools/work_creators_test.php
- Detail/20260916_work_creator_snapshots.md、関連の人物UI・公開表示の既存Detail

## 確認できた原因と経路

| 経路 | 作品作成・人物保存の動き |
| --- | --- |
| A: work_add → work_edit | work_addは人物欄がなく、作品本体を作成してwork_editへ遷移する。work_editで人物を入力して保存するとWorkCreatorEditor::saveがwork_creatorsへ保存する。作品作成だけで人物がないのは現行仕様で、この経路の保存漏れは確認できていない。 |
| B: content_add | タイトル不一致時にworksをINSERTする。入力人物はContentCreatorWriter::insertIfMissingでcontent_creatorsへ保存するが、work_creatorsへのINSERTはない。 |
| C: content_bulk_add | get_or_create_work_idが作品を新規作成しても、戻り値はIDだけ。人物保存はcontent_creatorsのみで、work_creatorsの初期登録がない。 |
| D: content_edit | タイトル不一致時に新規作品を作成できるが、人物保存はcontent_creatorsのみ。人物には以前の作品の掲載情報が含まれ得るため、そのまま標準人物に転用できない。 |
| MADB取込2画面 | worksとcontent_creatorsを作成するが、work_creatorsへの保存はない。外部の掲載情報なので無条件の標準人物化は行わない。 |

app/content_creators.phpのsyncWorkCreatorsToContentCreatorsは作品から掲載へのコピーであり、逆方向の保存ではない。B/Cでは新規目次へ不足分をコピー、Dでは明示チェック時のみコピーする。元のwork_creatorsが空なら人物は増えない。

既存Detail/20260916_work_creator_snapshots.mdにも「作品新規作成時に人物を作品側へ逆登録する処理は追加していない」と記録されている。この未実装部分が、B/C/Dからの新規作品に標準人物が残らないコード上の原因。ただし35072がそのどの経路を通ったかはDBや公開表示からは判定できていない。

## 前提とコードの相違

作品編集のWorkCreatorEditorは未登録人物を拒否し、候補からIDを選択する方式。一方、content_add/content_editのresolve_creator_idとcontent_bulk_addのget_or_create_creator_idには、未登録時にINSERT INTO creatorsする旧処理が残る。ユーザー指定の「未登録人物を自動作成しない」と相違するため、今回の対象経路を確認してから限定的に対応する。

## 修正方針（未実装）

確認された新規登録経路だけで、その処理がworksをINSERTした場合に限り、明示入力された標準人物を同じトランザクションで初期登録する。既存作品に対して人物を補完せず、content_creatorsテーブルから人物を読み戻して自動同期しない。入力時のroleと表示順を使用し、実役割がある同一人物へ補助的なauthorを追加しない。既存作品編集の追加・削除・role変更・display_order/default_visibleは維持する。

登録経路と標準人物入力の意図についてユーザーへ確認中。特にDの既存掲載人物の転用を避けるため、回答なしで全画面に保存処理を追加しない。

## 変更・影響範囲

今回の変更はこの調査記録のみ。work_creators/content_creatorsの書込み、自動同期、既存データ補完は一切行っていない。work.php・既存編集・DB構造・INDEX・UNIQUEは変更なし。

公開work.phpはwork_creators JOIN creatorsをwork_idで取得し、display_order/id順で描画する。authorは括弧を付けず、他の役割は括弧付き表示。正しく登録されればこの既存処理で表示できる構成であり、表示側の修正は不要。

## 検証

対象PHPの構文チェックとgit diff --checkを実行。登録動作の実DBテストは未実施。既存tools/work_creators_test.phpは専用ローカルMySQLを必要とするため、本番DBに向けて実行していない。新規1名・複数人物・author重複防止・既存編集・過去掲載非変更の動作確認は対象経路確定後の修正時に行う。

## 未確認事項・次の確認

- 作品35072を新規登録した画面、人物を作品の標準人物として入力したか。
- 35072の実データ、DB接続、本番のエラー記録。
- 修正後の実ブラウザ登録・公開表示、本番反映。
- 既存35072のデータ補完は未実施。必要なら既存work_editで人物を登録できるが、本調査では操作していない。
