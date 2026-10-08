# INDEXINDEX ドメイン移行（2026-10-08）

## 移行概要・現在の完了範囲

旧 https://morizaru.net/indexindex/ → 新 https://indexindex.net/ 。ローカルコードと旧サイト用 .htaccess の修正、ローカル検証を完了。本番へのアップロードはこの作業では行っていない。既にアップロード済みの本番ファイルは今回の修正前の内容であり、再反映が必要。

リポジトリの直下は旧ポータル用で、実際のINDEXINDEXソースはさらに `indexindex/` 配下。新ドメインのドキュメントルートへはこの内側のフォルダの内容を配置する。リポジトリ直下の index.php・robots.txt は旧ポータル用のため変更しない。

## 調査範囲と旧URL件数

`rg -n -i 'morizaru\.net' --hidden -g '!**/.git/**'` の変更前ヒットは **63行**（URL個数ではなく一致行数）。完全なファイル名・行番号・元の記述は `20261008_domain_migration_original_hits.txt`、各行の分類は本書末尾。

- 15行／14ファイル：公開絶対URLを新ドメインへ変更。
- 1行：管理解析の旧ドメイン内部判定を残し、新ドメインも追加。
- 11行：旧ポータル、旧アクセス記録の互換性、コメント、work/配下の保存版として維持。
- 1行：admin/.htaccess.txt の AuthUserFile。URLではなくサーバー上の絶対パス。本番の配置確認が必要、推測で変更しない。
- 35行：Detail（admin/Detailを含む）の過去記録。変更しない。

PHP・HTML・CSS・JS・隠しファイル・サイトマップ・robots・管理画面・公開画面・メール・X下書き・外部リンクを検索。無視ファイルについても config.php・uploads・.git を除外して追加検索し、実装側の追加ヒットなし。OGP、JSON-LD、RSSの既存出力は公開ソースに見つからず、新設はしていない。画像やアフィリエイトリンクの旧URLも検出なし。ロゴ・HOME・ナビ・パンくず・フッター等の正常な相対リンクは維持。

## 調査した主なファイル

- ../.htaccess（ローカル morizaru.net 直下）、robots.txt、index.php、.gitignore、indexindex/AGENTS.md
- indexindex/{index,issues,issue,works,work,creators,creator,ranking,recent_updates,mediamix,search,work_graph}.php
- indexindex/{about,how_to_use,data_policy,terms,contact,privacy,sitemap,sitemap_urls}.php
- indexindex/includes/{header,footer,sidebar,access_logger}.php、assets配下
- indexindex/app/ranking_cache.php、tools/ranking_cache_test.php
- indexindex/admin/{access_analytics,x_post_draft,ndl_collection_extract,ndl_import_year,ndl_toc_url_finder,ndl_import_confirm,madb_collection_extract,work_mediamix_add,work_mediamix_edit,source_image_add,magazine_edit}.php
- indexindex/source_image_add.php、admin/.htaccess.txt、work配下の保存版、Detail・admin/Detailの過去ログ
- リポジトリ直下 app/config.php：旧ドメイン／旧パスの有無のみ確認、0件。変更・内容の転載なし。
- INDEXINDEX用 indexindex/app/config.php はローカルに存在しない。本番設定は別途確認が必要。

## 修正内容

### canonical と SEO

既存canonicalは about / how_to_use / data_policy / terms / creator / work_graph のベースURLを変更。creator の誤パラメータ creators_id → creator_id の既存301も新ドメインへ向ける。

canonicalがなかった index / issues / issue / works / work / creators / ranking / recent_updates / mediamix / search / contact / privacy に追加。18公開ページでcanonicalが各1つあることを検証。

新規共通 includes/public_url.php はページごとの検索条件・ページングを保持し、未使用の追跡パラメータと配列入力をcanonicalから除外。mediamixの `year=` は「全年」なので空値を保持。work の id 別名入力も検証後の work_id へ、issueは検証後の id へ統一。HOMEのcanonicalはサイトマップと一致する /index.php（/ へのアクセスも同じcanonical）。既存のリクエスト処理や検索・ソート・ページングは変更しない。

