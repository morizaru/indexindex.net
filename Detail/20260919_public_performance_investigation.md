# 公開ページの表示速度調査と限定的なSQL改善

調査日：2026-09-19。対象サイト：`https://morizaru.net/indexindex/`。

## 症状・結論

公開ページ全体で最近やや表示が重く感じる、という申告に基づく調査。

**本番の遅延原因は未特定。** コードから不要な人物JOINと同一範囲の重複集計を特定し、2ファイルだけ改善した。最も負荷が大きそうな候補は、TOP・ランキングのアクセスログ集計。次いで一覧ページの広範囲JOIN・集約、および一部ページのアクセスログによる行動bot判定がある。実行時間や各テーブルの現在の行数を測定していないため、実際の重さの順位や改善率は断定しない。

## 調査対象

- 公開ページ：`index.php`、`issues.php`、`issue.php`、`works.php`、`work.php`、`work_graph.php`、`creators.php`、`creator.php`、`ranking.php`、`recent_updates.php`。
- 共通処理：`includes/access_logger.php`、`includes/header.php`、`includes/footer.php`、`includes/sidebar.php`、`includes/adsense_head.php`。
- 人物関連：`app/content_creator_display.php`、`app/work_creators.php`（後者は取得SQLの検索確認）。
- CSS：`assets/css/common.css`、各公開ページのインラインCSS・JS。
- 運用ルール：`AGENTS.md`。
- 検証方法の参考：`tools/work_creators_test.php` 冒頭の独立DBテスト方針。
- 保存済みDDL：`work/zq4vi_indexindex.sql` の生成日時・CREATE/ALTER/INDEX・制約部分、`work/indexindex_access_analytics/sql/access_logs.sql`。ダンプの投入・データ更新はしていない。
- 補助検索：`search.php`、`mediamix.php` のSQL呼び出し位置のみ。これらの全体評価・改修は今回含めない。

`app/config.php` はローカルに存在しない。MySQL/MariaDBのCLIも見つからず、実DBの接続情報は利用していない。保存済みダンプは2026-05-19生成、MySQL 5.7.44-logの記載があるが、現在の本番DB構成・バージョンを示すものとは扱わない。

対象URLをWeb取得ツールで1回参照したが取得できなかった。これはサイト停止や応答遅延を示す測定結果ではない。HTTPのTTFB、PHP処理時間、SQL実行時間、実ブラウザの通信時間は未測定。負荷テストや本番へのSQL実行はしていない。

## ページ別SQL・処理一覧

本数は正常経路のページ本体の実行SQL数。共通アクセスログ処理・接続初期化は含まない。SHOW COLUMNSは別記のうえ本数に含める。ソース中のprepare出現数ではなく、分岐・関数呼び出しを考慮した静的な数。LIMITがあっても、集約前の読取り範囲がページ件数に限定されるとは限らない。

