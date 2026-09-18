# 公開一覧3ページ・ランキングの追加性能調査

調査日：2026-09-19。

## 既存調査との関係・対象

以下を先に読み、既存改修を含む今回開始時のHEADを比較基準にした。

- `20260919_public_performance_investigation.md`
- `20260919_top_sidebar_cleanup.md`

今回の対象は `issues.php`、`works.php`、`creators.php`、`ranking.php`。既存のworksの人物JOIN省略、creatorの集計統合、TOPのSQL 15→5本の改修は維持し、やり直していない。

各ページのSQL組み立て、入力値、選択肢配列の参照、描画ループ、COUNT・一覧・検索分岐を追加確認した。共通アクセスログの処理と保存済みDDLの状況は既存調査を参照し、変更していない。

## 結論・計測範囲

**最も重い候補はranking.phpだが、本番で最も遅いページと実測で特定したわけではない。** ランキングの統合はINDEX・EXPLAINなしでは有効性を判断できないため、調査と提案に留めた。

コード上で不要と確認できた一覧側のJOIN・選択肢取得のみ修正した。実装変更は `issues.php`、`works.php`、`creators.php` の3ファイル。本報告書を追加。ranking.phpは変更なし。

ローカルに `app/config.php` がないことを再確認した。実DB接続、通常EXPLAIN、EXPLAIN ANALYZE、本番負荷テストは実施していない。先行調査の2026年5月のDDLを現行INDEX情報として扱っていない。INDEX依存の追加最適化は確認待ち。

## SQL本数：今回修正前→修正後

正常系のページ本体の本数。DB接続初期化・共通access_loggerのSQLは除外する。固定データを用いたページ実行で確認した本数であり、SQL実行時間の測定ではない。

| ページ・経路 | 修正前 | 修正後 | 内訳・効果 |
| --- | ---: | ---: | --- |
| issues：雑誌指定なし | 5 | 5 | 選択肢3、COUNT1、一覧1。後ろ2本の未使用JOINを削減 |
| issues：雑誌指定あり | 6 | 6 | 選択肢3、選択雑誌1、号COUNT1、号一覧1。変更なし |
| issues：追加選択条件 | 上記+0～2 | 同じ | publisher_id/filter_magazine_idによる個別名称取得。維持 |
| works | 5 | 3 | 出版社・雑誌の選択肢2本を停止。年・COUNT・一覧は維持 |
| creators | 6 | 4 | 出版社・雑誌の選択肢2本を停止。年・役割・COUNT・一覧は維持。COUNTの外側JOINも削減 |
| ranking | 15 | 15 | 3カテゴリ×5期間。limit=10/20/50の各経路で確認。変更なし |

各一覧行・人物・作品・雑誌ごとの追加SELECTは、対象4ページでも見つからなかった。rankingはカテゴリ/期間の固定ループで15本を実行するが、取得行数に比例するN+1ではない。bind・HTML描画ループ内には問い合わせなし。今回もN+1や新たなループ内SQLを追加していない。

## issues.php

### 原因と変更

`$keyword=''` が固定されているにもかかわらず、雑誌一覧のCOUNTと本体に次のLEFT JOINが常時あった。

```sql
LEFT JOIN works w ON ic.work_id = w.id
LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
LEFT JOIN creators c ON cc.creator_id = c.id
```

現行SELECT/WHERE/ORDER BY/GROUP BYはこれらの列を使用しない。`$magazine_search_join_sql` を設け、旧横断検索用の `$keyword !== ''` のときだけ結合する形に変更。入力仕様は変更せず、現在はこの3テーブルを結合しない。

COUNT DISTINCT m.id、COUNT DISTINCT mi.id/ic.id、MIN/MAX発行年は、LEFT JOINで増える重複行の有無に依存しないため、意味を保って中間行の増加をなくせる。人物なしの掲載も元のLEFT JOINで残るため、除去しても対象は減らない。

通常の雑誌一覧は、magazines→publishers→magazine_issues→issue_contentsを維持。公開statusの条件、目次がある雑誌のみ、発行年算出、HAVING、ソート、LIMIT/OFFSETは変更なし。

### 雑誌指定あり

