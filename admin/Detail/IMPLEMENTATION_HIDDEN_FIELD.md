# チェックボックス状態保持機能の実装詳細書

## 概要

POST送信時にチェックボックスの状態を保持するために、隠しフィールドを使用した実装方法を解説します。

---

## 実装の全体構造

```
クライアント側（JavaScript）
  ↓
  隠しフィールド（hidden input）を管理
  ↓
サーバー側（PHP）
  ↓
  POSTデータから状態を取得
  ↓
  リダイレクト時にURLパラメータに反映
```

---

## 実装の詳細

### 1. HTML 層 - 隠しフィールドの定義

#### 位置: form タグ直後（1896-1897行目）

```html
<form method="post" action="content_bulk_add.php<?= ... ?>">
    <!-- デバッグログ表示状態をPOSTで送信（チェックボックスの状態を保持） -->
    <input type="hidden" id="debugLogsEnabledField" name="debug_logs_enabled" value="0">
    
    <!-- ここからフォーム要素 -->
    <div class="form-row">
        ...
    </div>
</form>
```

#### 要素の詳細

```html
<input 
    type="hidden"                      <!-- ユーザーに見えない -->
    id="debugLogsEnabledField"         <!-- JavaScript用のID -->
    name="debug_logs_enabled"          <!-- POST時のフィールド名 -->
    value="0"                          <!-- 初期値（OFF状態） -->
>
```

**属性の説明:**

| 属性 | 値 | 説明 |
|------|-----|------|
| `type` | `hidden` | 隠しフィールド（画面に表示されない） |
| `id` | `debugLogsEnabledField` | JavaScriptで参照するためのID |
| `name` | `debug_logs_enabled` | POST時に $_POST['debug_logs_enabled'] としてアクセス |
| `value` | `0` | 初期値（OFF状態、データ送信時は '0' 文字列） |

**フロー:**
1. form送信時、すべての input 要素が送信される
2. 隠しフィールドも含まれる
3. `name="debug_logs_enabled"` として $_POST に格納される

---

### 2. JavaScript 層 - チェック状態の同期

#### 2.1 ページロード時の初期化（1394-1399行目）

```javascript
document.addEventListener('DOMContentLoaded', function () {
    // チェックボックスの状態で隠しフィールドを初期化
    const checkbox = document.getElementById('toggleDebugLogs');
    const hiddenField = document.getElementById('debugLogsEnabledField');
    if (checkbox && hiddenField) {
        hiddenField.value = checkbox.checked ? '1' : '0';
    }
    
    // ... 以下の処理に続く
});
```

**処理フロー:**

```
DOMContentLoaded イベント発火
  ↓
1. checkbox 要素を取得
   ↓
2. hiddenField 要素を取得
   ↓
3. 両要素が存在するか確認
   ↓
4. checkbox.checked の値を確認
   ↓ (true の場合)
   hiddenField.value = '1'
   ↓ (false の場合)
   hiddenField.value = '0'
```

**目的:**
- ページロード時（特にリロード後）にチェック状態を隠しフィールドに反映
- ユーザーがF5でリロードした場合、チェック状態が保持される
- その隠しフィールドの値で、最後に送信されたチェック状態を記憶

#### 2.2 チェック状態変更時の更新（1784-1809行目）

```javascript
window.toggleDebugLogs = function() {
    const checkbox = document.getElementById('toggleDebugLogs');
    const container = document.getElementById('debugLogsContainer');
    const hiddenField = document.getElementById('debugLogsEnabledField');
    
    if (!checkbox || !hiddenField) return;
    
    // ★ ここが重要: 隠しフィールドを更新（チェック状態をPOSTで送信）
    hiddenField.value = checkbox.checked ? '1' : '0';
    
    if (!container) return;  // ログコンテナがない場合はここで終了
    
    if (checkbox.checked) {
        container.style.display = 'block';
        const url = new URL(window.location);
        url.searchParams.set('debug_logs', '1');
        window.history.replaceState({}, '', url.toString());
    } else {
        container.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.delete('debug_logs');
        window.history.replaceState({}, '', url.toString());
    }
};
```

**処理フロー:**

```
チェックボックスの onchange イベント
  ↓
toggleDebugLogs() 関数実行
  ↓
1. 必要な要素を取得
   ↓
2. checkbox.checked の値を確認
   ↓ (true)
   hiddenField.value = '1'
   ↓ (false)
   hiddenField.value = '0'
   ↓
3. 隠しフィールドが更新された
   ↓
4. 続いてURLの更新
   ↓
5. ログコンテナの表示/非表示を切り替え
```

**重要なポイント:**

```javascript
hiddenField.value = checkbox.checked ? '1' : '0';
```