| ページ | 修正前 → 後 | 大きな処理・集約範囲 | 静的評価 |
| --- | --- | --- | --- |
| index.php | 15 → 15 | 5テーブルのCOUNT各1回、全期間アクセスランキング3回、SHOW COLUMNS 4回、最近の更新3回 | 優先調査。特にaccess_logs集約 |
| ranking.php | 15 → 15 | 3分類×5期間（全期間・今月・今日・先月・昨日）のランキング。COUNT DISTINCT・GROUP BY・ORDER BY | 優先調査。固定回数のループ内SQLあり |
| works.php | 5 → 5 | 出版社・雑誌・年の選択肢3回、総件数1回、一覧1回。全掲載をJOINして作品単位に集約、1ページ100件 | 優先調査。2本のSQLから不要な人物JOINを除去 |
| creators.php | 6 → 6 | 出版社・雑誌・年・役割の選択肢4回、COUNT DISTINCTと一覧集約2回。掲載経由の作品名GROUP_CONCAT、1ページ100件 | 優先調査。全人物から掲載へLEFT JOIN |
| issues.php | 通常5、雑誌指定6、追加選択条件で最大+2 → 同じ | 選択肢3回、雑誌一覧のCOUNT/一覧、または雑誌情報/号COUNT/号一覧。目次・人物JOINとDISTINCT、1ページ50件 | 一覧は優先調査、号一覧は対象雑誌に限定 |
| creator.php | 5 → 4 | 人物情報、同じ人物の掲載COUNTと概要、一覧件数、掲載/作品一覧。人物単位だが掲載数が多いと集約負荷増 | 重複集計を修正。1ページ100件は維持 |
| work.php | 6 → 6 | 作品・作品人物・掲載COUNT・掲載一覧・メディアミックス・概要。issue_contentsはCOUNT/一覧/概要で3回参照 | 作品に限定。長期連載は掲載一覧にLIMITがなく注意 |
| work_graph.php | 通常5、作品指定で通常8、出版社/雑誌指定で最大10 → 同じ | 選択肢3回、選択ID情報0～3回、グラフ年/本体2回、作品一覧COUNT/本体2回。ゼロ件・リダイレクトで減る | 一覧と長期間グラフは要確認 |
| issue.php | 4 → 4 | SHOW COLUMNS 1回、号情報、同じ雑誌の全公開号、当該号の目次・人物集約 | 比較的限定的。ただし前後号探索のため全号ソート |
| recent_updates.php | 8 → 8 | SHOW COLUMNS 4回、4種類の最新20件。COALESCE(updated_at, created_at)でORDER BY | JOINは少ない。式ソートのコストは要確認 |

現行の `works.php` は `$keyword=''`、出版社/雑誌IDが0に固定され、`creators.php` も旧横断検索・出版社/雑誌IDが固定値。`issues.php` も `$keyword=''`。旧分岐を数えて、現在の実行本数や検索負荷を過大評価しないようにした。この入力仕様は変更していない。

取得行数に比例してSELECTを増やすN+1は、調査対象10ページで見つからなかった。ランキングのループは固定15本で、取得人物数に依存するN+1とは異なる。パラメータbindのforeach、描画のforeachも追加SQLを実行していない。主要SQLにSELECT *は見つからず、主な懸念は列数よりJOIN後の行数・集約範囲。

「全件集約」「広範囲」と記した箇所はコード上の範囲を意味する。実行計画でtype=ALLのテーブル全走査を確認したという意味ではない。

## 重いSQL・処理の候補

### 1. TOP・ランキングのaccess_logs集約

`index.php` の `index_fetch_access_ranking()` は全期間の生ログを対象に作品・雑誌・人物のPV/UUを計算する。`ranking.php` の `fetch_ranking_safe()` はカテゴリごとに5期間分実行される。

作品ランキング等は `query_string REGEXP` と `CAST(SUBSTRING_INDEX(...))` でURLからIDを取り出し、作品等にJOINして `GROUP BY`、`COUNT(DISTINCT visitor_hash)`、PV/UUによるORDER BYを行う。雑誌は雑誌一覧のログと号ページのログをUNION ALLして再集約するため、1本のSQL内にも2つのログ参照がある。

LIMIT 5/10/20/50は集約後の表示件数。ログの集計範囲を5件等に制限するものではない。ログが増えるほど負荷が増える構造は確認できるが、実際の読取り行数と実行時間は未確認。

TOPの登録雑誌・号・作品・人物・目次COUNTはそれぞれ1回だけで、同じCOUNTの重複実行はない。結果は変数として再利用されている。ただし毎リクエストのCOUNT自体は残る。

### 2. 共通アクセスログの同期処理

全対象ページが本体取得前に `ii_access_log()` を呼ぶ。通常アクセスではaccess_logsへのINSERTが1回。既知botは通常ログを保存せず、日別・User-Agent別集計に最大2回UPSERTする。

参照元なしの一部ページには追加SELECTがある。

- `work.php`：特定作品の直近60分集計と公開ページburst判定で最大2本。
- `work_graph.php`：直近30分集計、条件次第で最多UAの集計、公開ページburst判定で最大3本。
- `works.php`・`ranking.php`：公開ページburst判定で最大1本。
- 各判定は早期returnがあり、これらの最大本数が常に実行されるわけではない。