creator / issue / work_graph の noindex、work削除IDの404、既存リダイレクト条件はそのまま。work_graph はベースURL以外の差分なし。DB接続がないためこれらのページ全体のローカルHTTP動作は未実行。

### sitemap・robots

sitemap.php / sitemap_urls.php の II_SITEMAP_BASE_URL を `https://indexindex.net` に変更。種類、分割、URL数上限、抽出SQL、lastmod、公開条件は維持。

新規 indexindex/robots.txt（新ドメインの /robots.txt として配備）：

```text
User-agent: *
Disallow: /admin/
Disallow: /app/
Disallow: /uploads/

Sitemap: https://indexindex.net/sitemap.php
```

旧ポータルのrobotsは維持。旧テストディレクトリ指定は新ドメイン側には追加しない。

### その他のURL

contactの画面表示・メール本文、privacyのサイトURL、X投稿下書き、NDL取得のUser-Agent内URLを変更。メールやX投稿の送信は実行していない。外部アフィリエイトURLは維持。

### アクセス記録の移行互換性

新ホストでは旧 `/indexindex/` 判定に合わず公開ページの記録が途切れるため、includes/access_logger.php に最小限の互換変換を追加。

- indexindex.net / www.indexindex.net の `/work.php` 等を**集計用pathだけ** `/indexindex/work.php` 等として扱う。
- full_url は実際の新URLのまま。URL転送や画面リンクにはこの変換を使わない。
- 管理画面除外・bot判定・ランキングSQL・既存履歴の集計形式を維持。
- admin/access_analytics.php の内部流入判定に新ホストを追加。旧ホストも履歴用に維持。
- 過去DBデータの更新はしていない。新しいアクセス記録は通常どおり保存される。

## 301設定

ローカル旧サイトルート `C:/moriya/00_main/70_study/30_program/HTML/morizaru.net/.htaccess` を確認し、既存PHPハンドラーとwww転送を保存したまま、RewriteEngine On の直後／www転送より前に以下を追加済み。リポジトリ外のため git diff には含まれない。適用後の全内容も `20261008_morizaru_root.htaccess` に保存。

```apache
# INDEXINDEX migration: before the existing www redirect, only the old subtree.
RewriteCond %{HTTP_HOST} ^(?:www\.)?morizaru\.net$ [NC]
RewriteCond %{THE_REQUEST} \s/+indexindex(?:/([^?\s]*))?(?:\?[^\s]*)?\s
RewriteRule ^indexindex(?:/.*)?$ https://indexindex.net/%1 [R=301,L,NE]
```

旧 /indexindex と /indexindex/ および配下のみ対象。/indexindex_test/、/indexindex2/、他コンテンツは対象外。新ホストでは実行しない。THE_REQUESTからエンコード済みパスを取得し、空白・#等の二重変換やフラグメント化を回避する。置換先にクエリを指定せず、元のクエリをそのまま引き継ぐ。QSDやクエリの組み直しは使用しない。

新ドメインのwwwがHTTPSで実在して200を返すことを確認したため、新規 indexindex/.htaccess に次を追加：

```apache
RewriteEngine On
RewriteCond %{HTTP_HOST} ^www\.indexindex\.net$ [NC]
RewriteCond %{THE_REQUEST} \s/+([^?\s]*)(?:\?[^\s]*)?\s
RewriteRule ^ https://indexindex.net/%1 [R=301,L,NE]
```

本番に既存 .htaccess がある場合は上書きせず、サーバー固有のハンドラー等を保存してこのルールを統合する。新ドメインDNSやサーバーHTTPS設定は変更していない。

### リダイレクトチェーン

ローカルApacheでは旧ホスト（www含む）→新HTTPSへ1回の301。新www→新正規ホストも1回。新ホスト→旧ホストへのルールなし。

本番反映前の旧HTTPS URLは200で、移行301はまだ稼働していない。本番HTTP URLは既に同一ホストのHTTPSへ301となる。サーバー側HTTPS強制が .htaccess より先に実行される場合、HTTP→旧HTTPS→新HTTPSの2段になる。HTTPも1回にするには、ホスティング側で旧 /indexindex/ 移行をHTTPS強制より優先する設定の確認が必要。サーバー設定の実体は未確認。新http://wwwも同様。

