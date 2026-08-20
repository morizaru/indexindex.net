# work_graph.php の Search Console 5xx 対策とURL正規化

## 概要

Google Search Console で `work_graph.php` の多数の検索条件付きURLが「サーバーエラー（5xx）」として検出されていたため、ページ番号付きの深いURL、0件検索URL、不正な `page` 値、重複URLを整理した。

対象例:

- `work_graph.php?year_from=1983&year_to=1984&work_id=13276&page=96`
- `work_graph.php?year_from=2003&year_to=2004&work_id=5815&page=121`
- `work_graph.php?year_from=1969&year_to=1969&work_id=25181&sort=year&order=desc&page=11`

今回の方針は、検索結果0件のURLを404や別ページへの一律301にはせず、HTTP 200 のまま `noindex,follow` を付けること。深すぎるページ番号や `page=1` などの重複だけを正規URLへ 301 する。

## 対象ファイル

変更:

- `indexindex/work_graph.php`

確認のみ:

- `indexindex/sitemap.php`
- `indexindex/sitemap_urls.php`
- `robots.txt`

DB定義、DB接続情報、パスワード、robots.txt は変更していない。

## 変更内容

### 1. GETパラメータの安全な取得

`work_graph.php` に以下のヘルパーを追加した。

- `ii_get_string_param()`
- `ii_get_positive_int_param()`
- `ii_get_page_param()`

これにより、以下のような値をSQLやページング処理へ直接渡さないようにした。

- `page=abc`
- `page=0`
- `page=-1`
- `page[]=1`
- 空文字や配列値

`publisher_id`、`magazine_id`、`year`、`year_from`、`year_to`、`work_id` は正の整数のみ採用する。

### 2. URL生成ルールの統一

既存の `build_work_graph_url()` を正規URL生成の中心として使い、追加で絶対URL用の `build_absolute_work_graph_url()` を追加した。

このURL生成では、以下はURLから省略される。

- `page=1`
- `sort=title`
- `order=asc`
- 空の検索文字列
- 0以下のIDや年

これにより、ページネーションリンク、ソートリンク、canonical、301リダイレクト先のルールを揃えた。

### 3. `page=1` と不正な `page` の301

次のようなURLは正規URLへ 301 する。

- `work_graph.php?...&page=1`
- `work_graph.php?...&page=abc`
- `work_graph.php?...&page=0`
- `work_graph.php?...&page=-1`
- `work_graph.php?...&page[]=1`

基本的には `page` を外したURLへ転送する。

### 4. 総ページ数を超えた `page` の301

COUNT SQL 実行後、`$total_pages` を計算し、指定された `$page` が総ページ数を超えている場合は最終ページへ 301 する。

例:

```text
total_pages = 3
page = 96
```

この場合は同じ検索条件を保ったまま `page=3` へ転送する。

一覧が0件の場合は総ページ数を実質1として扱い、`page` 付きURLは `page` なしURLへ寄せる。

### 5. 作品指定グラフ0件時の `page` 付きURL整理

`work_graph.php` では、一覧の `$total_count` が0ではなくても、作品指定グラフ側の `$rows` が0件になることがある。

Search Console に出ていた「データ件数0件」の多くはこのケースに該当する可能性があるため、以下の条件を追加した。

```php
if ($has_graph && empty($rows) && $has_page_param) {
    redirect_work_graph_url(build_work_graph_url(1, ...));
}
```

これにより、作品指定グラフが0件で `page=96` などが付いているURLは、同じ検索条件の `page` なしURLへ 301 される。

転送先では HTTP 200 のまま0件結果として表示し、`noindex,follow` を付ける。

### 6. 0件URLへの noindex

以下のどちらかに該当する場合、HTML head に robots meta を出すようにした。

- 作品一覧の `$total_count === 0`
- 作品指定グラフで `$rows` が空

出力内容:

```html
<meta name="robots" content="noindex,follow">
```

0件検索結果はHTTP 200のまま維持する。

### 7. canonical の追加

`work_graph.php` の head に canonical を追加した。

```html
<link rel="canonical" href="https://morizaru.net/indexindex/work_graph.php?...">
```

canonical は正規化済みの現在URLを指す。

- `page=1` は含めない
- `page=2` 以降はそのページ自身を指す
- 0件URLも、トップページや別ページではなく、その0件検索条件の正規化URL自身を指す
- 無関係な `index.php`、`works.php`、作品詳細ページなどには向けない

### 8. 0件時の不要SQL削減

COUNT の結果 `$total_count === 0` の場合は、作品一覧取得SQLを実行せず、`$works = []` のまま表示へ進むようにした。

これにより、0件URLへのクロール時に不要な `GROUP BY` / `ORDER BY` / `LIMIT OFFSET` の一覧SQLを避ける。

グラフ側は `work_id` 指定時の表示に必要なため、既存の表示仕様を壊さない範囲で維持している。

## sitemap確認

`sitemap.php` と `sitemap_urls.php` を確認した。

サイトマップに含まれているのは以下の種類。

- static
- magazines
- issues
- works
- creators

検索条件付きの `work_graph.php?...` は含まれていない。

また、固定ページ一覧にも `work_graph.php` 自体は含まれていなかったため、sitemap側の変更は不要と判断した。

