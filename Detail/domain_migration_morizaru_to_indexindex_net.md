# ドメイン正式移行の再調査（2026-10-19 JST）

## 実装前の調査結果

正式な作業ルートは `/Users/morizaru/GitHub/indexindex.net`。この直下が新ドメインの公開ルートで、内側に indexindex フォルダを追加しない。20261008の記録は当時の構成・検証結果であり、今回の構成や本番反映状況を表さない。AGENTS.mdの旧配備先より今回の明示指示を優先する。

開始時git statusはクリーン。主要18公開ページ、includes、app（config除外）、admin、tools、全ルートPHP、sitemap、robots、隠しファイル、assets、work保存版、Detailとadmin/Detailを調査。

|分類|調査結果と対応|
|---|---|
|A 新URLへ修正|access_loggerのHTTP_HOST未設定時フォールバック、管理解析の現在の対象サイト説明。公開canonical・メール・X下書き・NDL User-Agent・sitemapはすでに新URLで変更不要|
|B 履歴・説明|Detail、admin/Detail、work内の旧実装保存版、設置当時のコメントを保持|
|C 互換性|access_loggerの集計用path、rankingとadmin解析のSQL、旧参照元判定、tools/domain_migration_test.phpの旧入力・否定検証を保持|
|D 要確認|admin/.htaccess.txtのAuthUserFileはサーバー物理パスで推測変更不可。AGENTS.mdの配備ルールは今回の指示が優先。コピーPHP等の公開対象、本番config・DB内URL・サーバー配置は手動確認|

## canonical・内部リンク等

index / issues / issue / works / work / work_graph / creators / creator / ranking / recent_updates / mediamix / search / about / how_to_use / data_policy / terms / privacy / contact の18ページは各1個の新ドメインcanonicalあり。共通 includes/public_url.php と既存の個別正規化を維持。indexは既存どおり /index.php、issueはid、workはwork_id、creatorはcreator_id。リストのフィルタ・ページング、mediamixの空year、work_graphのpage正規化・noindexを変更しない。

公開ソースにOGP・JSON-LD・Twitterカードの出力なし。schema.orgはMADBのSPARQL語彙でサイトURLではない。相対リンク、画像配置assets/imagesおよびuploads/source_imagesへの相対参照を維持。contactメール本文、privacyのサイトURL、admin/x_post_draftとNDL3ツールは新URL済み。API/CSV/JSON向けコードの検索でも移行対象の旧固定URLなし。DB由来URLは未確認。

その他ルートPHP：aucfan_search.phpは外部302用でHTML canonical対象外。index2.php、issueのコピー.php、issuesのコピー.php、work_graph - コピー.phpは旧版候補。madb_collection_extract.phpとそのコピー、source_image_add.phpは作業・登録ツール。canonicalなし。公開対象の確認なしに同一ページ扱いのcanonicalやリダイレクトを追加しない。今回の本番アップロード対象から除外し、既に公開中なら運用者が用途を確認する。

## sitemap・robots・www

sitemap.phpとsitemap_urls.phpの基準は https://indexindex.net 。URL構造・SQLは変更なし。robots.txtは /admin/、/app/、/uploads/ をDisallowし、新sitemapを指定済み。

既存ルート.htaccessはwww.indexindex.netからhttps://indexindex.netへ301するルールを実装済み。THE_REQUESTからパスを取り、クエリは引き継ぐ。新たな上書きなし。本番固有のPHPハンドラー等は必ず保存してルールを統合する。

## 旧サイトへの手動301設定

旧サーバー・リポジトリ外のファイルは変更していない。morizaru.netのドキュメントルート.htaccessで、既存の一般www転送より前に以下を追加する。旧 /indexindex とその配下のみ対象で、/indexindex_test/や他サイトは対象外。既存設定全体をこの断片で置換しない。

```apache
RewriteEngine On
RewriteCond %{HTTP_HOST} ^(?:www\.)?morizaru\.net$ [NC]
RewriteCond %{THE_REQUEST} \s/+indexindex(?:/([^?\s]*))?(?:\?[^\s]*)?\s
RewriteRule ^indexindex(?:/.*)?$ https://indexindex.net/%1 [R=301,L,NE]
```