雑誌指定時は元々COUNT/号一覧に上記3テーブルの直接JOINがなく、現在の空keywordでは検索用EXISTSも付かない。こちらは変更なし。3本の選択肢SQLは実際にフォームで使用されるため維持した。

ページング前の掲載JOIN・集約、発行年のCASE/YEAR/文字列処理、号順の式ORDER BYは残る。読取り行数・filesort等は未測定。

## works.php

現行UIには出版社・雑誌選択欄がなく、対応IDは0、対応検索文字列は空に固定されている。`$publishers`・`$magazines` は描画に使われず、名称補完分岐も現在の入力では通らない。一方、その候補取得は毎回issue_contentsまでJOINしてDISTINCTしていた。

`$load_publisher_magazine_options = false` と空配列初期化を追加し、候補のprepare/execute/fetchAllを条件付きにした。SQL・条件生成を残したため、将来UIを戻すときに再利用できる。現在は2本とも実行されない。復活させる場合はUI・入力処理との対応を確認してフラグを切り替える。

年選択肢は現行UIで使うため残す。COUNT、本体のGROUP BY、COUNT DISTINCT、出版社/雑誌名GROUP_CONCAT、ORDER BY、100件ページングも維持。前回の人物JOIN省略はそのまま。

残る負荷候補は、年候補とCOUNT/本体がそれぞれ掲載集合を読むこと、および集約後にページングすること。件数・年代・名前一覧のソート結果に影響するため、LIMITを単純にJOIN前へ移動していない。

## creators.php

### 選択肢の実行停止

works同様に、現行UIで未使用の出版社・雑誌選択肢2本を `$load_publisher_magazine_options = false` で停止した。候補SQLは将来用に残す。これらはcontent_creatorsまでJOINしていたため、SELECTが2本減るだけでなく、そのJOIN/DISTINCT処理自体が不要になる。

年・役割選択肢はUIと入力検証に必要なので維持する。年SQLのGROUP_CONCATや役割候補のis_visible条件も変更しない。

### COUNTの外側JOIN削減

従来のCOUNTは次の外側LEFT JOINを持っていた。

```text
creators c
→ content_creators cc
→ issue_contents ic
→ works w
→ magazine_issues mi
→ magazines m
```

現行の氏名・頭文字条件はcの列だけを参照し、年・role条件は既に独立したEXISTS内で処理されている。そのため、COUNTにこれら5つの外側JOINは不要。

`$count_search_join_sql` を追加し、外側の作品・掲載・雑誌列が必要になる旧横断keyword検索時だけ元のJOINを残した。現在のCOUNTは概ね次の構造になる。

```sql
SELECT COUNT(DISTINCT c.id) AS total_count
FROM creators c
WHERE /* 既存の氏名・頭文字条件、および必要な場合のEXISTS */
```

WHEREがない場合はそのまま全人物を数える。LEFT JOINによる重複はCOUNT DISTINCTで元々除かれており、掲載0件の人物もLEFT JOINで元々残るため、JOIN除去で件数の意味は変わらない。

EXISTSは `cc2→ic2→mi2→m2` の既存処理をそのまま保持。年とroleは同じ掲載行で満たす条件のままとし、別々のEXISTSへ分割して誤って別掲載の条件を組み合わせない。is_visible条件を追加する等の表示範囲変更もしていない。

### 一覧本体は維持

掲載数・作品数・MIN/MAX年代・作品名GROUP_CONCATのため、一覧の広いJOINは必要。現在のrole/年は人物の選択条件で、表示集計をそのrole/年に限定する条件ではないため、その意味も変更しない。名順以外の件数・年代ソートでは全候補の集約が必要になり得る。集計前LIMITやPHPでの別ソートは導入していない。

## ranking.php：15本をどう扱ったか

### 現状確認

作品・雑誌・人物ごとに全期間・今月・今日を表示し、先月・昨日との順位比較にも各1本を使用する。合計15本。先月/昨日は表示行がなくても順位矢印に必要で、不要SQLとは扱わない。

カテゴリの対象パス、query_stringのID抽出、bot/site条件、PV/UU、期間境界、同点時の名前順、limit=10/20/50を再確認。雑誌は一覧アクセスと号アクセスをUNION ALLし、同じ雑誌へのUUをその外側でDISTINCTする必要がある。