- `checkbox.checked` - ブラウザの内部状態（true/false）
- `? '1' : '0'` - 文字列に変換（POST時に '1' または '0' として送信）
- `hiddenField.value` - 隠しフィールドの value 属性に代入

---

### 3. PHP 層 - POST受信と処理

#### 3.1 POST データの受信（761-762行目）

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issue_id = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $posted_rows = $_POST['rows'] ?? [];
    if (!is_array($posted_rows)) {
        $posted_rows = [];
    }

    // POST時のデバッグログ表示状態を確認
    $debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
    
    // ... 以下のPOST処理に続く
}
```

**処理フロー:**

```
form 送信（POST）
  ↓
$_POST グローバル変数に格納
  ↓
$_POST['debug_logs_enabled'] に隠しフィールドの値が格納
  値: '0' または '1' （文字列）
  ↓
isset() で存在確認
  ↓ (存在する場合)
  $_POST['debug_logs_enabled'] === '1' で値を比較
  ↓ (true)
  $debug_logs_enabled = true
  ↓ (false)
  $debug_logs_enabled = false
```

**厳密な比較の理由:**

```php
$_POST['debug_logs_enabled'] === '1'  // 厳密な比較
// ✅ 推奨

// 代わりに以下は使用しない:
$_POST['debug_logs_enabled'] == '1'   // 緩い比較（非推奨）
if ($_POST['debug_logs_enabled'])     // 型強制（危険）
```

理由:
- `== '1'` は型の自動変換により、'1' 以外の値でも true になる可能性
- `=== '1'` で厳密に '1' のみを許容
- セキュリティと予測可能性の向上

#### 3.2 リダイレクト時のURL作成（1036-1042行目）

```php
renumber_issue_contents($pdo, $issue_id);

$pdo->commit();

unset($_SESSION['toc_extract_rows'], $_SESSION['toc_extract_issue_id']);

// リダイレクト先URL を作成（POST時のデバッグログ状態を保持）
$redirect_url = 'content_bulk_add.php?issue_id=' . $issue_id . '&saved=1&inserted=' . $inserted_count;
if ($debug_logs_enabled) {
    $redirect_url .= '&debug_logs=1';
}

header('Location: ' . $redirect_url);
```

**リダイレクト先URL の組立:**

```
基本URL:
  content_bulk_add.php?issue_id=18794&saved=1&inserted=23

$debug_logs_enabled が true の場合:
  content_bulk_add.php?issue_id=18794&saved=1&inserted=23&debug_logs=1
  ↑ ここに &debug_logs=1 が追加される

$debug_logs_enabled が false の場合:
  content_bulk_add.php?issue_id=18794&saved=1&inserted=23
  ↑ パラメータなし（通常通り）
```

**目的:**
- チェック状態をURLパラメータに埋め込む
- ブラウザの Location ヘッダーでリダイレクト
- リダイレクト先のページロードで、URLから `debug_logs` パラメータを読み取り

---

### 4. PHPの初期化（既存コード）

#### 初期化時のコード（671-679行目）

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['saved']) && $_GET['saved'] === '1') {
    $inserted_count = isset($_GET['inserted']) ? max(0, (int)$_GET['inserted']) : 0;
    $message = $inserted_count . '件の目次データを登録しました。開始ページ順で掲載順を自動更新しました。';
    
    // デバッグログ表示フラグを確認（チェックボックス状態）
    $show_debug_logs = isset($_GET['debug_logs']) && $_GET['debug_logs'] === '1';
    
    // デバッグ情報をセッションから取得
    if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
        $debug_info = $_SESSION['debug_splits'];
        unset($_SESSION['debug_splits']);  // 表示後は削除
    } elseif (!empty($_SESSION['debug_splits'])) {
        // チェックなしの場合は削除するだけ
        unset($_SESSION['debug_splits']);
    }
}
```

**流れ:**

```
リダイレクト後のページロード
  ↓
URL が ?saved=1&debug_logs=1 の場合
  ↓
$_GET['debug_logs'] === '1'
  ↓ (true)
$show_debug_logs = true
  ↓
セッションのデバッグ情報を $debug_info に代入
  ↓
ログが表示される
```

---

## 全体的なデータフロー図

