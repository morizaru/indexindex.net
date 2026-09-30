# work_graph.php 0件・不正URL調査と最小修正

## 作業範囲・結論

- 変更PHP: `indexindex/work_graph.php`、`indexindex/work.php`。
- 記録: 本Detail。配置対象は上記PHP 2ファイル。
- ユーザーの追加指示に従い、DBを必要とする実行検証、設定作成、認証回避、テスト／本番へのアップロードは行っていない。追加指示以降の本番接続も行っていない。
- PHP構文・差分・コードの静的確認は完了。修正後のHTTP、実DB、サーバーログは未検証。テスト配置後の合格をもって本番反映可否を判断する。

## 1. 現在の処理フロー

1. Content-Type設定、既存config読み込み、アクセスロガー呼び出し。
2. GETを取得。文字列は配列を除外してtrim、ID／年は正の整数のみ採用。pageは省略時1、不正値は1に正規化。sortはtitle/content_count/year、orderはasc/desc。
3. 出版社・雑誌・年の選択肢、選択中の名前を取得。存在しない作品名のfetchは `['title'] ?? ''` で空文字になる。
4. 下部作品一覧の条件を組み立てる。連載・出版社・雑誌・作品検索・人物検索・yearを使用。work_idとyear_from/year_toは一覧条件ではない。
5. 選択作品の掲載年を取得。yearのみの指定なら表示範囲へ変換し、未指定の端点を掲載年の最小／最大で補完する。逆順の端点は入れ替える（掲載年がある場合）。
6. グラフSQLを実行。work_id・連載・出版社・雑誌・作品検索・人物検索・year_from/year_toを使用。SQLの列・JOINは変更していない。
7. グラフ0件なら下部一覧を抑制し、一覧COUNT・一覧取得を省略する。page付きまたは非正規クエリなら条件を維持して301。
8. それ以外は一覧COUNT後に総ページ数を決定。不要／不正／範囲外pageと非正規クエリを301で整理し、有効ページのみLIMIT/OFFSET取得。
9. グラフ選択時はグラフ行数、未選択時は一覧件数で0件を判断。0件のみrobots metaを出し、自己canonical・検索フォーム・結果を描画。

## 2. 原因と修正箇所

| 箇所（修正後の行番号） | 調査結果・変更理由 | 変更 |
| --- | --- | --- |
| work_graph.php:23,37 | 整数キャストだけでは上限超過値がPHP_INT_MAXに丸められ、別のIDとして扱われる | FILTER_VALIDATE_INTで上限超過を不正値扱い。ID／年は0、pageは1へ正規化 |
| work_graph.php:643 | 範囲外pageは従来、最終ページへ301だった | 1ページ目＝pageなしへ301。今回の確定仕様に合わせる |
| work_graph.php:693 | グラフ行が存在しても一覧COUNT=0だけでnoindexとなり得た。yearと表示範囲は異なるSQL条件 | グラフ選択時はempty($rows)、未選択時はtotal_count===0を使用 |
| work_graph.php:1669 | 別作品のグラフリンクにも選択中作品のyear_from/year_toをコピーしていた。一覧SQLはその範囲では絞らないため通常操作でも不一致が発生し得る | 別作品は一覧で既に取得済みのfirst_year/last_yearを使用。同じ作品は選択範囲を維持 |
| work.php:1072 | 掲載がある非連載作品にもグラフリンクを出すが、グラフSQLは連載のみ | 公開年別グラフリンクを連載作品に限定。元の掲載年・出版社・雑誌の取得は維持 |

例示URLの0件自体は「作品がない」ことを意味しない。作品IDと期間の組み合わせによるグラフSQLの0件である。
5xxの発生原因は、実DB・サーバーログ・当時の配置コードなしには断定できない。現行コードには既に0件対応があり、今回「0件をTOPへ送る処理を撤去した」という変更ではない。

## 3. 0件・robots・canonical・301の位置

- グラフ0件判定: work_graph.php:620 `$suppress_work_list = ($has_graph && empty($rows))`。
- グラフ0件時の301: 622以降。条件を維持してpageを除去し、クエリが一致すれば再転送しない。
- 一覧COUNTとpage判定: 629以降、643〜652。転送判定はOFFSET計算前なので深いpageで巨大OFFSETを実行しない。
- 301共通関数: 122 `redirect_work_graph_url()`、Location設定後exit。
- 最終0件判定: 693 `$is_zero_result`。
- robots出力: 700〜702 `<meta name="robots" content="noindex,follow">`。
- canonical生成／出力: 118、694、703。`https://morizaru.net/indexindex/` 固定の既存仕様。
- フォーム: 1257以降。work_id hidden、作品名補完、人物検索、出版社、雑誌、year、表示範囲を維持。指定端点が掲載年候補にない場合も選択肢に加える既存処理を維持。
- 存在しない正整数work_idもhas_graph=trueのまま。名前は空、グラフは空配列、一覧を省略し0件描画する。IDを消してTOPへ転送しない。fetch失敗時のnull合体、配列初期化、空foreachは静的に確認済み。

