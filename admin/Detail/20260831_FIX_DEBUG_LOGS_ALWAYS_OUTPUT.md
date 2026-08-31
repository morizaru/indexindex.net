# 修正レポート：デバッグログの常時出力とデフォルト隠す方式への切り替え

## 修正日時
2026年8月31日

## 修正内容
ログが登録後に表示されない問題を解決するため、**デバッグログを常時出力し、デフォルトは隠す**方式に変更しました。

## 問題の背景

### 前の実装の問題点
- チェックボックスをONで登録しても、登録後にリダイレクトされるとログが表示されないことがあった
- URLパラメータに依存していたため、状態保持が不確実だった

### 根本原因
1. URLパラメータで表示/非表示を制御していた
2. セッション データの生成と表示判定がURLパラメータに依存していた
3. ネットワークの遅延やリダイレクト処理でパラメータが失われる可能性があった

## 修正内容の詳細

### 修正方針
✅ **常時出力** → デバッグログデータを常にセッションに保存・出力  
✅ **デフォルト隠す** → 初期表示は隠す（`display: none`）  
✅ **チェックで表示** → チェックボックスでON/OFF制御  

### 修正箇所

#### 1. 初期表示フラグの取得（670行目付近）
```php
// 修正前
$show_debug_logs = isset($_GET['debug_logs']) && $_GET['debug_logs'] === '1';
if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
    $debug_info = $_SESSION['debug_splits'];
    unset($_SESSION['debug_splits']);
} elseif (!empty($_SESSION['debug_splits'])) {
    unset($_SESSION['debug_splits']);
}

// 修正後
if (!empty($_SESSION['debug_splits'])) {
    $debug_info = $_SESSION['debug_splits'];
    // デバッグログ表示フラグを確認（POST時のチェック状態をセッションから取得）
    $show_debug_logs = isset($_SESSION['show_debug_logs']) && $_SESSION['show_debug_logs'] === true;
    unset($_SESSION['debug_splits']);  // 表示後は削除
    unset($_SESSION['show_debug_logs']);
}
```

**変更点：**
- URLパラメータ（`$_GET['debug_logs']`）ではなく、**セッション**から表示フラグを取得
- `$_SESSION['show_debug_logs']` = ユーザーが登録時にチェックボックスをONにしたかどうか

#### 2. POST時のチェック状態をセッションに保存（761-763行目付近）
```php
// 修正前
$debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';

// 修正後
// POST時のデバッグログ表示状態を確認（チェックボックス状態）
$debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';

// チェック状態をセッションに保存（リダイレクト後に表示制御に使用）
$_SESSION['show_debug_logs'] = $debug_logs_enabled;
```

**変更点：**
- POST時のチェック状態を `$_SESSION['show_debug_logs']` に保存
- リダイレクト後、セッションから取得して表示判定に使用

#### 3. リダイレクト時のURL構築（1036行目付近）
```php
// 修正前
$redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count;
if ($debug_logs_enabled) {
    $redirect_url .= '&debug_logs=1';
}

// 修正後
// リダイレクト先URL を作成（デバッグログは常時出力、表示制御はセッションで管理）
$redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count . '&debug_logs=1';
```

**変更点：**
- **常にURL に `&debug_logs=1` を追加**（条件分岐なし）
- デバッグログデータは常にセッションに保存される
- 表示制御はセッション（`$_SESSION['show_debug_logs']`）で行う

## 動作フロー

### 登録時
```
① ユーザーが「詳細ログを表示」をチェック
  → 隠しフィールド `debug_logs_enabled` = '1' に設定

② 登録ボタンをクリック
  → POST送信: debug_logs_enabled=1 が含まれる

③ 作者名分割処理
  → $_SESSION['debug_splits'] にログを保存（常時）
  → $_SESSION['show_debug_logs'] = true に設定（チェック状態）

④ リダイレクト実行
  → content_bulk_add.php?issue_id=18794&saved=1&inserted=23&debug_logs=1
```

### リダイレクト後の表示
```
⑤ セッションからデータを取得
  → $debug_info = $_SESSION['debug_splits']（デバッグログ常時保存）
  → $show_debug_logs = $_SESSION['show_debug_logs']（チェック状態）

⑥ 画面表示
  ✅ チェックボックス：常に表示（デフォルトは、前回のチェック状態を反映）
  ✅ ログコンテナ：$show_debug_logs = true なら表示、false なら隠す
```

## セッション管理

### セッション変数の役割

| セッション変数 | 用途 | 設定時期 | 削除時期 |
|---|---|---|---|
| `$_SESSION['debug_splits']` | デバッグログデータ（配列） | 作者名分割時 | 表示後 |
| `$_SESSION['show_debug_logs']` | 表示フラグ（true/false） | POST時 | 表示後 |

### セッションの流れ