LIMITは集計後に効く。全期間と期間集計の対象ログには重なりがあるが、それが実際にどれだけ重複読取りとなるかは実行計画次第。REGEXP/ID抽出のCPU負荷と時刻/パス等の絞り込み効果も未測定。

### 統合案の比較・今回見送る理由

| 案 | 本数削減の可能性 | 問題・必要な確認 |
| --- | --- | --- |
| カテゴリごとに5期間の条件付き集約 | 15→3が候補 | COUNT(DISTINCT CASE WHEN ... THEN visitor_hash END)等で各期間を保持できるが、全期間の全候補を集約して期間別上位を取り出す処理が必要。5組のUU集合・一時領域が増える可能性。DBバージョン・INDEX・EXPLAINが必要 |
| 期間ごとに生ログを取得しPHPで分類 | 本数を減らせる可能性 | 全期間ログを転送・保持するとPHPメモリ/転送負荷が増大。MySQLの文字列比較とPHPの順位付け差異にも注意。今回は採用しない |
| 現行SQLを単純にUNION ALL | 往復回数は減らせる | 各枝の集計・走査は残るため、本数減が読取り削減を意味しない。巨大化・一時表コストを実測せず採用しない |
| 日別集計を足す | PVには利用余地 | 同じ訪問者が複数日に現れるためUUを日別の足し算で再現できない。集計仕様・保存構造の変更なしには置換しない |

SQL本数だけを目的に巨大なクエリへ書き換えていない。今回は**15→15のまま**。エラー処理や順位表示も変更なし。生ログをPHPへ大量に読み込む改修、キャッシュ導入、DB追加は行わない。

## INDEX・EXPLAIN確認待ち

必要性は未確定。追加INDEXは作成せず、現在の以下のSHOW INDEX結果が届くまで、ランキングの実行計画に依存する追加改修を停止する。

```sql
SHOW INDEX FROM access_logs;
SHOW INDEX FROM issue_contents;
SHOW INDEX FROM content_creators;
SHOW INDEX FROM magazine_issues;
SHOW INDEX FROM works;
SHOW INDEX FROM creators;
```

最優先はaccess_logsのsite_key/is_bot/path/access_timeをどの既存キーで絞れるか。以降はissue_contentsのissue_id/work_id、content_creatorsのcreator_id/content_id、magazine_issuesのmagazine_id、works/creatorsのキーと現在の選択性を確認したい。接続情報や生ログは不要。

通常EXPLAINの優先対象はランキングの作品全期間・期間付き、雑誌UNION、人物期間付き。次にissuesのCOUNT/一覧、worksのCOUNT/年/本体、creatorsのCOUNT/EXISTS/本体。type・possible_keys・key・rows・ExtraのUsing temporary/filesortを確認する。実行計画なしにALLやINDEX不足を断定しない。EXPLAIN ANALYZEは実行を伴うので、本番で安易に行わない。

## 短時間キャッシュ案（提案のみ・未実装）

- 候補：rankingの3カテゴリ×5期間の取得結果配列。HTML全文やaccess_logsの生行・visitor_hashを保存する案ではない。
- 有効期間：まず300秒を比較候補とする。今日ランキングの鮮度を優先するなら60秒。適切な値は利用状況・測定後に決める。
- 保存場所：サーバー上の公開ディレクトリ外に置く専用ファイルディレクトリを候補とする。今回作成していない。利用可能パス・権限・運用を先に確認する。APCu/Redis/Memcached/DBキャッシュテーブルは導入しない。
- キー：アプリ/環境、集計ロジック版、Asia/Tokyoの対象日・期間境界、limit（10/20/50）を含める。別環境や日/月境界の値を混ぜない。
- 無効化：TTL満了、日付変更によるキー更新、表示名・集計条件変更時の版更新/管理操作による対象ファイル削除。保存は一時ファイルからの置換、生成競合はロックで抑える設計が候補。cronは追加しない。
- 効果見込み：ヒット時にはランキング15本を省ける可能性。共通アクセスログ保存は残る。未生成/期限切れ時は15本が必要で、実時間の改善率は未測定。
- 欠点：最大TTL分の順位/件数/名前反映遅延、初回・失効時の待ち、同時生成、ファイル権限/容量/複数サーバーでの不一致、エラー結果をキャッシュしない扱いなどの設計が必要。