## 4. URL正規化（変更不要だった部分）

`build_work_graph_url()` と生のQUERY_STRINGの比較を維持。

- 順序は publisher_id → magazine_id → work_q → creator_q → year → year_from → year_to → work_id → sort → order → page。
- page=1、不正page、空文字、無効ID／年、sort=title、order=ascを省略する。
- 無効sortはtitle、無効orderはasc。DESCなどは小文字へ統一する。
- 重複キーはPHPのGET解釈（通常の同名スカラーは最後の値）を維持し、URL生成で1キーにする。配列値は採用しない。
- パラメータ順、未知パラメータ、空値、エンコード表記差も生成URLとの不一致なら301で正規化する。値が同じ正規URLは転送しない。
- 有効なpage=2以降は維持し、canonicalも当該ページを指す。
- 未指定のグラフ年範囲をDBの掲載年で補う既存仕様は維持。この補完による301も従来どおり。
- 0件だからという理由のTOP転送／404／410は追加していない。
- wwwの統一はこのPHP内に処理がなく、配備先Webサーバー側の既存処理。対応する設定はリポジトリで確認できず変更しない。PHPの相対Locationを維持し、テストパスから本番へ転送する変更もない。
- テスト環境でもcanonicalのベースは本番 `/indexindex/` のまま。Locationはテストパス内、canonicalは本番パス、という区別で検証する。
- DB障害を200に偽装する例外握りつぶしは追加していない。config・SQL・DB構造・ロガー・共通ヘッダー・robots.txt・サイトマップは変更不要。

## 5. 内部リンクとサイトマップの全体調査

PHP/JS/HTML内を `work_graph`、`make_graph_url`、`build_work_graph` で検索。

| 箇所 | 結果・対応 |
| --- | --- |
| work_graph.php:140 ページネーション／188 pageジャンプ | リンクは総ページ数内のみ。0件グラフは一覧ごと非表示。フォームmaxは総ページ数。サーバーでも範囲外を301にする |
| work_graph.php:228,1622 並び替え | 1ページ目に戻し、有効条件を維持。グラフSQLの条件は変わらず、変更不要 |
| work_graph.php:1257 検索フォーム／1404 表示年フォーム | 利用者が矛盾する条件を指定することは可能。0件の200＋noindexと条件修正を許容。生成を全面禁止しない |
| work_graph.php:1674 一覧のグラフリンク | 元作品の年範囲の流用を修正。既存一覧のMIN/MAXを使うのでSQL追加なし |
| work.php:1086 年リンク | 実際のcontentsの年ごとに最初の行のpublisher_id/magazine_idを利用。作品・年の関連は正しい。非連載だけリンクを抑制 |
| works.php／ranking.php | 公開の作品一覧・ランキングから直接グラフへのリンク生成なし。ranking.php内の文字列はPV集計対象パスで、リンクではない |
| index.php:71／index2.php:34／includes/header.php:20 | 条件なしグラフTOPへの入口。矛盾する作品・年の組み合わせは作らない |
| admin/works.php:746／admin/work_edit.php:556,644 | IDのみの管理用プレビュー。未掲載／非連載なら0件になり得る。編集確認の導線として維持し、0件処理に委ねる |
| includes/access_logger.php／admin/access_analytics.php | 判定・集計用文字列。公開グラフリンク生成ではない |
| work_graph - コピー.php | バックアップ内にもwork_graph.phpへの旧式リンクあり。現行画面からこのコピーへの参照は検索で見つからない。別ページなので未変更。公開配備有無は未確認 |
| sitemap.php／sitemap_urls.php | static/magazines/issues/works/creatorsのみ。固定パスにもwork_graph.phpなし。作品はwork.phpへ出力。0件グラフURLを含む全グラフURLは出力対象外。変更不要 |

フォームでの任意条件入力、管理用プレビュー、ロボット生成まで0件を禁止しない。公開の別作品選択による意図しない年範囲引き継ぎを止める。

## 6. 実施済み検証と結果

| 検証 | 結果 |
| --- | --- |
| `C:\xampp\php\php.exe -l indexindex\work_graph.php` | No syntax errors detected（PHP 8.2.4） |
| `C:\xampp\php\php.exe -l indexindex\work.php` | No syntax errors detected（PHP 8.2.4） |
| `git diff --check` | 合格。改行コードのLF→CRLF予告のみ |
| 差分レビュー | SQL・正常page・canonical生成・既定sort/order・0件描画の既存仕様を維持 |
| 内部リンク／サイトマップの静的検索 | 上表のとおり |
| 修正後のA〜E HTTP検証 | 未実施。接続設定なし、追加指示によりDB実行検証を行わない |