COUNT DISTINCT・文字列からの作品ID抽出をHTML生成より前に実行するため、条件により体感速度が変わる候補。botとして取得した測定と人間のブラウザ・参照元ありのアクセスでも経路が異なる。判定・ログ保存仕様の変更は行っていない。

### 3. 一覧の集約と検索・選択肢

works/creators/issuesは一覧本体とは別に件数を求め、GROUP BY/DISTINCTの前段で掲載・人物へJOINする。人物や役割が複数あると中間行数が増える。今回worksの不要JOINだけを限定的に修正した。

`creators.php` のCOUNTは `creators → content_creators → issue_contents → works → magazine_issues → magazines` のLEFT JOINを持つ。現行の氏名検索はc側、年・役割絞り込みはEXISTS側なので、COUNT側のJOIN整理は次の候補。ただし今回は変更していない。

`issues.php` の雑誌一覧にも、現行の空keywordでは使わないworks/content_creators/creatorsへのLEFT JOINが残る。これも次の限定改修候補。

選択肢作成のDISTINCT、年GROUP BYとGROUP_CONCATも毎回実行される。特に `creators.php` の出版社・雑誌配列は現行の固定ID=0では描画に使われず、`works.php` も旧選択肢処理が残っている。削除可能範囲の確定と当該ページ全体の出力検証を別途行う候補として記録し、今回は削除していない。

部分一致LIKE、REGEXPによる頭文字、COALESCE/YEAR/CASTによる年条件は、単純なB-treeの直接検索・整列と一致しない場合がある。INDEX追加や前方一致への仕様変更はしない。実行計画確認前に「インデックスが使われない」とは断定しない。

### 4. 個別ページの範囲と重複処理

- `creator.php`：掲載COUNTと概要のFROM/JOIN/WHEREが完全に同じ。今回1本へ統合。
- `work.php`：COUNTは作品の全掲載、一覧は検索条件付きで複数のINNER JOIN、概要は別のJOIN条件。単に `count($contents)` へ置き換えると検索時の説明文/noindex等が変わり得るため統合しない。
- `work.php`：作品の全掲載を取得・描画し、グラフ年リンクのため年ごとに `$contents` を再走査。N+1 SQLではないが、PHPの繰り返し処理候補。
- `work.php`：一覧SQLの `creators` と `creators_data` は人物を2通りGROUP_CONCATしている。画面は `creators_data` を使用。未使用集約の削除は次の候補として残した。
- `issue.php`：前後号のため同じ雑誌の全公開号を式ORDER BYしてPHPで位置検索。現在の複雑な日付/号順を保つ必要があり、無計測でLIMITへ変更しない。
- `work_graph.php`：対象作品・年範囲の全掲載をグラフ化するため本体にはLIMITなし。作品一覧は別途集約する。表示件数・グラフ範囲は変更しない。

### 5. 最近の人物関連変更

`work.php` 上部の `work_creators JOIN creators` はwork_id指定の1本で一括取得。人数分のSELECTを追加しておらず、下部content_creatorsのJOIN件数を増やす結合にもしていない。

対象公開10ページでは、掲載人物の `display_name/display_title/display_affiliation` を使う新しい取得処理は見つからなかった。現行の `work.php`・`issue.php` は従来のroleとcreators.nameのGROUP_CONCATを使用する。これら3列が追加されたことだけを、今回の公開SQLの遅延原因とは判断できない。

`app/content_creator_display.php` の `load_issue_content_creators()` は号単位の一括取得後にPHPでcontent_id別に分類する構造で、ループ内SQLなし。対象公開ページからこの関数は呼ばれていない。ローカルと本番のソース差異は未確認。

### 6. 共通ファイル・CSS/JS

共通header/footer/sidebarにDB問い合わせはない。sidebarは `$show_affiliate_links=false` により早期return。issue.phpの列存在確認はstatic配列で同一リクエスト内をキャッシュし、2列チェックでもSHOW COLUMNSは1回。

インラインCSSは1ページ約4.2～12.4KB、インラインJSは0～6.3KB、共通CSSは415bytes。極端な肥大化は見つからなかった。PHPソース容量はレスポンス容量と同一ではなく、一覧データ量によるHTML増大は別問題。

