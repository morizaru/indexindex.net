# 2026-09-04 目次画像AI抽出から一括登録への連携実装

## 対象

- `indexindex/admin/toc_extract_tool_ai.php`
- `indexindex/admin/content_bulk_add.php`

## 調査した現行仕様

- `toc_extract_tool_ai.php` はテキスト貼り付け前提。通常抽出は先頭が「・」の行を既存PHPルールで分解し、AI抽出は既存の OpenAI Responses API 呼び出しでテキストを JSON Schema に沿って抽出していた。
- `OPENAI_API_KEY` は `OPENAI_API_KEY` 定数を優先し、なければ環境変数 `OPENAI_API_KEY` を見る。モデルも `OPENAI_MODEL` 定数、環境変数、既定値 `gpt-4.1-mini` の順。
- `content_bulk_add.php` は `$_SESSION['toc_extract_rows']` と `$_SESSION['toc_extract_issue_id']` を受け、`content_bulk_add.php?issue_id=...&from_toc_extract=1` でフォームへ反映していた。
- `$_SESSION['toc_extract_rows']` は作品単位の配列で、`work_title`、`creator_text`、`creators`、`episode_no`、`episode_title`、`note`、各種フラグを保持していた。
- `$_SESSION['toc_extract_issue_id']` は抽出元の雑誌号IDで、遷移先の `issue_id` と一致する場合のみ反映していた。
- `content_bulk_add.php` は `creators_json` があれば構造化作者を優先し、なければ `creator_name` を `split_creator_names()` / 役割解析で分解していた。
- 登録処理は既にトランザクション、PDOプリペアドステートメント、雑誌号存在確認、ロールバック、入力保持を備えていた。
- 既存画像保存は `admin/source_image_add.php` で `uploads/source_images/` に保存し、`source_images(issue_id, image_path, note)` に追加していた。同じ雑誌号の画像は削除せず追加登録。
- 既存 admin 画面に共通 CSRF 実装や共通権限確認ファイルは見つからなかった。
- 実DB `DESCRIBE` は外部MySQL接続がタイムアウトしたため取得不可。DB変更は行わず、既存コードが INSERT/JOIN しているカラムのみ使用した。

## 実装内容

- `toc_extract_tool_ai.php` に雑誌号選択、目次画像アップロード、保存済み画像プレビュー、AI画像解析を追加。
- アップロードは JPG/JPEG/PNG/WEBP、5MB以下、`finfo(FILEINFO_MIME_TYPE)` によるMIME検証、ランダムファイル名、保存先実パス検証を実装。
- 保存先は既存仕様と同じ `uploads/source_images/`。保存後は `source_images` へ追加登録し、既存画像は削除しない。
- 画像解析は既存の Responses API と既存モデル取得方式を利用し、`input_image` に data URL を渡す方式にした。
- AI応答は JSON Schema で `display_order`、`start_page`、`work_title`、`creators`、`episode_no`、`episode_title`、`note`、`content_type`、`publication_type`、各種フラグ、`color_type`、`page_count`、`confidence`、`raw` を要求。
- `color_type` や `page_count` は専用カラム追加をせず、既存仕様に合わせて `note` へ追記。
- 雑誌別抽出設定用に `toc_extract_magazine_settings()` を追加。現時点では共通設定と、実ID未確定のマーガレット形式テンプレートを保持。
- 抽出結果確認画面に、掲載順、開始ページ、作品名、作者名、作者役割、話数、サブタイトル、備考、連載/読切、通常/出張、新連載、最終回、登録対象、信頼度、元表記を編集できる一覧を追加。
- 行追加、行削除、表示順による並べ替え、複数作者の追加/削除を JavaScript で追加。
- `confidence=medium` は黄色、`low` は赤系の控えめな警告表示にした。
- 確認済みデータは `$_SESSION['toc_extract_rows']` / `$_SESSION['toc_extract_issue_id']` に保存し、既存URLへリダイレクトする。
- `creators` 配列は `creator_text` へ潰さずセッションから一括登録フォームまで保持する。
- `content_bulk_add.php` は抽出データの `page_start`、`content_type`、`publication_type` を受け取るよう修正。
- 構造化 `creators` が存在する場合はそれを優先し、古い `creator_text` は従来どおりフォールバックにした。
- `content_bulk_add.php` にローカル CSRF トークンとワンタイム送信トークンを追加。既存の localStorage 入力復元がトークンを上書きしないよう除外した。
- 一括登録画面に「目次画像からAI抽出」リンクを追加。雑誌号未選択時は先に選択する案内を表示。

