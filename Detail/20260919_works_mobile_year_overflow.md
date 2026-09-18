# 作品一覧のスマホ表示で発行年selectがはみ出す問題の修正

## 症状・対象

公開 `works.php` の発行年フォームで「指定なし」のselectが右にはみ出し、ページ全体が縮小して見えるという報告への対応。

調査：`works.php` の検索フォームHTML・インラインCSS・既存レスポンシブ指定、`assets/css/common.css`、`AGENTS.md`。

変更：`works.php` と本報告書のみ。共通CSS、他ページ、既存の性能改善処理は変更していない。

## 原因

PC用の `.filter-grid .year-row` は `flex: 0 0 300px` と `white-space: nowrap` を持つ。発行年のlabel、テキストinput、selectは同じ行の直下にある。

980px以下では既存CSSにより `.filter-grid` と `.form-row` が `display: block` となり、input/selectはそれぞれ `width: 100%` になる。一方、発行年行の `white-space: nowrap` は解除されていなかった。inputとselectはブロック要素に変更されていないため、幅100%の2つのコントロールが同じ行に残り、selectが右へはみ出していた。

スマホ時は親がblockなので、PC用flex-basis 300pxやflexのgapが直接の原因ではない。コントロールのbox-sizingは既にborder-box。selectのmin-width 90pxも、検証幅での親幅より小さい。共通CSSには発行年フォームへの指定はなく、ページ内のnowrapとモバイル幅100%指定の組合せが原因だった。

## 修正

既存の `@media (max-width: 980px)` 内だけに以下を追加した。

```css
.filter-grid .year-row {
    white-space: normal;
}
```

これによりスマホではlabelの下にinput、その次の行にselectが親幅内で表示される。既存のwidth・余白・文字サイズ・border-boxを使用し、PC用の1行レイアウトは変更しない。

ページ全体やフォームに `overflow-x: hidden` を追加していない。HTML構造、入力名、検索仕様、SQL、ページング、人物表示、DB、URL・SEOは変更なし。

## 検証

- `php -l indexindex/works.php` 成功。
- `git diff --check` 成功。
- 前回の独立SQLite固定データ用PHPハーネスを使い、変更前後の実ページHTMLを生成。DB接続設定・アクセスログをテスト側で除外し、外部スクリプトを除いたオフラインHTMLで描画比較した。共通CSS・ロゴはローカルファイルを参照。
- ヘッドレスChromeのiframe内で320/390/768/980/981/1280/1600px幅を比較。DOMの矩形とdocumentElement.scrollWidthを取得し、親幅・画面幅からのはみ出しを確認した。

| 検証幅 | 修正前scrollWidth | 修正後scrollWidth | 結果 |
| ---: | ---: | ---: | --- |
| 320px | 503px | 305px | selectの親外はみ出し・ページ横はみ出し解消 |
| 390px | 643px | 375px | 同上 |
| 768px | 1399px | 753px | 同上 |
| 980px | 1823px | 965px | 同上 |
| 981px | 966px | 966px | 発行年行・input・selectの位置と寸法が一致 |
| 1280px | 1265px | 1265px | 同上 |
| 1600px | 1585px | 1585px | 同上 |

scrollWidthが検証幅より15px小さいケースは、検証iframeの縦スクロールバー領域によるもの。モバイル側の全4幅で修正前のはみ出しを再現し、修正後の全7幅ではページ横はみ出しなし。モバイル側ではselectの右端が発行年行の右端と一致した。

実装差分はモバイル用CSSの追加4行のみ。一時成果物は `/private/tmp/indexindex-works-mobile-before.php`、`/private/tmp/indexindex-works-mobile-layout.html`、`/private/tmp/indexindex-works-mobile-result.html`。

## 未確認事項

実スマホのSafari/Chrome、TypeSquareや広告等の外部通信を含む本番ページでの表示・自動縮小挙動は未確認。検証はCSS viewport幅を指定したローカルChromeでのレイアウト確認であり、本番端末のブラウザ設定まで再現したものではない。

indexindex_testへのアップロード・本番反映は未実施。