```
1. POST時（登録フォーム送信）
   $_SESSION['debug_splits'] = [ ... ログデータ ... ]
   $_SESSION['show_debug_logs'] = true/false（チェック状態）

2. リダイレクト
   (セッションは保持される)

3. GET時（リダイレクト後）
   $debug_info = $_SESSION['debug_splits']
   $show_debug_logs = $_SESSION['show_debug_logs']
   
   両変数を使用後、削除：
   unset($_SESSION['debug_splits'])
   unset($_SESSION['show_debug_logs'])
```

## デフォルト表示設定

### チェックボックスのデフォルト状態
```html
<input type="checkbox" id="toggleDebugLogs" 
    onchange="toggleDebugLogs()" 
    <?= $show_debug_logs ? 'checked' : '' ?>>
```

- **初回訪問**：`$show_debug_logs = false` → チェックなし
- **登録後**：`$_SESSION['show_debug_logs']` の値に応じて反映

### ログコンテナの初期表示
```html
<div id="debugLogsContainer" style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
```

- **初期状態**：`display: none`（隠す）
- **チェック時**：JavaScriptで `display: block`（表示）

## JavaScript側の動作

### 初期化（DOMContentLoaded時）
```javascript
const hiddenField = document.getElementById('debugLogsEnabledField');
if (hiddenField && checkbox) {
    hiddenField.value = checkbox.checked ? '1' : '0';
}
```

- ページロード時、チェックボックスの状態を隠しフィールドに同期

### チェック変更時（toggleDebugLogs()）
```javascript
hiddenField.value = checkbox.checked ? '1' : '0';

if (checkbox.checked) {
    container.style.display = 'block';  // 表示
} else {
    container.style.display = 'none';   // 隠す
}
```

- チェック/チェック解除時、ログの表示/非表示をToggle

## テスト方法

### ✅ テストケース1：デフォルト隠す
1. ページを開く
2. ✅ チェックボックスが**チェックなし**の状態
3. ✅ ログコンテナが**隠れている**（`display: none`）

### ✅ テストケース2：チェックで表示
1. チェックボックスをON
2. ✅ ログコンテナが**表示される**（`display: block`）
3. 登録ボタンをクリック
4. ✅ 登録後、リダイレクト先でログが**表示されている**

### ✅ テストケース3：チェックなしで登録
1. チェックボックスが**チェックなし**
2. 登録ボタンをクリック
3. ✅ 登録後、リダイレクト先でログが**隠れている**

### ✅ テストケース4：作者名分割確認
1. チェックボックスをON
2. 作者名「小林哲也、DJ SPACE」を入力
3. 登録ボタンをクリック
4. ✅ ログに以下が表示されている：
   ```
   作者: author:小林哲也
   作者: author:DJ SPACE
   ```
   ✅ **3つに分割されていない**（「DJ」と「SPACE」が分かれていない）

## デバッグのポイント

### ブラウザの開発者ツル（F12）で確認する項目

1. **Network タブ**
   - POST リクエストの Form Data に `debug_logs_enabled=1` が含まれているか
   - リダイレクト先URLに `&debug_logs=1` が含まれているか

2. **Application タブ → Cookies → Session**
   - `show_debug_logs` が存在するか
   - 値が正しいか（true/false）

3. **Console**
   - JavaScript エラーがないか
   - `toggleDebugLogs()` が正しく実行されているか

## 修正前後の比較

| 項目 | 修正前 | 修正後 |
|---|---|---|
| **ログ出力** | チェック時のみ | 常時出力 |
| **初期表示** | 条件による | デフォルト隠す |
| **状態管理** | URLパラメータ | セッション + URLパラメータ |
| **信頼性** | 不安定 | 安定（セッション保持） |
| **ユーザー体験** | 手動設定後も状態が失われる可能性 | 安定した表示制御 |

## 修正の効果

### 修正前の問題
❌ ログが表示されないことがある  
❌ チェック状態が失われる可能性  
❌ URLパラメータに依存  

### 修正後の改善
✅ ログは常にセッションに保存されるため、確実に取得できる  
✅ 表示制御はセッション変数で行うため、状態が確実に保持される  
✅ URLパラメータは統一的に使用（`&debug_logs=1` は常に追加）  
✅ デフォルトは隠すため、ユーザーが明示的に確認したい場合のみ表示  

## 補足

### なぜセッションを使うのか？

- **セッション**：サーバー側に保存される、ページ遷移中も保持される
- **URLパラメータ**：ブラウザのURL、リダイレクトで失われる可能性がある
- **隠しフィールド**：フォーム送信時のみ、POST→GET 遷移では保持されない

セッションとURLパラメータの**組み合わせ**により、確実な状態管理を実現しています。

---

## 修正完了チェックリスト

- [x] セッション変数の役割を明確化
- [x] デバッグログを常時出力する処理を追加
- [x] 表示フラグをセッションで管理する機能を実装
- [x] リダイレクトURL を常に `&debug_logs=1` を付与するよう修正
- [x] HTMLのデフォルト表示を「隠す」に設定
- [x] JavaScriptで表示/非表示の同期を実装
- [x] テスト方法を文書化

---

**修正完了日：2026年8月31日**
