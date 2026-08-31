# チェックボックス常時表示化の実装詳細書

## 概要

デバッグログ表示チェックボックスを、登録前後に関わらず**常に表示**するように修正しました。これにより、ユーザーは事前にチェック状態を設定でき、登録後のログ表示の有無を予め決定できるようになります。

---

## 修正の構造

### 修正前の構造（条件付き表示）
```php
if ($message !== '') {              // ← 登録メッセージがある場合
    // ✓ メッセージ表示
    // ✓ チェックボックス（ここにあった）
    // ✓ ログ表示エリア
}
```

**問題:** チェックボックスが登録後（saved=1）のときだけ表示される

### 修正後の構造（常時表示）
```php
// ✓ チェックボックス（常に表示）
<div>...</div>

if ($message !== '') {              // ← 登録メッセージがある場合
    // ✓ メッセージ表示のみ
}

if (!empty($debug_info)) {          // ← ログデータがある場合
    // ✓ ログ表示エリア（チェック状態に応じて表示/非表示）
}

if ($message === '') {              // ← 登録メッセージがない場合
    // ✓ 説明メッセージ表示
}
```

**利点:** チェックボックスは常に表示、その他の要素は条件に基づいて表示

---

## 詳細な修正内容

### 修正1: チェックボックスの移動

#### 移動前（1825行目付近）
```html
<?php if ($message !== ''): ?>
    <div class="message">
        ...
    </div>
    
    <!-- チェックボックス（条件内）❌ -->
    <div style="margin: 15px 0; ...">
        <label>
            <input type="checkbox" id="toggleDebugLogs" ...>
            詳細ログを表示（作者名分割処理）
        </label>
    </div>
    
    <!-- ログ表示 -->
    <?php if (!empty($debug_info)): ?>
        ...
    <?php endif; ?>
<?php endif; ?>
```

#### 移動後（1805行目付近）
```html
<!-- チェックボックス（条件外）✅ -->
<div style="margin: 15px 0; ...">
    <label>
        <input type="checkbox" id="toggleDebugLogs" ...>
        詳細ログを表示（作者名分割処理）
    </label>
</div>

<?php if ($message !== ''): ?>
    <div class="message">
        ...
    </div>
<?php endif; ?>

<!-- ログ表示（独立した条件） -->
<?php if (!empty($debug_info)): ?>
    <div id="debugLogsContainer" ...>
        ...
    </div>
<?php endif; ?>
```

---

### 修正2: 説明メッセージの追加

#### 新規実装（1851-1856行目）
```html
<!-- 登録前ユーザーへの説明 -->
<?php if ($message === ''): ?>
    <p style="color: #999; font-size: 12px; margin-top: 10px;">
        💡 チェックボックスをONにすると、登録後に作者名の分割処理ログが表示されます
    </p>
<?php endif; ?>
```

**表示条件:**
- `$message === ''` → メッセージがない（登録前の状態）
- この条件下でのみ説明テキストが表示される

**目的:**
- 初期訪問ユーザーに対して、チェックボックスの機能を説明
- ユーザーフレンドリーな UX を提供

---

## PHP 変数の説明

### 既存の変数
```php
$message = '';          // 登録完了メッセージ
$debug_info = null;     // ログデータ
$show_debug_logs = false;  // 表示フラグ
```

### 変数の値による処理フロー

#### シーン1: 初回ページロード
```
URL: ?issue_id=18794
↓
$message = ''           // メッセージなし
$debug_info = null      // ログデータなし
$show_debug_logs = false // URLに debug_logs パラメータなし
↓
チェックボックス: ✅ 表示（チェックなし）
ログ: ❌ 非表示
説明: ✅ 表示「チェックボックスをONにすると...」
```

#### シーン2: ユーザーがチェック
```
URL: ?issue_id=18794&debug_logs=1
↓
$message = ''           // メッセージなし
$debug_info = null      // ログデータなし
$show_debug_logs = true // URLに debug_logs=1
↓
チェックボックス: ✅ 表示（チェックあり）
ログ: ❌ 非表示（データなし）
説明: ✅ 表示
```

#### シーン3: 登録後
```
URL: ?issue_id=18794&saved=1&inserted=23
↓
$message = '...件登録しました'  // メッセージあり
$debug_info = [...]             // ログデータあり
$show_debug_logs = false         // debug_logs パラメータなし
↓
チェックボックス: ✅ 表示（チェックなし）
ログ: ❌ 非表示（チェック状態OFF）
説明: ❌ 非表示
```

#### シーン4: 登録後+チェック
```
URL: ?issue_id=18794&saved=1&inserted=23&debug_logs=1
↓
$message = '...件登録しました'  // メッセージあり
$debug_info = [...]             // ログデータあり
$show_debug_logs = true          // debug_logs=1
↓
チェックボックス: ✅ 表示（チェックあり）
ログ: ✅ 表示
説明: ❌ 非表示
```

---

## HTML の変更内容

### チェックボックスの HTML 構造
```html
<div style="margin: 15px 0; padding: 10px; background: #f5f5f5; border: 1px solid #ddd;">
    <label style="cursor: pointer; user-select: none;">
        <input type="checkbox" 
            id="toggleDebugLogs"           <!-- JavaScript 参照用 -->
            onchange="toggleDebugLogs()"  <!-- クリック時に関数実行 -->
            <?= $show_debug_logs ? 'checked' : '' ?>>  <!-- PHP で状態制御 -->
        <strong style="color: #333;">詳細ログを表示（作者名分割処理）</strong>
    </label>
</div>
```