TOPと共通headerのTypeSquareスクリプトはasync/deferなしで、ネットワーク状態により描画待ちの候補。AdSenseはasync。work_graph.phpでは共通AdSense includeに加え末尾にも同じscriptタグがある。Chart.jsも外部読込み。通信・フォント適用・広告の実ブラウザ測定なしに読み込み方式を変更すると挙動へ影響し得るため変更していない。

## 修正内容

### creator.php：同じ範囲の集計を1回にする

従来の独立した `COUNT(DISTINCT ic.id)` を、同じFROM/JOIN/WHEREを持つ概要SQLのSELECTへ追加した。

```sql
SELECT
    COUNT(DISTINCT ic.id) AS total_count,
    COUNT(DISTINCT ic.work_id) AS work_count,
    COUNT(DISTINCT ic.issue_id) AS issue_count,
    GROUP_CONCAT(
        DISTINCT CASE
            WHEN cc.role IS NOT NULL AND cc.role <> '' AND cc.role <> 'author'
            THEN cc.role
        END
        ORDER BY cc.role SEPARATOR ' / '
    ) AS roles
FROM content_creators cc
JOIN issue_contents ic ON cc.content_id = ic.id
WHERE cc.creator_id = :creator_id
```

この結果から従来どおりnoindex・概要表示用変数を設定する。検索結果の件数SQLはJOIN条件が異なるため統合しない。正常な個別ページで5本→4本となり、同じ人物の掲載集合を読むクエリが1本減る。COUNT DISTINCT自体の計算は必要なので、ページ時間が20%減るという意味ではない。

### works.php：集計に不要な人物JOINを省く

COUNTと一覧の両方で、次のJOINを `$keyword !== ''` のときだけ追加する。

```sql
LEFT JOIN content_creators cc ON ic.id = cc.content_id AND cc.is_visible = 1
LEFT JOIN creators c ON cc.creator_id = c.id
```

現行コードはkeywordが空固定なので、通常は両JOINが不要。既存の検索条件生成との対応を明示するため条件付きSQL変数とした。入力の扱いを変えて横断検索を復活させる変更はしていない。

COUNT DISTINCT・MIN/MAX・出版社/雑誌名のDISTINCT GROUP_CONCATには人物列を使用しない。LEFT JOINが増やす重複行をなくしてもこれらの意味は維持される。SQL本数は5本のまま、うち2本から人物2テーブルのJOINとそれによる行数増加を除く。人数が多い掲載ほど中間行の削減余地があるが、本番の実行計画・削減行数は未測定。

## 修正しなかった項目・DB確認待ち

INDEX、テーブル、データ、URL、canonical、noindexの条件、検索仕様、表示件数、掲載人物の表示、sitemap、cronは変更していない。キャッシュ/APCu/Redis/Memcachedの導入もない。

保存済みDDLには、issue_contents(work_id)/(issue_id)、content_creators(creator_id)/(content_id)、magazine_issues(magazine_id)、access_logsの時刻/サイト・時刻/パス・時刻等がある。ただし現行本番に存在するとは断定できず、work_creators等の現在の構成も不足している。この先のINDEX判断・実行計画の検証は、次の情報が届くまで停止する。

まず現在の本番DBで以下の結果を確認したい。データ行や接続情報は不要。

```sql
SHOW INDEX FROM access_logs;
SHOW INDEX FROM issue_contents;
SHOW INDEX FROM content_creators;
SHOW INDEX FROM work_creators;
SHOW INDEX FROM magazine_issues;
SHOW INDEX FROM works;
SHOW INDEX FROM creators;
```

確認目的は、access_logsのsite_key/is_bot/path/access_timeによる絞り込み、issue_contentsのwork_id/issue_id、content_creatorsのcreator_id/content_id/is_visible、work_creatorsのwork_id/display_order/idに対する既存キーの先頭列・列順・cardinality。複合INDEXの要否は、実際の行数・選択性・EXPLAINを見てから判断する。現時点で具体的なCREATE INDEXは提案しない。