## アクセス解析

新ホストの /work.php 等は集計用pathだけ旧形式へ変換済み。full_urlは実際のREQUEST_URIとホストを保持。ランキングSQL、bot判定、DB仕様、キャッシュは変更しない。過去ログのURLや内部参照元判定は保持。管理画面では集計キーと現在の公開URLの違いを説明する。

## 設定・配備

app/config.phpの作成・編集・上書きなし、秘密情報の追加なし。アップロード手段・接続設定はリポジトリで確認できず、テスト／本番アップロードは未実施。

手動確認：本番configのドメイン・cookie・絶対パス設定、admin認証のAuthUserFile、DBに保存された画像やsource_url、親ディレクトリのindexindex_cacheの配置と権限。DB接続情報は推測しない。uploadsの実データ移送は別管理で、Gitに追加しない。

今回の追加修正のアップロード対象は includes/access_logger.php と admin/access_analytics.php。移行の既存実装がサーバー未反映なら18公開PHP、includes/public_url.php、sitemap.php、sitemap_urls.php、robots.txt、admin/x_post_draft.php、admin/ndl_collection_extract.php、admin/ndl_import_year.php、admin/ndl_toc_url_finder.phpも差分確認のうえ反映する。.htaccessはルールを統合する。

アップロード禁止・対象外：app/config.php、秘密情報、.git、AGENTS.md、Detail、admin/Detail、tools、work、SQL、旧版コピーPHP。uploadsを空のローカル内容で上書きしない。

テスト環境に必要ファイルを反映し確認後、新ドメインのドキュメントルートへ反映。新サイトの表示・SEO確認後に旧サイト301を設定する。テスト環境canonicalは本番ドメインのまま。旧ホストのindexindex_testでは既存ログ除外が維持されるため、本番のログ保存検証とは分ける。

## 今回の変更一覧

- 変更：includes/access_logger.php（HTTP_HOST未設定時の既定ホストを新ドメインへ）
- 変更：admin/access_analytics.php（現在の対象サイト、実URLと集計キーの違いを説明。SQL・データ表示そのものは維持）
- 新規：本Markdownのみ
- 削除：なし。commit / pushなし。

## 今回の検証結果

2026-10-19 JST、curlで実際の公開サーバーを読み取り確認。今回のローカル修正をアップロードした結果ではない。

|URL（新ドメイン）|HTTP|canonical等|
|---|---|---|
|/|200|https://indexindex.net/index.php|
|/issues.php|200|同じ新URL|
|/works.php|200|同じ新URL|
|/creators.php|200|同じ新URL|
|/work.php?work_id=35072|200|同じ新URL、実在ページ|
|/creator.php?creator_id=123|200|同じ新URL、実在ページ|
|/ranking.php|200|同じ新URL|
|/sitemap.php|200|子サイトマップ5件すべて新URL|
|/robots.txt|200|ローカルと一致、要求の3 DisallowとSitemap|

7公開HTMLで旧サイトURLなし、Fatal error / Warning: / Notice: / Parse error の文字列なし（サーバーログは未確認）。子サイトマップ5件も200でXML解析成功。static 13、magazines 440、issues 19046、works 35313、creators 13652件のlocは全件新ドメイン。各loc先の全件アクセスは実施していない。

取得HTMLの同一サイト参照CSS・画像3件（common.css、main_title.png、main_title0.png）は全件200。main_title0.pngはローカルにないがサーバーに存在するため既存画像を削除しない。同一サイトJS参照なし。外部広告・フォント、ブラウザ実描画、動的画像・全リンクの網羅検証は未実施。

|301検証対象|実測|
|---|---|
|https://www.indexindex.net/work.php?work_id=123|301 → https://indexindex.net/work.php?work_id=123（パス・クエリ保持）|
|https://morizaru.net/indexindex/|200、移行301未反映|
|https://morizaru.net/indexindex/work.php?work_id=123|200、移行301未反映|
|https://www.morizaru.net/indexindex/work.php?work_id=123|301 → 旧non-www、移行301未反映|
|https://morizaru.net/indexindex_test/|401 Basic認証、新ドメインへ転送なし|

