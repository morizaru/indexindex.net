# クイックガイド：デバッグログ常時出力方式

## 修正内容（ザックリ）

### 修正前の問題
❌ チェックボックスをONで登録しても、登録後にログが表示されない

### 修正内容
✅ ログを**常時保存**し、チェック状態で**表示/非表示を制御**

---

## 変更ポイント（3つ）

### 1️⃣ 初期表示フラグの取得【670行目付近】

```php
// 修正前：URLパラメータから判定
$show_debug_logs = isset($_GET['debug_logs']) && $_GET['debug_logs'] === '1';
if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
    $debug_info = $_SESSION['debug_splits'];
}

// 修正後：セッションから判定（ログは常時取得）
if (!empty($_SESSION['debug_splits'])) {
    $debug_info = $_SESSION['debug_splits'];
    $show_debug_logs = isset($_SESSION['show_debug_logs']) && $_SESSION['show_debug_logs'] === true;
    unset($_SESSION['debug_splits']);
    unset($_SESSION['show_debug_logs']);
}
```

**ポイント**：ログは常に取得、表示フラグはセッションから判定

---

### 2️⃣ POST時のチェック状態をセッションに保存【761-763行目付近】

```php
// 修正後：チェック状態をセッションに保存
$debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
$_SESSION['show_debug_logs'] = $debug_logs_enabled;  // ← 追加
```

**ポイント**：チェック状態をセッションに保存して、リダイレクト後に使用

---

### 3️⃣ リダイレクト時のURL構築【1036行目付近】

```php
// 修正前：チェック状態に応じてURL にパラメータを追加
$redirect_url = 'content_bulk_add.php?...&inserted=' . $inserted_count;
if ($debug_logs_enabled) {
    $redirect_url .= '&debug_logs=1';
}

// 修正後：常に &debug_logs=1 を追加
$redirect_url = 'content_bulk_add.php?...&inserted=' . $inserted_count . '&debug_logs=1';
```

**ポイント**：常にURL に `&debug_logs=1` を付与（ログは常時出力）

---

## 動作フロー（シンプル版）

```
① チェックボックスをON
   ↓
② POST送信（debug_logs_enabled=1）
   ↓
③ 登録処理実行
   ├─ ログを $_SESSION['debug_splits'] に保存（常時）
   └─ チェック状態を $_SESSION['show_debug_logs'] に保存（true）
   ↓
④ リダイレクト（?debug_logs=1 を常に付与）
   ↓
⑤ セッションからログと表示フラグを取得
   ├─ $debug_info = $_SESSION['debug_splits']
   └─ $show_debug_logs = $_SESSION['show_debug_logs']（true）
   ↓
⑥ HTML出力
   ├─ チェックボックス：checked="checked"
   └─ ログコンテナ：style="" （display: block になる）
   ↓
⑦ ログが表示される ✅
```

---

## セッション変数（2つ）

| 変数 | 役割 | 設定時期 | 削除時期 |
|---|---|---|---|
| `$_SESSION['debug_splits']` | ログデータ | 登録処理時 | リダイレクト後 |
| `$_SESSION['show_debug_logs']` | 表示フラグ | POST時 | リダイレクト後 |

---

## テストケース（4つ）

### ✅ テストケース1：デフォルト隠す
```
① ページを開く
② チェックボックス：チェックなし ✅
③ ログコンテナ：隠れている ✅
```

### ✅ テストケース2：チェックで表示＆登録
```
① チェックボックスをON
② ログコンテナが表示される ✅
③ 登録ボタンをクリック
④ 登録後、ログが表示されている ✅
```

### ✅ テストケース3：チェックなしで登録
```
① チェックボックス：チェックなし
② 登録ボタンをクリック
③ 登録後、ログが隠れている ✅
```

### ✅ テストケース4：作者名分割確認
```
① チェックをONにして登録
② 作者名「小林哲也、DJ SPACE」を入力
③ ログに表示されるのは：
   - author:小林哲也
   - author:DJ SPACE
   ✅ 3つに分割されていない（DJ と SPACE が一体）
```

---

## デバッグのポイント

### ログが表示されない場合

1. **Network タブで確認**
   ```
   POST リクエスト → Form Data
   ✅ debug_logs_enabled=1 が含まれているか？
   ```

2. **リダイレクトURLで確認**
   ```
   Location: content_bulk_add.php?...&debug_logs=1
   ✅ &debug_logs=1 がURLに含まれているか？
   ```

3. **開発者ツール Console で確認**
   ```javascript
   console.log(document.getElementById('debugLogsEnabledField').value)
   // '1' または '0' が表示されるか？
   ```

---

## 修正の効果

| 項目 | 修正前 | 修正後 |
|---|---|---|
| **ログ出力** | チェック時のみ | 常時出力 |
| **初期表示** | URLパラメータ依存 | デフォルト隠す |
| **状態管理** | URLパラメータ | セッション + URLパラメータ |
| **信頼性** | 低（パラメータ失われる可能性） | 高（セッション保持） |

---

## ファイル変更箇所

```
content_bulk_add.php
├─ 670行目付近：初期表示フラグの取得
├─ 761-763行目付近：POST時のチェック状態をセッション保存
└─ 1036行目付近：リダイレクト時のURL構築（常に&debug_logs=1）
```

---

## HTMLの構造（変更なし）

```html
<!-- チェックボックス（常に表示） -->
<input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()" 
    <?= $show_debug_logs ? 'checked' : '' ?>>

<!-- 隠しフィールド（form内） -->
<input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="0">

<!-- ログコンテナ（条件付き表示） -->
<div id="debugLogsContainer" style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
    ...ログ内容...
</div>
```

---

## JavaScriptの動作（変更なし）

```javascript
// ① ページロード時：チェック状態を隠しフィールドに同期
DOMContentLoaded → hiddenField.value = checkbox.checked ? '1' : '0'

// ② チェック変更時：隠しフィールドとログコンテナを同期
toggleDebugLogs() → {
    hiddenField.value = checkbox.checked ? '1' : '0'
    container.style.display = checkbox.checked ? 'block' : 'none'
}
```

---

## よくある質問

### Q：ログが表示されないんだけど？
A：以下を確認してください
1. チェックボックスがONになっているか？
2. Network タブで POST送信に `debug_logs_enabled=1` が含まれているか？
3. リダイレクト先URLに `&debug_logs=1` が含まれているか？

### Q：チェック状態が保持されないんだけど？
A：以下を確認してください
1. 隠しフィールド `debugLogsEnabledField` の値が正しく更新されているか？（F12で確認）
2. JavaScript エラーがないか？（F12 Console で確認）
3. セッションが有効か？（サーバーのセッション設定を確認）

### Q：なぜセッションを使うの？
A：URLパラメータだけではリダイレクト時に失われる可能性があるため、より安定したセッション（サーバー側保存）を使用しています

---

## 修正完了チェックリスト

- [x] ログを常時セッションに保存
- [x] 表示フラグをセッションで管理
- [x] リダイレクト時に常にURL に `&debug_logs=1` を追加
- [x] デフォルト表示を「隠す」に設定
- [x] テスト方法を確認
- [x] ドキュメント作成

---

**修正完了日：2026年8月31日**