優先する通常EXPLAINは、TOPの全期間作品ランキング、rankingの期間付き作品/雑誌ランキング、worksのCOUNTと一覧、creatorsのCOUNTと一覧、ログ判定SQL、workの一覧・概要、creatorの統合集計。type、possible_keys、key、rows、Extra（Using temporary/filesortを含む）を比較する。今回EXPLAINは実施していない。EXPLAIN ANALYZEはSQLを実行するため、本番で安易に実行しない。

キャッシュなしでも今回のように不要JOIN・重複集計を削減できる。全期間ログ集計は仕様上残るため、測定で支配的と判明した場合はランキング結果の短時間キャッシュが候補になる。ヒット時はTOPの3本、ランキングの最大15本の集計を省ける可能性があるが、経過時間の改善率は不明。更新反映の遅延や期間境界・limit別の扱いを伴うため、必要性を決めず、実装もしない。

## 影響範囲

実装変更は `creator.php` と `works.php` のSQL組み立て・結果変数の代入のみ。HTML/CSS/JS、共通アクセスログ、人物表示方法は変更なし。DB構造・INDEX・既存データへの変更なし。出力の意味は維持し、検索・ページング・ソート・SEOに意図した変更はない。

## 検証

- PHP 8.5.10で対象公開10ファイル、includesの5ファイル、人物関連appの2ファイル、計17ファイルの `php -l` 成功。今回の変更2ファイルも含む。
- `git diff --check` 成功。
- 変更前HEADの2ファイルと変更後ファイルを、一時PHPハーネスでそれぞれ実行して出力比較。configとアクセスログ呼出しをテスト時のみ除外し、SQLiteメモリDBへ接続。共通header/footerは同じローカル実装を使用。
- SQLは各PHPが実際に生成するものを利用。ただしMySQLのGROUP_CONCAT構文・文字変換をSQLite用へテスト時のみ変換し、REGEXP/YEAR/LOCATE等を互換関数で代用した。これは本番MySQLの照合順序・切詰め・実行計画・性能を検証するテストではない。
- 固定データ：掲載作品105件と未掲載作品、2出版社/雑誌/号、人物3件、複数役割・非表示人物・人物なしの掲載・役割NULL/空文字・作品NULL・参照先なしを含む。DBへ投入したのはこの一時メモリデータのみ。
- works 20ケース：4種ソート×昇降順、2ページ目/超過ページ、タイトル一致/0件、年・年候補・区分・頭文字、旧パラメータの扱い。
- creator 20ケース：掲載あり/なし×掲載/作品タブ×検索なし/一致/0件の12ケース、タブ×ソート×昇降順の2ページ目8ケース。
- **計40ケースすべてでHTML全文・総件数・noindex用値が一致。** 人物概要の既存値も一致。worksのSQL数は5→5で人物JOINを含むSQLが2→0、creatorのSQL数は5→4であることをハーネスで確認した。
- 新たなループ内SQLは追加していない。削除対象となるN+1は見つかっていない。
- テスト用ファイルは `/private/tmp/indexindex-performance-before/`、`/private/tmp/indexindex-performance-render.php`、`/private/tmp/indexindex-performance-compare.py` に作成した一時成果物。実装には追加していない。

## 未確認事項・本番で確認するページ

- 実DBの行数・SHOW INDEX・EXPLAIN・SQL時間・PHP時間・負荷状況・ロック待ち・ログ増加量。最近の遅延との因果関係。
- 本番とローカルのコード/DB差異。現在のMySQL/PHPバージョン・照合順序での動作。
- 実ブラウザのTTFB/描画時間、TypeSquare/AdSense/Chart.jsの通信・実行時間、長い一覧のHTML量。
- まず `index.php`、`ranking.php`、`works.php`、`creators.php`。次に掲載の多い `creator.php` と `work.php`、作品指定の `work_graph.php`、`issues.php` の雑誌指定あり/なし、`issue.php`、`recent_updates.php`。
- 変更2ページは検索・ソート・2ページ目・0件表示・人物の掲載/作品タブを本番相当DBで確認する。
- `indexindex_test` へのアップロード・実ブラウザ確認・本番反映は未実施。現在のINDEX情報が必要な追加最適化は行わず、確認待ちとする。