追加指示より前の読み取り確認（修正後の検証ではない）:

- 未認証テストURLは401 Basic認証。認証を迂回していない。
- 旧本番の `https://www.morizaru.net/indexindex/work_graph.php?year_from=2009&year_to=2009&work_id=28594&page=9999` はcurl GETで301（www除去）→301（page除去）→200、2回転送。最終URLは同じ条件のpageなし。応答HTMLのnoindex,followと自己canonicalを確認、Warning/Fatal error/Noticeの文字列は検出なし。ただしサーバーログは未確認。
- その後の「本番へ接続しない」指示に従い追加通信は停止。取得物のCookie等は記録・コミットせず、一時ファイルも除去。

## 7. テスト配置後のURL・期待値（ユーザー実施用）

ベース `T = https://morizaru.net/indexindex_test/work_graph.php`。
canonicalベース `P = https://morizaru.net/indexindex/work_graph.php`。
N=テストDBで掲載がある連載作品ID、Y=その掲載年、X=テストDBに存在しない正整数ID。
N/Y/Xは管理画面・既存の掲載データで確認して置き換える。28594の2009年0件はテストDBでも確認する。
表のcanonicalはHTMLの `&amp;` を復号した値。301応答本体のrobots/canonicalは判定対象外で、最終200を確認する。

| ケース | URL（Tに付加） | 期待HTTP／Location | 最終noindex | 最終canonical |
| --- | --- | --- | --- | --- |
| A 正常 | `?year_from=Y&year_to=Y&work_id=N` | 200、転送なし | なし | P＋同じクエリ |
| B 指定年0件 | `?year_from=2009&year_to=2009&work_id=28594` | 200、転送なし、TOPへ送らない | noindex,follow | P＋同じクエリ |
| B2 例示URL | `?work_q=ガヴリールドロップアウト&year_from=2009&year_to=2009&work_id=28594&sort=year` | 日本語をUTF-8パーセント符号化した正規クエリなら200。表記差のみある場合301→200 | noindex,follow | P＋符号化した同じクエリ |
| C 不存在 | `?work_id=X` | 200、転送なし | noindex,follow | P＋`?work_id=X` |
| D 深いpage＋0件 | `?year_from=2009&year_to=2009&work_id=28594&page=9999` | 301→BのURL→200（1回） | noindex,follow | Bと同じ |
| D2 深いpage＋結果あり | `?sort=year&order=desc&page=9999` | 総ページ数未満でないことを確認、301→`work_graph.php?sort=year&order=desc`→200 | 一覧に結果があればなし | P＋`?sort=year&order=desc` |
| D3 不存在＋page | `?work_id=X&page=9999` | 301→C→200 | noindex,follow | Cと同じ |
| E www正規クエリ | ホストのみwww付きのB | 既存サーバー設定で301→非wwwのB→200（1回） | noindex,follow | Bと同じ |
| E2 www＋深いpage | ホストのみwww付きのD | 既存のwww除去→page除去→200（通常2回、既存より増えないこと） | noindex,follow | Bと同じ |
| F 不要／不正page | Bに `&page=1`、`&page=0`、`&page=-1`、`&page=abc`、`&page[]=2`、`&page=999999999999999999999999` を個別追加 | 301→B→200 | noindex,follow | Bと同じ |
| G 既定／不正sort等 | Bに `&sort=title&order=asc`、`&sort=invalid&order=invalid`、`&sort[]=year` を個別追加 | 301→B→200 | noindex,follow | Bと同じ |
| H 順序／重複 | `?work_id=28594&year_to=2009&year_from=2009&work_id=28594` | 301→B→200 | noindex,follow | Bと同じ |
| I 有効page | `?page=2`（一覧が2ページ以上あること） | 200、転送なし | なし | P＋`?page=2` |
| J 正常グラフ＋一覧0件 | `?year=Z&year_from=Y&year_to=Y&work_id=N`（Zは一覧掲載0件の年、N/Yは掲載あり） | 200、転送なし | **なし** | P＋同じクエリ |
| K 空検索一覧 | `?work_q=実際に一致しない検索語`（UTF-8符号化） | 正規クエリで200 | noindex,follow | P＋同じ正規クエリ |
| L 上限超過ID | `?work_id=999999999999999999999999` | 無効パラメータ除去で301→T→200 | TOP一覧結果に従う | P |