ローカルPHP 8.5.10：変更2ファイルのlint成功、既存domain_migration_test.phpの48チェック成功。保存版workを除くGit管理PHP101ファイルのlintは99成功。既存admin/toc_extract_tool.php:18 と admin/toc_extract_tool_ai.php:34 はmb_trim再定義で失敗（現行PHPとの互換性問題、今回変更なし）。本番PHPバージョンは未確認、今回のドメイン作業とは分離する。

残作業：アップロード手段・テスト認証の確認、テスト反映、今回の2ファイルの本番反映、旧サイト301設定と再検証、本番DBのfull_url/pathの保存結果、旧URLを含むDBデータ、本番config・認証・キャッシュ配置、公開対象が不明な旧版・登録PHPの整理。今回の実測で正式移行完了とは扱わない。

## 最終検索：旧URL・旧パスの残存箇所

検索は隠しファイル・ignore対象を含め、.git、秘密設定、uploads、バイナリを除外。検索語は morizaru.net/indexindex、www.morizaru.net/indexindex、https://morizaru.net、http://morizaru.net、/indexindex/（旧ホスト単体も追加）。以下は本報告書を除く一致行ごとの一覧。本報告書自身にある旧URLは、移行元説明・設定例・検証結果・この残存一覧として残す（B）。長いHTMLは行番号で特定し、本文を重複転載しない。

|ファイル:行|分類|残す理由|
|---|---|---|