## robots.txt確認

今回 `robots.txt` は変更していない。

理由:

- 0件URLには Google に `noindex,follow` を読ませる必要がある
- `work_graph.php` 全体をDisallowすると、正常なグラフページまでクロールできなくなる
- 個別の検索結果品質制御は robots.txt より meta robots の方が適切

## Search Console参考URLの扱い

修正後の想定:

### `work_id=13276&page=96`

```text
/indexindex/work_graph.php?year_from=1983&year_to=1984&work_id=13276&page=96
```

作品指定グラフが0件であれば、以下へ301。

```text
/indexindex/work_graph.php?year_from=1983&year_to=1984&work_id=13276
```

転送先は HTTP 200 + `noindex,follow`。

### `work_id=5815&page=121`

```text
/indexindex/work_graph.php?year_from=2003&year_to=2004&work_id=5815&page=121
```

作品指定グラフが0件であれば、以下へ301。

```text
/indexindex/work_graph.php?year_from=2003&year_to=2004&work_id=5815
```

転送先は HTTP 200 + `noindex,follow`。

### 総ページ数超過のみの場合

一覧結果が存在し、`page` が `$total_pages` を超える場合は最終ページへ301。

```text
page=96, total_pages=3
```

なら `page=3` へ転送する。

## 確認結果

実行できた確認:

- `git diff --check -- indexindex/work_graph.php`
- `rg` による sitemap 内 `work_graph.php` 混入確認
- `git diff` による差分確認

結果:

```text
git diff --check: 問題なし
```

sitemap確認:

```text
indexindex/sitemap.php
indexindex/sitemap_urls.php
```

上記に検索条件付き `work_graph.php?...` は含まれていない。

## 実行できなかった確認

### PHP lint

ローカル環境で `php` コマンドが PATH に存在しなかったため、以下は実行できなかった。

```powershell
php -l indexindex\work_graph.php
```

結果:

```text
php : 用語 'php' は、コマンドレット、関数、スクリプト ファイル、または操作可能なプログラムの名前として認識されません。
```

Windows環境で PHP が XAMPP に入っている場合は、次のように確認する。

```powershell
C:\xampp\php\php.exe -l indexindex\work_graph.php
```

### curl確認

公開URLのヘッダー確認として以下を試した。

```powershell
curl.exe -I "https://morizaru.net/indexindex/work_graph.php?year_from=1983&year_to=1984&work_id=13276&page=96"
curl.exe -I "https://morizaru.net/indexindex/work_graph.php?year_from=2003&year_to=2004&work_id=5815&page=121"
```

ただし、このローカル環境の Windows Schannel 側で以下のエラーになった。

```text
curl: (35) schannel: AcquireCredentialsHandle failed: SEC_E_NO_CREDENTIALS (0x8009030e)
```

`curl.exe -k -I` でも同じエラーだったため、ローカルからのヘッダー確認は未完了。

## 公開検索結果で見えた現状

Web検索結果上では、現行公開版に次のような0件グラフURLが見えていた。

```text
https://www.morizaru.net/indexindex/work_graph.php?order=desc&page=4&work_id=4380&year_from=1960&year_to=1961
```

ページ内容では `データ件数：0件` が表示されていた。

このため、0件グラフURLに `noindex,follow` を付け、`page` 付き0件URLを `page` なしへ正規化する今回の対応は、Search Console に出ている問題の方向性と一致している。

## 残っている確認事項

本番またはPHP実行可能な環境で以下を確認する。

- `php -l indexindex/work_graph.php`
- `page=1` が `page` なしへ301されること
- `page=abc` / `page=0` / `page=-1` / `page[]=1` がエラーにならず正規URLへ301されること
- 総ページ数超過の `page` が最終ページへ301されること
- 作品指定グラフ0件かつ `page` 付きURLが `page` なしへ301されること
- 転送先の0件URLで `<meta name="robots" content="noindex,follow">` が出ること
- 正常なグラフ表示ページには `noindex` が出ないこと
- canonical が現在の正規URL自身を指すこと
- 既存の作品検索、人物検索、出版社絞り込み、雑誌絞り込み、年指定、年範囲指定、sort/order、ページネーションが壊れていないこと

## DBインデックス候補

今回、DBインデックス追加は実施していない。

理由:

- まずは無効URLの正規化と0件URLの noindex が主目的
- 大規模なSQLチューニングやDB構造変更は今回の範囲外
- 5xx の主因が深い `page` や0件URLの大量クロールであれば、先にURL正規化で負荷を下げるのが低リスク

今後、実サーバーで依然として負荷が高い場合は、以下のWHERE/JOINに関わるカラムのインデックス状況を確認する。

- `works.publication_type`
- `issue_contents.work_id`
- `issue_contents.issue_id`
- `magazine_issues.magazine_id`
- `magazine_issues.issue_year`
- `magazines.publisher_id`
- `content_creators.content_id`
- `content_creators.creator_id`

ALTER TABLE は未実施。

## 補足

今回の変更は `work_graph.php` のURL正規化、noindex、canonical、0件時の不要SQL削減に限定した。

表示デザイン、DB接続、DB構造、sitemap、robots.txt、AdSense、共通header/footer、access_logger の仕様変更は行っていない。