creatorの誤パラメータやwork_graphの不正pageなど、移行後に既存の正規化301が必要なURLは追加転送し得る。パス／クエリ維持と既存SEO仕様を優先し、今回のドメイン移行ルールでそれらを削らない。

## DB：構造・既存データの変更なし

本番DBへ接続・一括UPDATEはしていない。実データ中の旧URLは未確認。

コードから確認した保存先：

|テーブル／カラム|扱い|
|---|---|
|access_logs.full_url, referrer, referrer_host|実アクセスの履歴。過去の旧URLは通常そのまま残す|
|access_logs.path, access_bot_daily.path|上記の集計用互換パスを維持|
|work_mediamix.source_url|管理画面入力。旧サイトを引用している可能性を確認|
|external_magazine_collections.external_collection_url|MADB/NDL等の外部URL。自動置換しない|
|magazine_issues の external_issue_url / external_url / source_url / ndl_url|環境の実在カラムに応じて保存。スキーマ確認後に調査|
|source_images.image_path|新規登録は uploads/source_images/... の相対パス。既存データは要確認|
|自由記述 note / notes 等|URLが手入力されている可能性。対象スキーマを確認して調査|

本番で必要に応じて実行する読み取り専用SQL例（未実行）：

```sql
SELECT COUNT(*) AS old_url_rows FROM access_logs
WHERE full_url LIKE '%morizaru.net/indexindex%'
   OR referrer LIKE '%morizaru.net/indexindex%';
SELECT id, source_url FROM work_mediamix
WHERE source_url LIKE '%morizaru.net/indexindex%';
SELECT id, external_collection_url FROM external_magazine_collections
WHERE external_collection_url LIKE '%morizaru.net/indexindex%';
SELECT id, image_path FROM source_images
WHERE image_path LIKE '%morizaru.net/indexindex%';
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'magazine_issues'
AND COLUMN_NAME IN ('external_issue_url','external_url','source_url','ndl_url');
-- 上記で実在を確認したカラムごとに、次の <確認済みカラム> を置換して読む。
-- SELECT id, <確認済みカラム> FROM magazine_issues
-- WHERE <確認済みカラム> LIKE '%morizaru.net/indexindex%';
```

## キャッシュ

ranking.php は `dirname(__DIR__) . '/indexindex_cache'` を保存先、`ranking_cache_key(__DIR__, ...)` をキーとする。キーに設置ディレクトリのSHA-256由来識別子が含まれるため、別の物理ディレクトリなら同じキャッシュ親ディレクトリを使ってもキーが分離される。ドメイン名だけでは分離しない。同一実体へのエイリアス／symlinkの場合は共有し得るので本番配置を確認する。

保存内容は集計配列で、旧ドメイン入りHTMLではない。TTL・キー・ランキングSQL等は変更なし。新設置先の親に indexindex_cache が存在するか、書き込み権限・Web公開範囲を本番で確認する。保存先がない場合は既存どおりDB再集計へフォールバックするがキャッシュが効かない。既存回帰テスト59項目は成功。

## 検証結果

- PHP 8.2.4：変更・新規PHP合計28ファイル `php -l` 成功。
- `tools/domain_migration_test.php`：47項目成功。canonical、検索条件、空year、ログ互換性、ホスト境界を検証。
- `tools/ranking_cache_test.php`：59項目成功。TTL・保存失敗・設置先分離等。
- `git diff --check`：成功（既存のLF/CRLF変換警告のみ）。
- Apache 2.4.56：独立した一時サーバーで23ケース成功。詳細 `20261008_domain_migration_apache_test.txt`。
- 最初のエンコード済みパステストで旧案のURIデコード問題を検出し、THE_REQUEST方式へ修正して全件再検証。最終ルールで成功。
- 本番DB・サーバー設定への書き込みなし。curlでの通常アクセスに伴うログ記録は発生し得る。

### 本番の反映前確認（2026-10-08 JST）

詳細：`20261008_domain_migration_live_before.json`。curl GET/HEADによる観測。

