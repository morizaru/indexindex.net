# チェックボックス状態保持機能 - クイックガイド

## 修正内容のサマリー

**問題:** チェックボックスをONで登録しても、登録完了後にログが表示されない  
**原因:** チェック状態がPOST送信時に失われていた  
**解決:** 隠しフィールドでチェック状態をPOSTデータとして送信

---

## ユーザー向け使用方法

### 通常の使用フロー

#### 1️⃣ ログを確認したい場合
```
1. ページロード
2. 「詳細ログを表示」をチェック ☑
3. フォーム入力
4. 登録ボタンをクリック
   ↓
5. 登録完了画面でログが表示される ✅
```

#### 2️⃣ ログが不要な場合
```
1. ページロード
2. チェックボックスはOFFのまま ☐
3. フォーム入力
4. 登録ボタンをクリック
   ↓
5. 登録完了画面でログは表示されない（通常）
```

#### 3️⃣ ページをリロードした場合
```
1. チェックをONで登録
2. 登録完了画面でログが表示される
3. ページをリロード（F5）
4. チェック状態が保持されている ✅
5. ログが表示されたままになる ✅
```

---

## 動作の詳細

### ブラウザの開発者ツールで確認

#### Network タブでPOST データを確認
```
POST /indexindex/admin/content_bulk_add.php

Form Data:
  issue_id: 18794
  rows[0][page_start]: 100
  rows[0][title_main]: 作品名
  rows[0][creator_name]: 小林哲也、DJ SPACE
  ...
  debug_logs_enabled: 1  ← ★ ここが隠しフィールド
```

#### URL でリダイレクト先を確認
```
リダイレクト前:
  POST: /content_bulk_add.php?issue_id=18794

リダイレクト後:
  GET: /content_bulk_add.php?issue_id=18794&saved=1&inserted=23&debug_logs=1
                                                                  ↑ ★ パラメータ追加
```

---

## 技術的な仕組み

### 3層の動作

```
┌─ HTML層 ────────────────────────┐
│ <input type="hidden"             │
│   id="debugLogsEnabledField"    │
│   name="debug_logs_enabled"     │
│   value="0">                     │
└─────────────────────────────────┘
         ↑
         │ 同期
         ↓
┌─ JavaScript層 ───────────────────┐
│ toggleDebugLogs()                │
│   hiddenField.value =            │
│   checkbox.checked ? '1' : '0'  │
└──────────────────────────────────┘
         ↑
         │ 送信
         ↓
┌─ PHP層 ──────────────────────────┐
│ $debug_logs_enabled =            │
│ $_POST['debug_logs_enabled']     │
│                                  │
│ if ($debug_logs_enabled) {       │
│   URL に &debug_logs=1 を追加    │
│ }                                │
└──────────────────────────────────┘
```

---

## トラブルシューティング

### Q1: チェックをONで登録したのにログが表示されない
**A:** 
- ブラウザキャッシュをクリアしてみてください
- ブラウザの開発者ツール(F12)の Network タブで、POST データに `debug_logs_enabled=1` が含まれているか確認
- リダイレクト先URLに `&debug_logs=1` が付いているか確認

### Q2: チェック状態がページリロード後に保持されない
**A:**
- 一度登録してから確認してください（登録後のページで状態が保持される）
- 登録前のページでリロードした場合、チェック状態は URL の `debug_logs` パラメータに基づいて復元されます

### Q3: POSTデータに `debug_logs_enabled` が見当たらない
**A:**
- JavaScript が正常に動作していない可能性
- ブラウザのコンソール(F12)にエラーが出ていないか確認
- 別のタブで同じページを開き直してみてください

### Q4: チェック中にページ移動したらどうなるか
**A:**
- チェック状態は URL に `debug_logs=1` として保存されます
- 別のページに移動してから戻ってくると、チェック状態が保持されます

---

## 修正ポイント

### 追加されたコード

#### 1. 隠しフィールド（1896-1897行目）
```html
<input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="0">
```
- POST送信時にチェック状態を送信

#### 2. POST処理（761-762行目）
```php
$debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
```
- 受信したチェック状態を確認

#### 3. リダイレクト処理（1036-1042行目）
```php
if ($debug_logs_enabled) {
    $redirect_url .= '&debug_logs=1';
}
```
- チェック状態をURLに追加

#### 4. JavaScript 初期化（1394-1399行目）
```javascript
if (checkbox && hiddenField) {
    hiddenField.value = checkbox.checked ? '1' : '0';
}
```
- ページロード時に隠しフィールドを初期化

#### 5. JavaScript 同期（1790-1809行目）
```javascript
hiddenField.value = checkbox.checked ? '1' : '0';
```
- チェック変更時に隠しフィールドを同期

---

## 確認方法

### ブラウザの開発者ツール（F12）を使用

#### 1. Elements タブで隠しフィールドを確認
```
<input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="1">
      ↑ value が 0 または 1 で更新されている
```

#### 2. Console タブで手動確認
```javascript
// チェックボックスの状態を確認
document.getElementById('toggleDebugLogs').checked

// 隠しフィールドの値を確認
document.getElementById('debugLogsEnabledField').value
```

#### 3. Network タブでPOST送信を確認
```
登録ボタンをクリック
  ↓
Network タブが自動スクロール
  ↓
content_bulk_add.php の POST リクエストを確認
  ↓
Form Data セクション内に debug_logs_enabled: 1 があるか確認
```

#### 4. Application タブでセッションを確認
```
登録後
  ↓
Application → Cookies → PHPSESSID
  ↓
セッション内にデバッグ情報が保存されているか確認
```

---

## 修正ファイル情報

| 項目 | 値 |
|------|-----|
| **ファイル名** | content_bulk_add.php |
| **修正日** | 2026-08-31 |
| **修正行数** | 5箇所（計10行程度） |
| **影響範囲** | 一括登録時のデバッグログ表示機能 |

---

## 利用シーン

### シーン1: 登録処理のテスト・検証
```
開発者が登録処理をテストする場合
  ↓
チェックボックスをON
  ↓
テストデータを入力・登録
  ↓
ログ出力で分割処理を確認 ✅
```

### シーン2: ユーザーサポート
```
ユーザーが「どう登録されたか確認したい」
  ↓
チェックボックスをON
  ↓
登録
  ↓
ログでデータの分割方法を確認
  ↓
「スペースは区切りません」ことを確認できる ✅
```

### シーン3: 本番運用
```
通常の運用ではチェックなし（デフォルト）
  ↓
ログ表示なし
  ↓
ページ軽量、読み込み快適
  ↓
問題が発生したときのみチェックON
  ↓
詳細ログで原因を特定 ✅
```

---

## 修正履歴

| 日時 | 修正内容 |
|------|---------|
| 2026-08-31 | 初版作成 |
| | チェック状態がPOST後も保持されるように実装 |
| | 隠しフィールドによる状態送信機能を追加 |
