# 2026-08-15 詳細検索の複数キーワード対応

## 対象

- [indexindex/search.php](../search.php)

## 変更内容

詳細検索で、1つの検索文字列に複数キーワードを入れたときに、各キーワードのいずれかに一致する結果を表示するように修正した。

対応対象は次のとおり。

- 作品名検索
- 作者名検索
- 雑誌名検索
- 全体検索

## 仕様

以下の入力形式に対応した。

- `鳥山明 DRAGON`
- `鳥山明, DRAGON`
- `鳥山明　DRAGON`

内部では、スペース、半角カンマ、全角カンマ、区切り記号で文字列を分割し、それぞれを OR 条件として SQL に反映している。

## 実装方針

- 分割処理: `split_search_keywords()`
- 条件生成: `build_multi_keyword_clause()`
- 作品名・作者名・雑誌名で同方式の WHERE 条件を生成
- ひらがな・カタカナのゆれ対応は既存の `kana_like_values()` をキーワードごとに再利用

## 例

```php
$keyword_tokens = split_search_keywords($q);
```

```sql
WHERE (
    m.name LIKE :mag_keyword_0
    OR m.name_search LIKE :mag_keyword_0
)
OR (
    m.name LIKE :mag_keyword_1
    OR m.name_search LIKE :mag_keyword_1
)
```

## 確認内容

- 複数キーワードの入力文字列が分割されることを確認。
- 各キーワードに対して OR 条件で検索されるようにした。
- 画面の入力例も `鳥山明 DRAGON` 形式に更新した。

## 備考

今回の修正は検索ロジックの改善であり、DB スキーマ変更は行っていない。