Lは「存在しない有効な整数ID」のCとは異なる、不正値の正規化。巨大値を別IDに丸めない。
すべて最終URLをもう一度取得して200、Locationなし、転送ループなしを確認する。
wwwの転送順は配備設定に依存する。テストでも同じ設定か確認する。PHP変更だけでサーバー設定の合格とはしない。

## 8. curl手順（PowerShell、認証情報はローカル入力のみ）

修正PHP 2ファイルをテストに配置し、上表のURLごとに実行する。パスワードをコマンド／ファイルに書かず、curlの対話入力を使う。HEADだけでなくGET本文を取得する。

```powershell
$testBase = 'https://morizaru.net/indexindex_test/work_graph.php'
$basicUser = Read-Host 'Basic認証ユーザー名'
$testUrl = $testBase + '?year_from=2009&year_to=2009&work_id=28594&page=9999'

# 初回ステータスとLocation（追跡しない）
curl.exe --globoff --user "$basicUser" --silent --show-error --max-time 60 --dump-header initial.headers --output initial.html "$testUrl"

# 全転送と最終HTML（同一ホストのURLに使用）
curl.exe --globoff --user "$basicUser" --silent --show-error --location --max-redirs 5 --max-time 60 --dump-header chain.headers --output result.html --write-out 'HTTP=%{http_code} redirects=%{num_redirects} URL=%{url_effective}' "$testUrl"
Select-String -Path chain.headers -Pattern '^HTTP/','^Location:'
Select-String -Path result.html -Pattern 'name="robots"','rel="canonical"','Warning','Notice','Fatal error','SQLSTATE'
```

- `--globoff` は `page[]` 等の検証に必要。
- Cookie付きヘッダーや認証情報を公開／Gitへ追加しない。
- 日本語URLは `[uri]::EscapeDataString('ガヴリールドロップアウト')` をwork_qの値として組み立てる。URL全体をEscapeDataStringしない。
- wwwケースはまずwww URLを追跡なしで取得し、Locationが非wwwの同じテストパスであることを確認する。そのLocationを別のcurlコマンドで認証して取得する。curlは別ホストへ認証を自動転送しないため、単純な-Lでの401をPHPの不具合と混同しない。認証転送を強制するオプションは不要。
- 401はBasic認証の確認失敗なのでA〜Eの合格に数えない。
- HTMLにエラーがないだけではPHP警告・DBエラーなしと断定できない。同じ時刻のWeb/PHPエラーログにwarning、例外、SQLSTATEがないことも確認する。
- sitemap.phpを取得し、列挙されたsitemap_urls.phpの各type/pageのXMLも確認する。canonicalと同様、既存XMLは本番ベースを使うため、検証アクセスは明示的にテスト側パスへ置き換える。work_graph.phpを含むlocがないことを確認する。

## 9. ブラウザ確認手順

1. Basic認証済みテスト環境で開発者ツールのNetworkを開き、Preserve logを有効にしキャッシュを無効にする。A〜Lのステータス／Location／最終URLを確認。
2. ページソースでrobotsとcanonicalを確認。B/Cで0件画面、作品・年・人物・出版社・雑誌条件が保持されること、TOPに移動しないことを確認。
3. Bの表示年を掲載がある年へ直し、グラフが復帰することを確認。作品名変更で古いhidden work_idが送信されないことも確認。
4. Aでグラフ表示を確認し、一覧の別年代の作品を選択。移動先の年範囲が選んだ作品の掲載年になり、元作品の期間が流用されないことを確認。
5. 正常一覧でsort/order、次／前／最後、有効page=2、ページジャンプを操作。0件グラフでは下部一覧・ページネーションが非表示であることを確認。
6. 連載作品のwork.phpから年リンクで正しい作品・雑誌・年へ移動。非連載作品では連載順グラフリンクだけが消え、掲載一覧が従来どおり表示されることを確認。
7. エラーログと結果表を保存し、A〜Eと追加ケース合格後に本番反映を判断する。

## 10. 未確認・残る懸念

- 修正後の実DBでのSQL実行、HTTP 200/301、robots/canonical実出力、警告・例外・DBエラーの不発生、負荷・タイムアウトは未確認。
- 例示作品ID・年の関係、不存在ID、正常年、複数ページのデータはテストDBで確認が必要。DB構造は推測して変更していない。
- Webサーバーのwww／HTTPS転送設定とBasic認証の適用順はリポジトリ外。余分な転送がないことは配置後確認が必要。
- 任意条件の検索は0件を生成し得る。意図した200＋noindex処理であり、全面禁止していない。
- バックアップPHPの公開有無は不明。現行work_graph.phpへの修正はコピーには適用していない。
- ここまでを実装・静的検証済みとして引き渡す。テスト環境検証済み／本番反映済みとは報告しない。