## AIへの主な抽出条件

- JSON以外の説明文を返さない。
- 判読できない項目は空文字にする。
- 不確実な項目は `medium` または `low`。
- 漫画作品と作者を対応付け、複数作者を配列で保持する。
- 役割表記がない作者は「作者」。
- 新連載、最終回、読切、出張掲載を識別する。
- 巻頭カラー、巻中カラー、センターカラー、掲載ページ数は備考にも残す。
- 広告、投稿募集、お知らせ、インタビューなどは漫画作品として登録しない。
- ページ番号と掲載ページ数を混同しない。
- 縦書き・横書き・複数段組の自然な読み順を判断する。

## データ構造

```php
$_SESSION['toc_extract_rows'] = [
    [
        'display_order' => 1,
        'page_start' => '3',
        'work_title' => '作品名',
        'creator_text' => '作者名 原作：原作者名 作画：作画者名',
        'creators' => [
            ['name' => '作者名', 'role' => '作者'],
            ['name' => '原作者名', 'role' => '原作'],
            ['name' => '作画者名', 'role' => '作画'],
        ],
        'episode_no' => '',
        'episode_title' => '',
        'note' => '巻頭カラー / 50P',
        'content_type' => 'manga',
        'publication_type' => '通常掲載',
        'is_one_shot' => 0,
        'is_guest_publication' => 0,
        'is_new_series' => 0,
        'is_final_episode' => 0,
        'raw' => '判断根拠の元表記',
    ],
];
$_SESSION['toc_extract_issue_id'] = 123;
```

## DB変更

なし。

## 確認結果

- `C:\xampp\php\php.exe -l indexindex\admin\toc_extract_tool_ai.php`: No syntax errors detected
- `C:\xampp\php\php.exe -l indexindex\admin\content_bulk_add.php`: No syntax errors detected
- `git diff --check -- indexindex/admin/toc_extract_tool_ai.php indexindex/admin/content_bulk_add.php`: エラーなし

## 未確認事項

- 外部MySQLへの接続がタイムアウトしたため、ライブDBの `DESCRIBE` は未確認。
- OpenAI API 実通信と実画像抽出は、ローカルから本番キー/APIへ実リクエストを投げない方針で未実施。
- 実ブラウザでのアップロード、プレビュー、AI再解析、登録完了までは未実施。

## 本番反映前の確認手順

1. `indexindex_test` へ反映し、雑誌号を選択して目次画像をアップロードする。
2. JPG/JPEG/PNG/WEBP が保存され、5MB超過や非対応MIMEが日本語エラーになることを確認する。
3. AI画像抽出で複数作品、縦書き、2段組が抽出されることを確認する。
4. 開始ページ、タイトル、作者名、作者役割、備考、読切、出張掲載、新連載、最終回を修正して一括登録へ渡す。
5. 複数作者の `creators` 配列が `content_bulk_add.php` の hidden `creators_json` まで保持されることを確認する。
6. 低信頼度行の表示、不要記事の登録対象OFF、行追加/削除/並べ替えを確認する。
7. 一括登録で `issue_contents` と `content_creators` に登録され、作者ごとの role が保持されることを確認する。
8. 登録エラー時に入力内容が消えないこと、二重送信が拒否されることを確認する。
9. 通常の手入力一括登録と従来のテキスト抽出経由が従来どおり動くことを確認する。