```
┌─────────────────────────────────────────────────────────┐
│                     クライアント側                       │
└─────────────────────────────────────────────────────────┘

1. ページロード
   ↓
   <input id="toggleDebugLogs"> 表示
   <input id="debugLogsEnabledField" value="0"> 初期化
   ↓

2. ユーザーがチェック
   ↓
   JavaScript: onchange="toggleDebugLogs()"
   ↓
   toggleDebugLogs() 実行
     - checkbox.checked = true
     - hiddenField.value = "1" ← ★ ここで隠しフィールドを更新
   ↓

3. ユーザーが登録ボタンをクリック
   ↓
   form.submit()
   ↓
   POST 送信
     - name="rows", value="[...]"
     - name="debug_logs_enabled", value="1" ← ★ 隠しフィールド送信

┌─────────────────────────────────────────────────────────┐
│                     サーバー側（PHP）                    │
└─────────────────────────────────────────────────────────┘

4. POST データ受信
   ↓
   $_POST['debug_logs_enabled'] = '1'
   ↓
   $debug_logs_enabled = true ← ★ ここで取得
   ↓

5. 登録処理実行
   ↓
   データベースへ挿入
   ↓
   セッションにデバッグ情報を保存
   ↓

6. リダイレクト URL 作成
   ↓
   if ($debug_logs_enabled) {
       $redirect_url .= '&debug_logs=1';  ← ★ ここでURLに追加
   }
   ↓
   header('Location: ' . $redirect_url);
   ↓
   Location: content_bulk_add.php?issue_id=...&saved=1&debug_logs=1

┌─────────────────────────────────────────────────────────┐
│                   リダイレクト先のページ                 │
└─────────────────────────────────────────────────────────┘

7. ページロード
   ↓
   $_GET['debug_logs'] = '1'
   ↓
   $show_debug_logs = true ← ★ ここで true になる
   ↓

8. HTML 生成
   ↓
   <input id="toggleDebugLogs" checked> ← ★ チェック状態が復元
   <input id="debugLogsEnabledField" value="1"> ← ★ 隠しフィールドも復元
   ↓
   JavaScript: DOMContentLoaded
     - hiddenField.value = "1" ← 現在のチェック状態で初期化
   ↓

9. ログ表示
   ↓
   $show_debug_logs = true なら
   <div id="debugLogsContainer" style=""> ← 表示
   ↓
   ログが画面に表示される ✅
```

---

## セキュリティ検証

### POST データの検証

```php
$debug_logs_enabled = isset($_POST['debug_logs_enabled']) && $_POST['debug_logs_enabled'] === '1';
```

**チェック項目:**

1. **存在確認**: `isset($_POST['debug_logs_enabled'])`
   - フィールドが存在するか確認
   - 存在しない場合は false

2. **値の厳密な比較**: `=== '1'`
   - 正確に '1' のみを許容
   - '1' 以外（'0', '', null など）は false

3. **型安全性**
   - 文字列 '1' か厳密に比較
   - ブール値への型強制を避ける

**攻撃シナリオの対策:**

| 攻撃パターン | 送信値 | 処理結果 | 安全性 |
|------------|--------|--------|--------|
| 正常 | '1' | true | ✅ |
| SQLインジェクション | "1'; DROP TABLE..." | false | ✅ |
| XSS | `<script>alert()</script>` | false | ✅ |
| 型強制 | 1（数値） | false | ✅ |
| 空値 | '' | false | ✅ |

---

## 修正ファイル情報

| ファイル | パス |
|---------|------|
| content_bulk_add.php | `c:\moriya\00_main\70_study\30_program\HTML\morizaru.net\indexindex\indexindex\admin\` |

**修正箇所:**

| 行番号 | 内容 | 層 |
|--------|------|-----|
| 761-762 | POST受信処理 | PHP |
| 1036-1042 | リダイレクト処理 | PHP |
| 1394-1399 | ページロード初期化 | JavaScript |
| 1790-1809 | チェック変更時の同期 | JavaScript |
| 1896-1897 | 隠しフィールドの定義 | HTML |

---

## テストケース

### TC-1: チェックONで登録
```
前提: チェックボックスが表示されている
手順:
1. チェックボックスをクリック
2. 隠しフィールドの value が "1" に更新されたことを確認
3. フォーム入力
4. 登録ボタンをクリック
5. ネットワークタブで POST データを確認
   → debug_logs_enabled=1 が送信されている
6. リダイレクト後のURL を確認
   → &debug_logs=1 が付いている
期待値: ✅ ログが表示される
```

### TC-2: チェックOFFで登録
```
前提: チェックボックスがOFF
手順:
1. 隠しフィールドの value が "0" のままであることを確認
2. フォーム入力
3. 登録ボタンをクリック
4. ネットワークタブで POST データを確認
   → debug_logs_enabled=0 が送信されている
5. リダイレクト後のURL を確認
   → &debug_logs が付いていない
期待値: ✅ ログは表示されない
```

### TC-3: リロード時の状態保持
```
前提: チェックONで登録後のページ
手順:
1. ページをリロード（F5）
2. チェックボックスがON状態で表示されることを確認
3. 隠しフィールドの value が "1" であることを確認
4. 再度登録
期待値: ✅ ログが表示される
```

---

## 修正日時

- **修正日:** 2026-08-31
- **修正内容:** POST時のチェック状態を隠しフィールドで送信
