# 実装詳細書：デバッグログ常時出力方式

## 目次
1. [全体アーキテクチャ](#全体アーキテクチャ)
2. [各ファイルの修正内容](#各ファイルの修正内容)
3. [セッション管理](#セッション管理)
4. [JavaScriptの処理フロー](#javascriptの処理フロー)
5. [HTML構造](#html構造)
6. [トラブルシューティング](#トラブルシューティング)

---

## 全体アーキテクチャ

### システム図

```
┌─────────────────────────────────────────┐
│        ユーザー入力フォーム              │
│  • 作者名を入力                          │
│  • 「詳細ログを表示」をチェック（任意）  │
└──────────────┬──────────────────────────┘
               │
               ├─→ JavaScript: toggleDebugLogs()
               │   └─→ 隠しフィールド値を更新
               │
               ▼
        [POST 送信]
    debug_logs_enabled=0/1
               │
               ▼
   ┌──────────────────────────┐
   │  PHP POST処理            │
   │  • チェック状態を取得    │
   │  • セッションに保存      │
   │    - debug_splits        │
   │    - show_debug_logs     │
   │  • 登録処理実行          │
   │  • ログを常時出力        │
   └────────┬─────────────────┘
            │
            ├─→ $_SESSION['debug_splits'] = [ ... ]
            ├─→ $_SESSION['show_debug_logs'] = true/false
            │
            ▼
   [リダイレクト]
   &debug_logs=1 （常に付与）
            │
            ▼
  ┌─────────────────────────────┐
  │ PHP GET処理（リダイレクト後）│
  │ • セッションからログ取得    │
  │ • セッションから表示フラグ取得
  │ • $show_debug_logs で制御   │
  └────────┬────────────────────┘
           │
           ▼
   ┌─────────────────────┐
   │   HTML 出力         │
   │ • ログコンテナ表示  │
   │   ($show_debug_logs│
   │    = true)          │
   │ • ログコンテナ隠す  │
   │   ($show_debug_logs│
   │    = false)         │
   └─────────────────────┘
```

---

## 各ファイルの修正内容

### ファイル：content_bulk_add.php

#### 修正1：初期表示フラグの取得（670行目付近）

**目的**：リダイレクト後、セッションからデバッグログデータと表示フラグを取得

**修正前のコード**
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['saved']) && $_GET['saved'] === '1') {
    $inserted_count = isset($_GET['inserted']) ? max(0, (int)$_GET['inserted']) : 0;
    $message = $inserted_count . '件の目次データを登録しました。...';
    
    // URLパラメータから表示フラグを判定
    $show_debug_logs = isset($_GET['debug_logs']) && $_GET['debug_logs'] === '1';
    
    // URLパラメータが true の時のみセッションから取得
    if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
        $debug_info = $_SESSION['debug_splits'];
        unset($_SESSION['debug_splits']);
    } elseif (!empty($_SESSION['debug_splits'])) {
        unset($_SESSION['debug_splits']);
    }
}
```

**修正後のコード**
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['saved']) && $_GET['saved'] === '1') {
    $inserted_count = isset($_GET['inserted']) ? max(0, (int)$_GET['inserted']) : 0;
    $message = $inserted_count . '件の目次データを登録しました。...';
    
    // デバッグ情報をセッションから取得（常時出力、表示状態はチェックボックスで制御）
    if (!empty($_SESSION['debug_splits'])) {
        $debug_info = $_SESSION['debug_splits'];
        // デバッグログ表示フラグを確認（POST時のチェック状態をセッションから取得）
        $show_debug_logs = isset($_SESSION['show_debug_logs']) && $_SESSION['show_debug_logs'] === true;
        unset($_SESSION['debug_splits']);  // 表示後は削除
        unset($_SESSION['show_debug_logs']);
    }
}
```

**変更点の説明**
- 【削除】URLパラメータ `$_GET['debug_logs']` での判定
- 【追加】セッション変数 `$_SESSION['show_debug_logs']` からの判定
- 【変更】**ログデータは常にセッションから取得**（前の実装では URLパラメータが '1' の時だけ取得）
- 【追加】表示フラグの取得後、セッション変数を削除（リセット）

---

#### 修正2：POST時のチェック状態をセッションに保存（761-763行目付近）

**目的**：ユーザーのチェックボックス状態を、リダイレクト後も保持するため、セッションに保存

**修正前のコード**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $posted_rows = $_POST['rows'] ?? [];
    if (!is_array($posted_rows)) {
        $posted_rows = [];
    }

    // POST時のデバッグログ表示状態を確認
    $debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
    
    // 登録処理へ...
}
```

**修正後のコード**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $posted_rows = $_POST['rows'] ?? [];
    if (!is_array($posted_rows)) {
        $posted_rows = [];
    }

    // POST時のデバッグログ表示状態を確認（チェックボックス状態）
    $debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
    
    // チェック状態をセッションに保存（リダイレクト後に表示制御に使用）
    $_SESSION['show_debug_logs'] = $debug_logs_enabled;
    
    // 登録処理へ...
}
```

**変更点の説明**
- 【追加】`$_SESSION['show_debug_logs'] = $debug_logs_enabled;`
- この1行により、ユーザーのチェック状態がセッションに保存される
- リダイレクト後、このセッション値から表示フラグを判定

---

#### 修正3：リダイレクト時のURL構築（1036行目付近）

**目的**：デバッグログを常時出力する、かつ常にURLに `&debug_logs=1` を付与

**修正前のコード**
```php
// リダイレクト先URL を作成（POST時のデバッグログ状態を保持）
$redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count;
if ($debug_logs_enabled) {
    $redirect_url .= '&debug_logs=1';
}

header('Location: ' . $redirect_url);
exit;
```

**修正後のコード**
```php
// リダイレクト先URL を作成（デバッグログは常時出力、表示制御はセッションで管理）
$redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count . '&debug_logs=1';

header('Location: ' . $redirect_url);
exit;
```

**変更点の説明**
- 【削除】`if ($debug_logs_enabled)` の条件分岐
- 【変更】**常に** `&debug_logs=1` をURL に付与
- これにより、GET時に `&debug_logs=1` パラメータが常に存在
- セッションの有無判定 `if (!empty($_SESSION['debug_splits']))` で表示/非表示を制御

---

## セッション管理

### セッション変数の役割

```php
// 1. 作者名分割時（作者名処理内で自動的に設定）
$_SESSION['debug_splits'][] = [
    'row' => $i + 1,
    'input' => $creator_name,
    'output' => $creator_rows,
    'debug_html' => debug_split_creator_names($creator_name),
];

// 2. POST時（チェックボックス状態を保存）
$_SESSION['show_debug_logs'] = $debug_logs_enabled;  // true または false
```

### セッションのライフサイクル

```
┌─────────────────────────────────────┐
│  セッション開始（session_start()）   │
│  ※既存のセッション情報が復元される   │
└────────────┬────────────────────────┘
             │
             ▼
   ┌─────────────────────────┐
   │   POST時の処理          │
   │ $_SESSION['show_debug_logs']
   │  = チェック状態        │
   └────────┬────────────────┘
            │
            ▼
   ┌─────────────────────────────┐
   │ 作者名分割処理              │
   │ $_SESSION['debug_splits'][] │
   │  = ログデータ               │
   └────────┬────────────────────┘
            │
            ▼
        [リダイレクト]
            │
            ▼（セッションはサーバー側に保持）
   ┌─────────────────────────────┐
   │ GET時の処理                 │
   │ • $debug_info              │
   │  = $_SESSION['debug_splits']│
   │ • $show_debug_logs         │
   │  = $_SESSION['show_debug_logs']
   └────────┬────────────────────┘
            │
            ▼
   ┌─────────────────────────────┐
   │ セッション変数の削除        │
   │ unset($_SESSION[...])       │
   └────────┬────────────────────┘
            │
            ▼
   ┌─────────────────────────────┐
   │ HTML出力（ユーザー表示）    │
   └─────────────────────────────┘
```

---

## JavaScriptの処理フロー

### 1. ページロード時の初期化

**実行時機**：`DOMContentLoaded` イベント

**目的**：ページ読み込み時に、チェックボックスの状態を隠しフィールドに同期

```javascript
document.addEventListener('DOMContentLoaded', function() {
    const checkbox = document.getElementById('toggleDebugLogs');
    const hiddenField = document.getElementById('debugLogsEnabledField');
    
    if (checkbox && hiddenField) {
        // ページ読み込み時、チェック状態を隠しフィールドに反映
        hiddenField.value = checkbox.checked ? '1' : '0';
    }
});
```

**処理フロー**
```
ページ読み込み
    ↓
checkbox.checked を確認
    ↓
hiddenField.value を '0' または '1' に設定
    ↓
フォーム送信時、隠しフィールドがPOSTデータに含まれる
```

---

### 2. チェック変更時の同期

**実行時機**：チェックボックスの `onchange` イベント

**目的**：ユーザーがチェック/解除した時、隠しフィールドとログコンテナの表示を同期

```javascript
window.toggleDebugLogs = function() {
    const checkbox = document.getElementById('toggleDebugLogs');
    const container = document.getElementById('debugLogsContainer');
    const hiddenField = document.getElementById('debugLogsEnabledField');
    
    if (!checkbox || !hiddenField) return;
    
    // 隠しフィールドを更新（チェック状態をPOSTで送信）
    hiddenField.value = checkbox.checked ? '1' : '0';
    
    if (!container) return;  // ログコンテナがない場合はここで終了
    
    if (checkbox.checked) {
        container.style.display = 'block';
        // URLにdebug_logs=1を追加（リロード時に状態を保持）
        const url = new URL(window.location);
        url.searchParams.set('debug_logs', '1');
        window.history.replaceState({}, '', url.toString());
    } else {
        container.style.display = 'none';
        // URLからdebug_logsパラメータを削除
        const url = new URL(window.location);
        url.searchParams.delete('debug_logs');
        window.history.replaceState({}, '', url.toString());
    }
};
```

**処理フロー**

```
チェックボックスがチェックされた
    ↓
hiddenField.value = '1'
    ↓
container.style.display = 'block'（表示）
    ↓
URL に debug_logs=1 を追加
    ↓
history.replaceState() でURLを変更（画面遷移なし）

-----

チェックボックスが解除された
    ↓
hiddenField.value = '0'
    ↓
container.style.display = 'none'（隠す）
    ↓
URL から debug_logs パラメータを削除
    ↓
history.replaceState() でURLを変更（画面遷移なし）
```

---

### 3. フォーム送信時の流れ

```javascript
// フォーム送信時、隠しフィールドの値が自動的にPOSTデータに含まれる
// 例：debug_logs_enabled=1

// POST送信
form.submit();
```

**フォームデータ**
```
POST /admin/content_bulk_add.php?issue_id=18794
Content-Type: application/x-www-form-urlencoded

issue_id=18794&rows[0][title_main]=...&debug_logs_enabled=1
```

---

## HTML構造

### 1. チェックボックス（常に表示）

```html
<!-- 詳細ログ表示の切り替え（常に表示） -->
<div style="margin: 15px 0; padding: 10px; background: #f5f5f5; border: 1px solid #ddd;">
    <label style="cursor: pointer; user-select: none;">
        <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()" 
            <?= $show_debug_logs ? 'checked' : '' ?>>
        <strong style="color: #333;">詳細ログを表示（作者名分割処理）</strong>
    </label>
</div>
```

**属性**
- `id="toggleDebugLogs"` ：JavaScriptで参照
- `onchange="toggleDebugLogs()"` ：チェック変更時に関数実行
- `<?= $show_debug_logs ? 'checked' : '' ?>` ：PHPから初期状態を制御

---

### 2. 隠しフィールド（フォーム内）

```html
<form method="post" action="content_bulk_add.php<?= ($issue_id > 0) ? '?issue_id=' . h($issue_id) : '' ?>" class="js-content-bulk-form">
    <!-- デバッグログ表示状態をPOSTで送信（チェックボックスの状態を保持） -->
    <input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="0">
    
    <!-- その他のフォーム要素 -->
    ...
</form>
```

**属性**
- `type="hidden"` ：画面に表示されない
- `id="debugLogsEnabledField"` ：JavaScriptで参照して値を更新
- `name="debug_logs_enabled"` ：PHPで`$_POST['debug_logs_enabled']` として取得
- `value="0"` ：デフォルト値（JavaScript で更新される）

---

### 3. デバッグログコンテナ（条件付き表示）

```html
<!-- デバッグ情報表示エリア（登録後のみ、チェック状態に応じて表示） -->
<?php if (!empty($debug_info)): ?>
    <div id="debugLogsContainer" style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
        <div style="background: #f0f0f0; border: 2px solid #666; padding: 15px; margin: 20px 0;">
            <h3 style="color: #333; margin-top: 0;">【詳細：作者名分割処理ログ】</h3>
            <p style="color: #666; font-size: 12px; margin: 5px 0;">
                区切り文字：「、」「,」「／」「/」（スペースは区切りません）<br>
                全 <?= count($debug_info) ?> 件の作者名を処理しました
            </p>
            <?php foreach ($debug_info as $debug): ?>
                <div style="background: #fff; border: 1px solid #ccc; padding: 10px; margin: 10px 0;">
                    <strong style="color: #0066cc;">行 <?= $debug['row'] ?>:</strong>
                    <span style="color: #333;">入力値: <code style="background: #f5f5f5; padding: 2px 5px;"><?= htmlspecialchars($debug['input'], ENT_QUOTES, 'UTF-8') ?></code></span>
                    <?= $debug['debug_html'] ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
```

**特徴**
- `<?php if (!empty($debug_info)): ?>` ：デバッグデータがある場合のみレンダリング
- `style="<?= $show_debug_logs ? '' : 'display: none;' ?>"` ：チェック状態に応じて初期表示を制御
- JavaScript により `display: block / none` がトグルされる

---

## トラブルシューティング

### Q1：ログが表示されない場合

#### 確認項目

1. **セッションが保存されているか**
   ```php
   // content_bulk_add.php の HEAD に一時的に追加して確認
   echo '<pre>';
   var_dump($_SESSION);
   echo '</pre>';
   ```

2. **デバッグデータがセッションに存在するか**
   ```php
   if (!empty($_SESSION['debug_splits'])) {
       echo "✅ デバッグデータあり: " . count($_SESSION['debug_splits']) . " 件";
   } else {
       echo "❌ デバッグデータなし";
   }
   ```

3. **表示フラグの値は正しいか**
   ```php
   echo "表示フラグ: " . ($_SESSION['show_debug_logs'] ? 'true' : 'false');
   ```

#### 対応方法

- **セッションが空**：登録処理がスキップされていないか確認
- **表示フラグが false**：チェックボックスが送信されていないか確認（隠しフィールドの値）
- **デバッグデータはあるが表示されない**：`display: none` が効いているか確認（ブラウザの F12 で確認）

---

### Q2：チェックボックスの状態がリセットされる場合

#### 確認項目

1. **隠しフィールドの値が正しいか**
   ```html
   <input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="?">
   <!-- ブラウザの F12 Developer Tools で確認 -->
   ```

2. **POST送信時にデータが含まれているか**
   ```
   F12 → Network → POST リクエスト → Form Data を確認
   debug_logs_enabled=1 が含まれているか？
   ```

3. **JavaScript の初期化が実行されているか**
   ```javascript
   // F12 Console で実行
   console.log(document.getElementById('debugLogsEnabledField').value);
   ```

#### 対応方法

- **隠しフィールドの値が '0'**：`toggleDebugLogs()` が実行されていないか、またはチェック状態の反映に失敗している
- **POST送信でデータが含まれない**：フォーム要素の `name` 属性が正しいか確認
- **JavaScript エラー**：F12 Console のエラーメッセージを確認

---

### Q3：ページをリロードするとチェック状態が保持されない場合

#### 原因
- ページリロード時、PHP は初期状態から処理を開始
- セッションからデータを取得して `$show_debug_logs` を判定するが、セッション変数が削除されている

#### 対応方法

1. **セッション削除タイミングを確認**
   ```php
   // 現在の実装では、表示後に削除される
   unset($_SESSION['show_debug_logs']);
   ```
   これは意図的な仕様（リロード後は初期状態に戻す）

2. **リロード後も状態を保持したい場合**
   ```php
   // 削除ライン を コメントアウト
   // unset($_SESSION['show_debug_logs']);
   ```

---

### Q4：複数の作者名で分割処理ログが出力される場合

#### 確認項目

1. **配列要素が複数存在するか**
   ```php
   echo "ログ件数: " . count($_SESSION['debug_splits']);
   ```

2. **各行のログデータが正しいか**
   ```php
   foreach ($_SESSION['debug_splits'] as $debug) {
       echo "行 {$debug['row']}: {$debug['input']} → " . 
            implode(', ', $debug['output']) . "\n";
   }
   ```

---

## コード全体の流れ（時系列）

```
【ユーザー操作】
1. ページ読み込み
   → JavaScript: DOMContentLoaded
     └─ hiddenField.value = checkbox.checked ? '1' : '0'

2. チェックボックスをクリック
   → JavaScript: toggleDebugLogs()
     ├─ hiddenField.value = '1' (チェック時)
     ├─ container.style.display = 'block'
     └─ URL に debug_logs=1 を追加

3. 作者名を入力
   (例: 小林哲也、DJ SPACE)

4. 登録ボタンをクリック
   → form.submit()
     └─ POST data: debug_logs_enabled=1, rows[0][creator_name]=小林哲也、DJ SPACE, ...

【サーバー側処理】
5. POST 処理開始
   → $debug_logs_enabled = true (POST data から取得)
   → $_SESSION['show_debug_logs'] = true (セッションに保存)

6. 作者名分割処理
   → creator_name = "小林哲也、DJ SPACE"
   → split_creator_names() により分割
   → $_SESSION['debug_splits'][] = { row, input, output, debug_html }

7. リダイレクト
   → header('Location: content_bulk_add.php?...&debug_logs=1')
   → exit;

【リダイレクト後】
8. GET 処理開始
   → $_SERVER['REQUEST_METHOD'] === 'GET'
   → $_GET['saved'] === '1' なら表示メッセージを設定
   → $_SESSION['debug_splits'] から $debug_info を取得（常時）
   → $_SESSION['show_debug_logs'] から $show_debug_logs を取得
   → セッション変数を削除

9. HTML 出力
   → チェックボックス: checked="${show_debug_logs}" (PHP で判定)
   → コンテナ: style="display: <?= $show_debug_logs ? 'block' : 'none' ?>"

【ページ表示後】
10. JavaScript: DOMContentLoaded（再実行）
    → hiddenField.value = checkbox.checked ? '1' : '0'

11. ユーザーが「隠す」をクリック
    → JavaScript: toggleDebugLogs()
      ├─ hiddenField.value = '0'
      ├─ container.style.display = 'none'
      └─ URL から debug_logs パラメータを削除
```

---

## まとめ

### 修正のポイント

✅ **ログデータは常時出力**  
- `$_SESSION['debug_splits']` に登録処理時に自動保存  
- セッションは常に保持される

✅ **表示制御はセッション変数**  
- `$_SESSION['show_debug_logs']` でPOST時のチェック状態を保存  
- リダイレクト後、セッションから取得して表示判定

✅ **デフォルトは隠す**  
- 初期表示 `display: none`  
- チェックボックスで ON/OFF 制御

✅ **複数フロー の組み合わせ**  
- HTML（初期表示）+ JavaScript（ON/OFF制御）+ PHP（状態管理）  
- 3層の連携により、確実な状態管理を実現

---

**実装完了日：2026年8月31日**