| `ranking.php:68` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `ranking.php:82` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `ranking.php:106` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `ranking.php:127` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `AGENTS.md:7` | D | 旧運用指示。今回の新ルート指定を優先 |
| `aucfan_search.php:4` | B | 旧配置・互換性の説明コメント |
| `work/indexindex_access_analytics/README.md:10` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/README.md:11` | B | 旧実装保存版。配備対象外 |
| `admin/.htaccess.txt:3` | D | 認証用物理パス。実配置の確認が必要 |
| `work/indexindex_access_analytics/admin/access_analytics.php:3` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/admin/access_analytics.php:90` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/admin/access_analytics.php:377` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:9` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:13` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:18` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:49` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:59` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics/includes/access_logger.php:82` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics_graph_monthly_complete/README.txt:15` | B | 旧実装保存版。配備対象外 |
| `admin/Detail/IMPLEMENTATION_CHECKBOX_ALWAYS_VISIBLE.md:376` | B | 過去の調査・検証・設定例の原記録 |
| `work/indexindex_access_analytics_graph_monthly_complete/admin/access_analytics.php:4` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics_graph_monthly_complete/admin/access_analytics.php:139` | B | 旧実装保存版。配備対象外 |
| `work/indexindex_access_analytics_graph_monthly_complete/admin/access_analytics.php:462` | B | 旧実装保存版。配備対象外 |
| `admin/Detail/QUICK_GUIDE_DEBUG_LOGS.md:147` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/IMPLEMENTATION_HIDDEN_FIELD.md:434` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260717_access_log_bot_leak_check.txt:101` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260717_access_log_bot_leak_check.txt:135` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260717_access_log_bot_leak_check.txt:155` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260717_access_log_bot_leak_check.txt:177` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260919_public_performance_investigation.md:3` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/QUICK_GUIDE_HIDDEN_FIELD.md:52` | B | 過去の調査・検証・設定例の原記録 |
| `admin/access_analytics.php:4` | B | 旧配置・互換性の説明コメント |
| `admin/access_analytics.php:270` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `admin/access_analytics.php:624` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `admin/access_analytics.php:821` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `admin/access_analytics.php:825` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `admin/access_analytics.php:829` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `Detail/20261008_domain_migration_live_before.json:47` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:66` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:72` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:78` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:84` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:90` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:96` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_live_before.json:98` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:11` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:43` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:58` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:71` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:82` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:89` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/bot_distributed_work_30230_20260716.txt:96` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/MODIFICATION_CHECKBOX_ALWAYS_VISIBLE_2026-08-31.md:136` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260807_work_edit_issue_year_priority_order.md:7` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/MODIFICATION_DEBUG_LOGS_TOGGLE_2026-08-31.md:120` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/MODIFICATION_DEBUG_LOGS_TOGGLE_2026-08-31.md:128` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:149` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:186` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:187` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:188` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:189` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_access_log_bot_leak_real_ip_check.txt:190` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:24` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:25` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:35` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:37` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:66` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_onamae_ip_access_diff_check.txt:67` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:5` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:73` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:104` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:105` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:106` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:107` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:108` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260721_adsense_content_improvement.txt:109` | B | 過去の調査・検証・設定例の原記録 |
| `admin/Detail/FIX_CHECKBOX_STATE_POST_2026-08-31.md:221` | B | 過去の調査・検証・設定例の原記録 |
| `includes/footer.php:2` | B | 旧配置・互換性の説明コメント |
| `Detail/20261008_domain_migration_apache_test.txt:1` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:2` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:3` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:4` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:5` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:6` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:7` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:8` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:9` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:10` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:11` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:12` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:13` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:14` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:15` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:16` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:19` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:20` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:21` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_apache_test.txt:23` | B | 過去の調査・検証・設定例の原記録 |
| `includes/access_logger.php:12` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:13` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:20` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:37` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:38` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:39` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:40` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:48` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:204` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:205` | B | 旧配置・互換性の説明コメント |
| `includes/access_logger.php:321` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:391` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:469` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:470` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:471` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:472` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `includes/access_logger.php:473` | C | 過去ログと共通の集計キー・参照元判定・集計キーの説明 |
| `Detail/20261008_morizaru_root.htaccess:16` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260919_ranking_cache_performance.md:83` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260919_ranking_cache_performance.md:95` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260919_ranking_cache_performance.md:176` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:43` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:60` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:97` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:102` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:103` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260930_work_graph_zero_result_normalization.md:136` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260807_work_edit_combined_issue_order.md:7` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:1` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:2` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:3` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:4` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:5` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:6` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:7` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:8` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:9` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:10` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:11` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:12` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:13` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:14` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:15` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:16` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:17` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:18` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:19` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:20` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:21` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:22` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:23` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:24` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:25` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:26` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:27` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:28` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:29` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:30` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:31` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:32` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:33` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:34` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:35` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:36` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:37` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:38` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:39` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:40` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:41` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:42` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:43` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:44` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:45` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:46` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:47` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:48` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:49` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:50` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:51` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:52` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:53` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:54` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:55` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:56` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:57` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:58` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:59` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:60` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:61` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:62` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_original_hits.txt:63` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/MODIFICATION_REPORT.md:4` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/MODIFICATION_REPORT.md:19` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/MODIFICATION_REPORT.md:75` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260807_issue_status_verified_list_visibility.md:6` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260807_issue_status_verified_list_visibility.md:7` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260807_work_edit_issue_label_numeric_order.md:7` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:126` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:177` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:183` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:191` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:197` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:262` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:263` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20260820_work_graph_gsc_5xx_url_normalization.md:279` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:5` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:23` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:68` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:70` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:78` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:87` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:104` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:128` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:129` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:131` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:133` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:135` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:141` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:182` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:190` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:194` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:198` | B | 過去の調査・検証・設定例の原記録 |
| `Detail/20261008_domain_migration_indexindex_net.md:213` | B | 過去の調査・検証・設定例の原記録 |
| `tools/domain_migration_test.php:12` | C | 旧パス互換性・旧URL不出力の回帰テスト |
| `tools/domain_migration_test.php:13` | C | 旧パス互換性・旧URL不出力の回帰テスト |
| `tools/domain_migration_test.php:15` | C | 旧パス互換性・旧URL不出力の回帰テスト |
| `tools/domain_migration_test.php:16` | C | 旧パス互換性・旧URL不出力の回帰テスト |
| `tools/domain_migration_test.php:21` | C | 旧パス互換性・旧URL不出力の回帰テスト |

一致行数：226（本報告書を除く）。

最終差分確認：`git diff --check` を実行し成功。