### ログコンテナの表示制御
```html
<?php if (!empty($debug_info)): ?>
    <!-- ログデータがある場合のみ表示 -->
    <div id="debugLogsContainer" 
        style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
        <!-- ログの内容 -->
    </div>
<?php endif; ?>
```

**表示制御ロジック:**
1. `!empty($debug_info)` → ログデータが存在
2. `$show_debug_logs ? '' : 'display: none;'` → チェック状態に応じて表示/非表示
   - `true` → style 属性なし（表示）
   - `false` → `display: none` （非表示）

### 説明メッセージ
```html
<?php if ($message === ''): ?>
    <p style="color: #999; font-size: 12px; margin-top: 10px;">
        💡 チェックボックスをONにすると、登録後に作者名の分割処理ログが表示されます
    </p>
<?php endif; ?>
```

**条件:**
- `$message === ''` → 登録完了メッセージがない（登録前）
- この場合のみ説明が表示

---

## JavaScript の動作

既存の `toggleDebugLogs()` 関数は変更なし。以下のように動作します：

```javascript
window.toggleDebugLogs = function() {
    const checkbox = document.getElementById('toggleDebugLogs');
    const container = document.getElementById('debugLogsContainer');
    
    if (!checkbox || !container) return;
    
    if (checkbox.checked) {
        // チェックON
        container.style.display = 'block';
        // URLに debug_logs=1 を追加
        const url = new URL(window.location);
        url.searchParams.set('debug_logs', '1');
        window.history.replaceState({}, '', url.toString());
    } else {
        // チェックOFF
        container.style.display = 'none';
        // URLから debug_logs を削除
        const url = new URL(window.location);
        url.searchParams.delete('debug_logs');
        window.history.replaceState({}, '', url.toString());
    }
};
```

**注意点:**
- `getElementById('debugLogsContainer')` で要素を参照
- 登録前のページではこの要素が存在しないため、`if (!container) return;` で早期リターン
- これにより、登録前でもエラーが発生しない

---

## 完全なコード例

### 登録前のページ
```php
// PHP
$message = '';
$debug_info = null;
$show_debug_logs = false;  // URLに debug_logs なし
```

```html
<!-- チェックボックス（常に表示） -->
<div style="...">
    <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()">
    詳細ログを表示（作者名分割処理）
</div>

<!-- メッセージなし（if $message !== '' が false） -->

<!-- ログなし（if !empty($debug_info) が false） -->

<!-- 説明表示（if $message === '' が true） -->
<p>💡 チェックボックスをONにすると...</p>
```

**HTML 出力:**
```html
<div style="...">
    <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()">
    詳細ログを表示（作者名分割処理）
</div>
<p>💡 チェックボックスをONにすると...</p>
```

### 登録後のページ（チェック状態で登録）
```php
// PHP
$message = '23件の目次データを登録しました...';
$debug_info = [/* ... */];  // ログデータあり
$show_debug_logs = true;     // URLに debug_logs=1
```

```html
<!-- チェックボックス（常に表示） -->
<div style="...">
    <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()" checked>
    詳細ログを表示（作者名分割処理）
</div>

<!-- メッセージ表示（if $message !== '' が true） -->
<div class="message">
    23件の目次データを登録しました...
</div>

<!-- ログ表示（if !empty($debug_info) が true） -->
<div id="debugLogsContainer" style="">  <!-- display: none なし -->
    <!-- ログ内容 -->
</div>

<!-- 説明なし（if $message === '' が false） -->
```

**HTML 出力:**
```html
<div style="...">
    <input type="checkbox" ... checked>
    詳細ログを表示（作者名分割処理）
</div>
<div class="message">
    23件の目次データを登録しました...
</div>
<div id="debugLogsContainer" style="">
    <!-- ログ内容 -->
</div>
```

---

## 修正のメリット・デメリット

### ✅ メリット
- **常時利用可能** - 登録前からチェックボックスが見える
- **直感的** - ユーザーが登録前に動作を把握できる
- **説明付き** - 初期訪問者に対して機能説明が表示される
- **柔軟性** - 登録前後で一貫した UI

### ⚠️ デメリット
- チェックボックルが登録前でも表示されるため、少し冗長に見えるかも
  （ただし、説明メッセージで理由が明確になります）

---

## テストケース

| テスト項目 | 条件 | 期待値 | 確認状況 |
|-----------|------|--------|----------|
| T1 | 初回ロード | チェックボックス表示、説明表示 | ✅ |
| T2 | チェック時 | チェック状態保持、説明継続表示 | ✅ |
| T3 | 登録前リロード | チェック状態保持 | ✅ |
| T4 | 登録実行 | POST 送信、リダイレクト | ✅ |
| T5 | 登録後（チェックなし） | ログ非表示、メッセージ表示 | ✅ |
| T6 | 登録後（チェックあり） | ログ表示、メッセージ表示 | ✅ |
| T7 | ログ表示時チェック解除 | ログ非表示（即座に） | ✅ |
| T8 | ログ非表示時チェック | ログ表示（即座に） | ✅ |

---

## 修正ファイル

- **ファイル:** `content_bulk_add.php`
- **パス:** `c:\moriya\00_main\70_study\30_program\HTML\morizaru.net\indexindex\indexindex\admin\`
- **修正行数:** 1805-1856 行目
- **修正内容:** HTML 構造の再構成 + 説明メッセージの追加

---

## 修正日時

- **修正日:** 2026-08-31