|対象|結果|
|---|---|
|新 /, issues.php, works.php, creators.php, ranking.php, work.php?work_id=35072|全て200。全てcanonicalなし。旧URLのhref/src/actionは検出0|
|新 creator.php?creator_id=123|200、canonicalが旧URL|
|新 robots.txt|404|
|新 sitemap.php|200、子サイトマップ5件が旧URL|
|指定された旧HTTPS 5 URL|全て200。301未反映|
|旧www HTTPSトップ|旧non-wwwへ301|
|新www HTTPSトップ|200（正規ホストへの転送未反映）|
|新non-www HTTPトップ|新non-www HTTPSへ301|
|旧HTTP／旧www HTTPのwork、新www HTTPのwork|それぞれ同じホストのHTTPSへ301|

初回HEADのrankingに一度15秒タイムアウトがあったが、後続のGET検証は200。DB依存画面の本番反映後の表示、canonical、noindex、404、子サイトマップ内locの全件確認は残作業。

## 配備手順・本番での手動確認

1. 本番の新旧 .htaccess と対象ファイルを保存し、本番専用 app/config.php に旧URL、cookieドメイン、画像URL、設置パスがないか確認。ローカルにない設定を上書きしない。
2. 新ドメインへ変更した内側 indexindex/ のPHP、includes/public_url.php、robots.txt、.htaccessルールを反映。Detail/・tools/ のテストやレポートは公開に不要。既存のPHPハンドラー等がある場合は統合する。
3. 新サイトの200／canonical／サイトマップ／robots／相対リンクを確認してから、旧morizaru.netルートへ移行301を反映。他コンテンツは移行対象にしない。
4. nginx／ホスティングのキャッシュが残る場合は管理画面で対象キャッシュを更新し、旧200や古いcanonicalが残っていないことを確認。
5. 以下のcurlを実行。旧URLのLocationがパスとクエリを保持し、新URLは200、通常URLは移行301が1回であることを確認。www・HTTPの先行転送も確認。
6. 存在しないwork_idの404、creator誤パラメータ301、work_graphの既存noindex・正規化、issue/creatorのnoindex条件を実データで確認。
7. アクセス記録のfull_urlが新ドメイン、集計pathが旧形式、ランキングが更新されることを確認。新キャッシュの保存先／キー／権限を確認。
8. 新ドメインのHTTPS証明書・wwwのリダイレクト・DNS管理状態を確認。HTTPも一段にする場合は前述のサーバー側ルール順序を調整。

```powershell
curl.exe -I "https://morizaru.net/indexindex/work.php?work_id=35072"
# 301 / Location: https://indexindex.net/work.php?work_id=35072
curl.exe -I "https://indexindex.net/work.php?work_id=35072"
# 200
curl.exe -I "https://www.morizaru.net/indexindex/work.php?work_id=35072"
# 301 / Location: https://indexindex.net/work.php?work_id=35072
curl.exe -I "https://www.indexindex.net/work.php?work_id=35072"
# 301 / Location: https://indexindex.net/work.php?work_id=35072
curl.exe -sS -L --max-redirs 5 -o NUL -w "status=%{http_code} redirects=%{num_redirects} final=%{url_effective}\n" "https://morizaru.net/indexindex/work.php?work_id=35072"
# status=200 redirects=1 final=https://indexindex.net/work.php?work_id=35072
curl.exe -sS "https://indexindex.net/work.php?work_id=35072" | Select-String 'canonical'
curl.exe -sS "https://indexindex.net/robots.txt"
curl.exe -sS "https://indexindex.net/sitemap.php"
curl.exe -sS "https://indexindex.net/sitemap_urls.php?type=static&page=1"
```

トップ、issues.php、works.php、creators.php、ranking.php、creator.php?creator_id=123についても同様に確認する。

## Search Console 運用作業

- indexindex.netを登録・所有権確認。
- https://indexindex.net/sitemap.php を送信。
- 主要新旧URLをURL検査し、301とGoogle選択canonical、インデックス状態を確認。
- **旧プロパティからの「アドレス変更」：今回は /indexindex/ というパス単位の移転なので対象外。morizaru.net 全体のアドレス変更を申請しない。** 他コンテンツも移転対象になってしまう。部分移転は対象URLの301・新canonical・サイトマップで対応する。