今回の範囲で導入の必要性を確定していない。まず現在のINDEXと実行計画・時間を確認し、SQL改善の余地と比較する。

## 影響範囲

HTML構造・CSS・JS・表示項目・人物表示・ソート・ページング件数・検索入力/検索意味・URL・canonical・noindex・sitemap・robotsは変更しない。実際に3変更ページのDOCTYPE以降のソースが変更前と完全一致することも確認した。

work_creators/content_creatorsの構造やデータ、DB INDEX、既存データへの変更なし。JOINを参照しない経路を設けるSQL変更だけで、データ更新は一切ない。TOPとcreator.phpにも追加変更なし。

## 検証結果

- 対象4ページの `php -l` 成功（PHP 8.5.10）。
- `git diff --check` 成功。
- 比較基準は先行改修を含む今回開始時のHEAD。変更前4ページを一時ディレクトリへ退避。
- 一覧3ページはSQLiteメモリDBの独立固定データで実際のPHPを実行し、HTML全文・件数・ページ数/現在ページ・noindex用値（未定義時null）を比較した。config・access_loggerの呼出しだけをテスト側で除外し、SQLはページの生成結果を使用した。
- MySQL固有のGROUP_CONCAT/CONVERT/UNHEXを互換処理へ置き換え、REGEXP/YEAR/LOCATE/STR_TO_DATE/SUBSTRING_INDEX/CONCAT/LPAD等をテスト関数で代用。MySQLの照合順序・GROUP_CONCAT長さ制限・実行計画・性能の検証ではない。ストレージ上の本番データには接続していない。
- 固定データに100件を超える作品/人物、複数掲載・複数役割・非表示人物・人物なし・掲載0件・NULL・参照先なしを含めた。号側は公開statusの違い・目次なし・非公開号のみの雑誌・50件を超える号も用意。
- **works 20ケース成功**：4種ソート×昇降順、2ページ目/超過、タイトル一致/0件、年・年候補・区分・頭文字・現在無視する旧検索パラメータ。SQLは5→3。
- **creators 28ケース成功**：4種ソート×昇降順、2ページ目/超過、氏名/かな/掲載0件人物/不一致、roleの有効/無効・非表示role、年の有効/無効、年+role、頭文字、旧パラメータ。SQLは6→4、COUNTに外側LEFT JOINなし。
- **issues 39ケース成功**：雑誌指定あり/なし、両経路のソート×昇降順、ページ、出版社/雑誌のID/文字列/不一致、年、旧keyword、目次なし・非公開号のみ・存在しない雑誌。SQL本数は維持、現行経路では人物JOINが発行されない。
- 合計**87ケースですべて出力一致**。HTMLには検索結果・ページング・ソート表示も含む。
- rankingは別のPDO記録スタブでlimit=10/20/50を各実行し、各15本（全期間3本・期間指定12本）を確認。変更前後のSQL/HTML一致。スタブなのでランキング値のSQL計算や性能を検証したものではない。
- 検証一時ファイル：`/private/tmp/indexindex-list-before/`、`indexindex-list-render.php`、`indexindex-list-compare.py`、`indexindex-ranking-render.php`（いずれも/private/tmp配下）。公開実装には含めていない。

## 未確認・次に本番相当環境で確認するもの

- 対象4ページの実MySQL出力・SHOW INDEX・通常EXPLAIN・SQL時間・PHP時間・TTFB。本番で最近重くなった直接原因。
- issuesの雑誌指定あり/なしと公開status/年/出版社条件、worksの区分/年/タイトル/ページ、creatorsの氏名/role/年の組合せと掲載0件、rankingのlimit=10/20/50・当日/今月/前期間の順位。
- 実ブラウザとモバイル実機での画面操作。今回はHTML/CSSを変更しておらず、ブラウザ描画の再測定はしていない。
- indexindex_testへのアップロード・本番反映は未実施。ランキングやINDEX依存の追加最適化はユーザーからの現在のINDEX情報を待つ。
