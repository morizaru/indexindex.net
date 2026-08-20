# work_graph.php の0件グラフ時UI改善

## 概要

前回の Search Console 5xx 対策後、`work_graph.php` の0件URLには `noindex,follow` が付き、深い `page` も正規化されるようになった。

一方で、次のようなURLでグラフデータが0件の場合でも、下部に全作品一覧とページネーションが表示されていた。

```text
work_graph.php?year_from=2009&year_to=2009&work_id=24432&sort=content_count
```

また、URL上では `work_id` や `year_from/year_to` が指定されているのに、検索フォーム側では作品名や表示年が未指定に見える状態だった。

今回、この2点を改善した。

## 変更ファイル

- `indexindex/work_graph.php`

DB構造、DB接続情報、`app/config.php`、`robots.txt`、`sitemap.php`、`sitemap_urls.php` は変更していない。

## 変更内容

### 1. 作品指定グラフ0件時は下部一覧を表示しない

次の条件を追加した。

```php
$suppress_work_list = ($has_graph && empty($rows));
```

`work_id` が指定されていて、対象作品のグラフデータ `$rows` が0件の場合は、下部の作品一覧を表示しない。

非表示にしたもの:

- 全作品件数表示
- ページネーション
- ページジャンプ
- ページ内絞り込み
- 作品一覧テーブル

これにより、0件グラフURLで「全16320作品中 1〜100件を表示」のような無関係な一覧が出ないようになった。

### 2. 0件グラフ時は一覧COUNT/一覧取得SQLを実行しない

以前は `$rows` が0件かどうかを判定する前に、下部一覧用のCOUNT SQLと一覧取得SQLが走っていた。

今回、処理順を変更し、グラフデータ取得後に `$suppress_work_list` を判定するようにした。

`$suppress_work_list === true` の場合は、下部一覧用の以下を実行しない。

- `COUNT(DISTINCT w.id)` 用SQL
- 作品一覧取得SQL
- 一覧用 `GROUP BY`
- 一覧用 `ORDER BY`
- 一覧用 `LIMIT/OFFSET`

ただし、画面上部の条件表示や対象作品名の取得、グラフ0件判定に必要なSQLは維持している。

### 3. `work_id` から作品名を検索フォームへ復元

URLに `work_id` がある場合、DBから取得した `$selected_work_title` を検索フォームの作品検索欄に表示するようにした。

追加した表示用変数:

```php
$work_input_value = $work_q;
if ($work_input_value === '' && $work_id > 0 && $selected_work_title !== '') {
    $work_input_value = $selected_work_title;
}
```

これにより、`work_id=24432` のようなURLでも、フォーム上で現在の対象作品名が見える。

### 4. hidden `work_id` をフォームに保持

`work_id` が指定されている場合、検索フォームに hidden input を追加した。

```php
<input type="hidden" name="work_id" id="work_id" value="<?= h($work_id) ?>">
<input type="hidden" id="selected_work_title" value="<?= h($selected_work_title) ?>">
```

フォーム送信時に対象作品IDが落ちないようにしている。

### 5. 作品名を書き換えた場合は古い `work_id` を消す

ユーザーが作品検索欄を書き換えた場合、古い `work_id` が残ると「表示名は別作品、IDは前の作品」という不整合が起きる。

そのため、JavaScriptで次の制御を追加した。

- 作品名欄が元の選択作品名と異なる場合、hidden `work_id` の `name` を外す
- 元の作品名に戻した場合、元の `work_id` を復元する

### 6. `year_from/year_to` をフォームへ反映

`work_id` 指定時に、メイン検索フォーム内へ表示年の From/To セレクトを追加した。

これにより、グラフデータ0件で下部の年範囲フォームが出ない場合でも、URLで指定されている表示年範囲がフォーム上で確認できる。

例:

```text
year_from=2009&year_to=2009
```

なら、From/To ともに2009が選択される。

### 7. noindex/canonical/301 は維持

前回追加した以下の制御は維持した。

- 0件URLの `<meta name="robots" content="noindex,follow">`
- canonical は正規化済みURL自身
- `page=1` の除去
- 不正な `page` の正規化
- 総ページ数超過時の最終ページへの301
- 作品指定グラフ0件かつ `page` 付きURLの `page` なしURLへの301

## 対象URLの修正後想定

対象:

```text
work_graph.php?year_from=2009&year_to=2009&work_id=24432&sort=content_count
```

期待:

- HTTP 200
- `noindex,follow` あり
- canonical は同URLの正規化済みURL
- 条件表示に対象作品名と表示年が出る
- 作品検索フォームに対象作品名が出る
- 表示年 From/To が2009になる
- データ件数は0件
- 下部の全作品一覧は表示しない
- ページネーションは表示しない
- 下部一覧用COUNT SQLと一覧取得SQLは実行しない

## 確認結果

実行できた確認:

```powershell
git diff --check -- indexindex\work_graph.php
```

結果:

```text
問題なし
```

確認した主な差分:

- `$suppress_work_list` の追加
- 一覧COUNT/一覧取得SQLの実行位置をグラフ0件判定後へ移動
- `work_id` hidden input の追加
- 作品検索欄の復元値 `$work_input_value` の追加
- `year_from/year_to` セレクトの追加
- 作品名欄変更時の `work_id` クリアJS追加

## 実行できなかった確認

ローカル環境で `php` コマンドが PATH に存在しなかったため、PHP lint は未実行。

```powershell
php -l indexindex\work_graph.php
```

結果:

```text
php : 用語 'php' は、コマンドレット、関数、スクリプト ファイル、または操作可能なプログラムの名前として認識されません。
```

XAMPP環境では以下で確認する。

```powershell
C:\xampp\php\php.exe -l indexindex\work_graph.php
```

## 残確認事項

本番またはPHP実行可能な環境で以下を確認する。

- 対象URLで下部作品一覧が表示されないこと
- 対象URLでページネーションが表示されないこと
- 対象URLで `noindex,follow` が維持されていること
- 対象URLで canonical が正規化済みURL自身を指すこと
- 作品検索フォームに対象作品名が表示されること
- 表示年 From/To がURL条件と一致すること
- 作品名欄を書き換えた時に古い `work_id` が送信されないこと
- `work_id` なしの通常検索では従来どおり下部作品一覧が表示されること
- `work_id` あり、かつグラフデータありの場合は従来どおりグラフと一覧が表示されること

## 補足

今回の変更は、0件グラフURLにおけるUIの不整合と不要な下部一覧処理の抑止に限定した。

前回のSearch Console 5xx対策で追加したSEO向け制御は壊していない。