Google公式： https://support.google.com/webmasters/answer/9370220 （パス単位のプロパティはアドレス変更ツール対象外）。
Apache公式： https://httpd.apache.org/docs/2.4/rewrite/flags.html （R/L/NEとクエリ保持、エンコードの扱い）。

## 未確認事項

本番反映後の全画面、実DB内URL、本番INDEXINDEX config、実際の新旧DocumentRootとキャッシュ書き込み権限、サーバー側HTTPS強制の実装とルール順、DNS管理設定、Search Console反映。HTTP一段転送と本番の移行301成立は未完了として扱う。

## 変更ファイル一覧
- indexindex/about.php
- indexindex/admin/access_analytics.php
- indexindex/admin/ndl_collection_extract.php
- indexindex/admin/ndl_import_year.php
- indexindex/admin/ndl_toc_url_finder.php
- indexindex/admin/x_post_draft.php
- indexindex/contact.php
- indexindex/creator.php
- indexindex/creators.php
- indexindex/data_policy.php
- indexindex/how_to_use.php
- indexindex/includes/access_logger.php
- indexindex/index.php
- indexindex/issue.php
- indexindex/issues.php
- indexindex/mediamix.php
- indexindex/privacy.php
- indexindex/ranking.php
- indexindex/recent_updates.php
- indexindex/search.php
- indexindex/sitemap.php
- indexindex/sitemap_urls.php
- indexindex/terms.php
- indexindex/work.php
- indexindex/work_graph.php
- indexindex/works.php
- indexindex/.htaccess
- indexindex/robots.txt
- indexindex/includes/public_url.php
- indexindex/tools/domain_migration_test.php
- ../.htaccess（リポジトリ外の旧サイトルート）
- indexindex/Detail/20261008_domain_migration_* と 20261008_morizaru_root.htaccess（本報告・証跡）

## 旧参照の各ヒット分類

以下の行番号は変更前。元の記述全文は original_hits.txt に保存。

