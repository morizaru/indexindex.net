# 公開作品ページ上部への作品人物表示とタイトル行の調整

## 目的・表示仕様

公開作品ページ `work.php` の上部を次の配置に変更した。

```text
ギフテッド　区分：連載　話数形式：未設定
人物：雨宮理真（まんが）／天樹征丸（原作）
```

作品タイトルの右側に区分・話数形式を横並びで配置し、その下に作品としての標準人物を表示する。人物行は「人物：」・役割・区切り文字を含めて全体を太字にする。人物が0件ならラベルを含めて人物行を表示しない。

既存の副題は人物行の後に表示する。話数形式に付随する数字形式の表示も維持する。

## 調査・変更ファイル

- 調査：`AGENTS.md`、`work.php`、`creator.php`、`app/work_creators.php`、`assets/css/common.css`。
- 変更：`work.php`。
- 追加：本報告書 `Detail/20260918_public_work_creators.md`。
- 共通CSS、人物ページ、管理画面の人物保存処理は変更していない。

## 作品人物の取得SQL

既存の作品取得・404判定の直後に、次のプリペアドステートメントを追加した。`:work_id` には既存の検証済み作品IDを渡す。

```sql
SELECT wc.creator_id, wc.role, wc.display_order, c.name AS creator_name
FROM work_creators wc
JOIN creators c ON c.id = wc.creator_id
WHERE wc.work_id = :work_id
ORDER BY wc.display_order ASC, wc.id ASC
```

`fetchAll()` で人物を一括取得する。人物数にかかわらず追加クエリは1回で、人物ごとの問い合わせは発生しない。既存の作品取得SQLや掲載一覧SQLは変更していない。

並び順は `work_creators.display_order` 昇順、同値なら `work_creators.id` 昇順。名前はJOIN先の `creators.name` を使用する。`default_visible` による絞り込みは追加していない。

## 人物名・役割・リンク

- 人物名のみを `creator.php?creator_id=...` へのリンクにする。IDは整数へキャストし、既存の `h()` を通して出力する。
- 名前と役割も `h()`（`htmlspecialchars`、`ENT_QUOTES`、UTF-8）でエスケープする。
- `work_creators.role` は正規化・置換・トリムせず使用する。
- `role` が厳密に `author` の場合は人物名だけを表示し、`（author）` を付けない。
- 空の役割にも括弧を付けない。それ以外はリンク外に全角括弧で `（役割）` を表示する。
- 複数人物は全角の `／` で区切る。
- 取得結果が空なら人物行自体を出力しない。

## レイアウト・CSS

`work.php` 内のCSSに以下を追加した。

- `.work-title-row`：`display: flex`、`flex-wrap: wrap`、`align-items: baseline`。行間8px・横方向24pxのgapと下余白20pxを設定する。
- タイトル行内の `h1` と `.meta`：下余白を0にして、行のコンテナで余白を管理する。
- `.work-title-meta`：Flexboxと折り返しを使い、区分・話数形式の間隔を1emにする。
- `.work-creators`：`font-weight: bold` を設定する。

タイトルのフォント・サイズは変更していない。共通CSSのタイトル書体もそのまま使用する。メタ情報・人物行は既存の `.meta` の文字色 `#666` と行高1.7を引き継ぎ、フォントサイズを上書きしない。幅が足りない場合はタイトル行・メタ情報が自然に折り返す。新しい見出しやカードは追加していない。

## 既存機能・DBへの影響

上部は `work_creators`、下部の掲載人物は `content_creators` という役割分担を維持する。下部の人物取得SQL・表示関数・掲載雑誌と号の一覧は変更していない。

掲載件数、掲載雑誌数、連載順グラフ、既存検索・絞り込み、`creator.php` のURL仕様、canonical、noindex判定、404処理も変更していない。

DB構造変更・マイグレーション・既存データの一括更新はない。今回追加したDB処理はSELECTのみ。

## 検証結果・未確認事項

- `php -l indexindex/work.php`：成功（`No syntax errors detected`）。
- `git diff --check`：成功。
- 差分を確認し、実装変更が作品人物の取得・上部HTML・ページ内CSSに限定されていることを確認した。

実ブラウザでの表示確認は未実施。実DBを使用した人物の取得・並び順・authorの省略・人物0件の非表示、人物リンクの遷移、PCとスマホ幅の表示・折り返し・余白は未確認。副題や長いタイトルがある場合の実描画も未確認。

`indexindex_test` へのアップロードおよび本番反映は未実施。
