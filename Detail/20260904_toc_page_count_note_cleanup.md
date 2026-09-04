# 2026-09-04 目次AI抽出 掲載ページ数なし備考除外

## 対象

- `indexindex/admin/toc_extract_tool_ai.php`

## 指摘内容

- AI抽出後の備考に `掲載ページ数記載なし` が入力されるが、登録用備考としては不要。

## 修正内容

- `remove_unneeded_note_tokens()` で以下の備考トークンを除外するようにした。
  - `掲載ページ数記載なし`
  - `ページ数記載なし`
  - `ページ数不明`
- AI が `page_count` に `記載なし`、`不明`、`なし` を返した場合は空文字へ正規化するようにした。
- AIプロンプトを修正し、掲載ページ数が明記されている場合だけ `note` に残し、見つからない場合は `page_count` と `note` を空文字にするよう明記した。

## DB変更

なし。

## 確認結果

- `C:\xampp\php\php.exe -l indexindex\admin\toc_extract_tool_ai.php`: No syntax errors detected
- `git diff --check -- indexindex/admin/toc_extract_tool_ai.php`: エラーなし

## 未確認事項

- OpenAI APIの実再解析結果は未確認。
- 再解析後、備考に `掲載ページ数記載なし` が残らないことをブラウザ上で確認する必要あり。