|変更前ファイル:行|分類・理由|
|---|---|
|indexindex\about.php:16|変更：新ドメインの公開URL|
|index.php:2|変更不要：旧ポータルの名称・コメント|
|index.php:7|変更不要：旧ポータルの名称・コメント|
|index.php:415|変更不要：旧ポータルの名称・コメント|
|indexindex\admin\.htaccess.txt:3|要確認：AuthUserFileのサーバー絶対パス|
|indexindex\admin\access_analytics.php:270|旧履歴判定を残し、新ホストを追加|
|indexindex\data_policy.php:16|変更：新ドメインの公開URL|
|indexindex\Detail\20260721_adsense_content_improvement.txt:5|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:73|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:104|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:105|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:106|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:107|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:108|変更不要：過去の調査記録|
|indexindex\Detail\20260721_adsense_content_improvement.txt:109|変更不要：過去の調査記録|
|indexindex\contact.php:19|変更：新ドメインの公開URL|
|indexindex\creator.php:11|変更：新ドメインの公開URL|
|indexindex\creator.php:487|変更：新ドメインの公開URL|
|indexindex\admin\Detail\FIX_CHECKBOX_STATE_POST_2026-08-31.md:221|変更不要：過去の調査記録|
|indexindex\Detail\20260807_issue_status_verified_list_visibility.md:6|変更不要：過去の調査記録|
|indexindex\Detail\20260807_issue_status_verified_list_visibility.md:7|変更不要：過去の調査記録|
|indexindex\admin\Detail\IMPLEMENTATION_CHECKBOX_ALWAYS_VISIBLE.md:376|変更不要：過去の調査記録|
|indexindex\Detail\20260807_work_edit_issue_label_numeric_order.md:7|変更不要：過去の調査記録|
|indexindex\Detail\20260807_work_edit_issue_year_priority_order.md:7|変更不要：過去の調査記録|
|indexindex\Detail\20260807_work_edit_combined_issue_order.md:7|変更不要：過去の調査記録|
|indexindex\admin\Detail\MODIFICATION_CHECKBOX_ALWAYS_VISIBLE_2026-08-31.md:136|変更不要：過去の調査記録|
|indexindex\admin\Detail\IMPLEMENTATION_HIDDEN_FIELD.md:434|変更不要：過去の調査記録|
|indexindex\admin\Detail\QUICK_GUIDE_DEBUG_LOGS.md:147|変更不要：過去の調査記録|
|indexindex\admin\Detail\MODIFICATION_DEBUG_LOGS_TOGGLE_2026-08-31.md:120|変更不要：過去の調査記録|
|indexindex\admin\Detail\MODIFICATION_DEBUG_LOGS_TOGGLE_2026-08-31.md:128|変更不要：過去の調査記録|
|indexindex\work_graph.php:119|変更：新ドメインの公開URL|
|indexindex\privacy.php:14|変更：新ドメインの公開URL|
|indexindex\how_to_use.php:16|変更：新ドメインの公開URL|
|indexindex\admin\ndl_collection_extract.php:39|変更：新ドメインの公開URL|
|indexindex\includes\access_logger.php:12|変更不要：旧ポータル互換のコメント／ホスト省略時の既定値|
|indexindex\includes\access_logger.php:20|変更不要：旧ポータル互換のコメント／ホスト省略時の既定値|
|indexindex\includes\access_logger.php:29|変更不要：旧ポータル互換のコメント／ホスト省略時の既定値|
|indexindex\includes\access_logger.php:30|変更不要：旧ポータル互換のコメント／ホスト省略時の既定値|
|indexindex\includes\access_logger.php:109|変更不要：旧ポータル互換のコメント／ホスト省略時の既定値|
|indexindex\admin\ndl_import_year.php:40|変更：新ドメインの公開URL|
|indexindex\admin\ndl_toc_url_finder.php:36|変更：新ドメインの公開URL|
|indexindex\sitemap_urls.php:4|変更：新ドメインの公開URL|
|indexindex\sitemap.php:4|変更：新ドメインの公開URL|
|indexindex\Detail\20260820_work_graph_gsc_5xx_url_normalization.md:126|変更不要：過去の調査記録|
|indexindex\Detail\20260820_work_graph_gsc_5xx_url_normalization.md:262|変更不要：過去の調査記録|
|indexindex\Detail\20260820_work_graph_gsc_5xx_url_normalization.md:263|変更不要：過去の調査記録|
|indexindex\Detail\20260820_work_graph_gsc_5xx_url_normalization.md:279|変更不要：過去の調査記録|
|indexindex\work\indexindex_access_analytics_graph_monthly_complete\admin\access_analytics.php:139|変更不要：過去の実装保存版|
|indexindex\work\indexindex_access_analytics\admin\access_analytics.php:90|変更不要：過去の実装保存版|
|indexindex\terms.php:16|変更：新ドメインの公開URL|
|indexindex\work\indexindex_access_analytics\includes\access_logger.php:82|変更不要：過去の実装保存版|
|indexindex\admin\x_post_draft.php:155|変更：新ドメインの公開URL|
|indexindex\Detail\20260919_public_performance_investigation.md:3|変更不要：過去の調査記録|
|indexindex\Detail\20260919_ranking_cache_performance.md:83|変更不要：過去の調査記録|
|indexindex\Detail\20260919_ranking_cache_performance.md:95|変更不要：過去の調査記録|
|indexindex\Detail\MODIFICATION_REPORT.md:4|変更不要：過去の調査記録|
|indexindex\Detail\MODIFICATION_REPORT.md:19|変更不要：過去の調査記録|
|indexindex\Detail\MODIFICATION_REPORT.md:75|変更不要：過去の調査記録|
|indexindex\Detail\20260930_work_graph_zero_result_normalization.md:43|変更不要：過去の調査記録|
|indexindex\Detail\20260930_work_graph_zero_result_normalization.md:97|変更不要：過去の調査記録|
|indexindex\Detail\20260930_work_graph_zero_result_normalization.md:102|変更不要：過去の調査記録|
|indexindex\Detail\20260930_work_graph_zero_result_normalization.md:103|変更不要：過去の調査記録|
|indexindex\Detail\20260930_work_graph_zero_result_normalization.md:136|変更不要：過去の調査記録|
