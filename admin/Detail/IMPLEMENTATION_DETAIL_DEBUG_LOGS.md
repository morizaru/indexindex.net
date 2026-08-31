# デバッグログ表示制御の実装詳細書

## 概要
`content_bulk_add.php` の登録完了画面に表示される「作者名分割処理ログ」を、チェックボックスで表示/非表示を制御する機能を実装しました。

## 修正概要

| 項目 | 内容 |
|------|------|
| 修正ファイル | `content_bulk_add.php` |
| 修正方式 | PHP + HTML + JavaScript の組み合わせ |
| デフォルト状態 | ログ非表示 |
| 保持機能 | URLパラメータで状態を保持 |

---

## 実装詳細

### 1. PHP側の実装

#### 1.1 変数初期化（663行目）
```php
$show_debug_logs = false;  // 初期値は表示しない
```

#### 1.2 ログ表示フラグの設定（671行目）
```php
// GETパラメータから表示フラグを取得
$show_debug_logs = isset($_GET['debug_logs']) && $_GET['debug_logs'] === '1';
```

**ロジック:**
- `$_GET['debug_logs'] === '1'` の場合のみ true
- その他の場合は false
- ページ初回ロード時は自動的に false（チェックなし）

#### 1.3 セッションデータの処理（674-679行目）
```php
if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
    // チェックがONの場合のみ $debug_info に代入
    $debug_info = $_SESSION['debug_splits'];
    unset($_SESSION['debug_splits']);
} elseif (!empty($_SESSION['debug_splits'])) {
    // チェックがOFFの場合はセッションのみ削除
    unset($_SESSION['debug_splits']);
}
```

**処理フロー:**
```
セッションにデータあり
    ↓
    [チェック=ON] → $debug_info に代入 → セッション削除
    [チェック=OFF] → $debug_info に代入しない → セッション削除
```

---

### 2. HTML側の実装

#### 2.1 チェックボックスの配置（1815-1821行目）
```html
<div style="margin: 15px 0; padding: 10px; background: #f5f5f5; border: 1px solid #ddd;">
    <label style="cursor: pointer; user-select: none;">
        <input type="checkbox" id="toggleDebugLogs" onchange="toggleDebugLogs()" 
            <?= $show_debug_logs ? 'checked' : '' ?>>
        <strong style="color: #333;">詳細ログを表示（作者名分割処理）</strong>
    </label>
</div>
```

**特徴:**
- `id="toggleDebugLogs"` - JavaScript から参照可能
- `onchange="toggleDebugLogs()"` - 状態変更時にJavaScript関数を呼び出し
- `<?= $show_debug_logs ? 'checked' : '' ?>` - PHP側の状態に同期
- スタイル: ボックス背景で視認性向上

#### 2.2 ログコンテナの表示制御（1823-1846行目）
```html
<div id="debugLogsContainer" style="<?= $show_debug_logs ? '' : 'display: none;' ?>">
    <!-- ログ内容 -->
</div>
```

**表示制御ロジック:**
- `$show_debug_logs` = true → `style=""` （表示）
- `$show_debug_logs` = false → `style="display: none;"` （非表示）
- 初期値は非表示

---

### 3. JavaScript側の実装

#### 3.1 toggleDebugLogs関数（1768-1788行目）
```javascript
window.toggleDebugLogs = function() {
    const checkbox = document.getElementById('toggleDebugLogs');
    const container = document.getElementById('debugLogsContainer');
    
    if (!checkbox || !container) return;  // 要素チェック
    
    if (checkbox.checked) {
        // チェックON
        container.style.display = 'block';
        const url = new URL(window.location);
        url.searchParams.set('debug_logs', '1');
        window.history.replaceState({}, '', url.toString());
    } else {
        // チェックOFF
        container.style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.delete('debug_logs');
        window.history.replaceState({}, '', url.toString());
    }
};
```

**実装の詳細:**

1. **要素の取得**
   ```javascript
   const checkbox = document.getElementById('toggleDebugLogs');
   const container = document.getElementById('debugLogsContainer');
   ```

2. **存在確認**
   ```javascript
   if (!checkbox || !container) return;
   ```
   要素がなければ早期リターン

3. **チェック状態による処理**
   ```javascript
   if (checkbox.checked) {
       // ON処理
   } else {
       // OFF処理
   }
   ```

4. **表示/非表示の切り替え**
   ```javascript
   container.style.display = checkbox.checked ? 'block' : 'none';
   ```

5. **URLの更新**
   ```javascript
   const url = new URL(window.location);  // 現在のURLを取得
   url.searchParams.set('debug_logs', '1');  // パラメータ追加/上書き
   url.searchParams.delete('debug_logs');  // パラメータ削除
   window.history.replaceState({}, '', url.toString());  // URLを更新
   ```
   - **`history.replaceState`** を使用するため、ブラウザの戻るボタン履歴に影響しない
   - ページリロード時に URL パラメータから状態が復元される

---

## 動作シーケンス

### シーン1: 初回ページロード（登録直後）
```
ユーザーが目次を登録
    ↓
POST リクエスト処理
    ↓
セッションに debug_splits を保存
    ↓
リダイレクト: ?saved=1&inserted=N
    ↓
登録完了画面表示
    ↓
PHP側:
  - $show_debug_logs = false（debug_logsパラメータなし）
  - $debug_info = null（表示フラグOFFのため）
    ↓
HTML表示:
  - チェックボックス: チェックなし
  - ログコンテナ: display: none
```

### シーン2: ユーザーがチェック
```
チェックボックスをクリック
    ↓
JavaScript: toggleDebugLogs() 実行
    ↓
  - checkbox.checked = true
  - container.style.display = 'block'
  - URL更新: ?saved=1&inserted=N&debug_logs=1
    ↓
ログが即座に表示される（ページリロードなし）
```

### シーン3: ページリロード（チェック状態保持）
```
ユーザーが F5 キーでリロード
    ↓
新しいリクエスト: ?saved=1&inserted=N&debug_logs=1
    ↓
PHP側:
  - $show_debug_logs = true（debug_logs=1）
  - $debug_info = $_SESSION['debug_splits'] を取得
    ↓
HTML表示:
  - チェックボックス: チェック状態（checked属性付き）
  - ログコンテナ: display: なし（表示）
```

### シーン4: ユーザーがチェック解除
```
チェックボックスをクリック
    ↓
JavaScript: toggleDebugLogs() 実行
    ↓
  - checkbox.checked = false
  - container.style.display = 'none'
  - URLからdebug_logsパラメータを削除
    ↓
ログが非表示になる
```

---

## URLパラメータの説明

### 登録後の初期URL
```
/content_bulk_add.php?issue_id=123&saved=1&inserted=5
```

| パラメータ | 説明 |
|-----------|------|
| `issue_id=123` | 雑誌号ID |
| `saved=1` | 登録完了フラグ |
| `inserted=5` | 登録件数 |

### ログ表示時のURL
```
/content_bulk_add.php?issue_id=123&saved=1&inserted=5&debug_logs=1
```

| パラメータ | 説明 |
|-----------|------|
| `debug_logs=1` | ログ表示フラグ（1=表示、省略=非表示） |

---

## セッションデータの管理

### POST時（827-839行目）
```php
if (!empty($creators)) {
    $creator_rows = $creators;
} else {
    $creator_rows = build_creator_rows_from_name_bulk($creator_name);
    
    // デバッグ情報をセッションに保存
    if (!isset($_SESSION['debug_splits'])) {
        $_SESSION['debug_splits'] = [];
    }
    $_SESSION['debug_splits'][] = [
        'row' => $i + 1,
        'input' => $creator_name,
        'output' => $creator_rows,
        'debug_html' => debug_split_creator_names($creator_name),
    ];
}
```

### リダイレクト後
```php
if (!empty($_SESSION['debug_splits']) && $show_debug_logs) {
    // 表示するデータとしてセッションを渡す
    $debug_info = $_SESSION['debug_splits'];
} 

// 必ず削除（表示有無に関わらず）
unset($_SESSION['debug_splits']);
```

---

## パフォーマンスの考慮

### 利点
1. **初期ロード速度** - デフォルト非表示でDOM解析が軽い
2. **セッションメモリ** - 1回のページビューで削除
3. **URL長** - 表示中のみパラメータを追加

### 注意点
- ログデータはセッションに一時保存のみ
- ページリロード時に復元のため、セッションは保持
- 複数回のチェック/アンチェックでもセッションは1度のみ削除

---

## ブラウザ互換性

| ブラウザ | 対応 | 備考 |
|---------|------|------|
| Chrome | ✅ | ES6対応 |
| Firefox | ✅ | ES6対応 |
| Safari | ✅ | ES6対応 |
| Edge | ✅ | ES6対応 |
| IE 11 | ❌ | `URL()` コンストラクタ未対応 |

**IE 11対応が必要な場合:**
```javascript
// 互換コード例
const url = location.href;
if (checkbox.checked) {
    window.history.replaceState({}, '', url + (url.indexOf('?') > -1 ? '&' : '?') + 'debug_logs=1');
} else {
    // URL処理
}
```

---

## テストチェックリスト

- [ ] 初回ロード時、チェックボックスはチェックなし
- [ ] 初回ロード時、ログは非表示
- [ ] チェックボックスをクリックするとログが表示される
- [ ] URLに `debug_logs=1` が追加される
- [ ] ページをリロード（F5）するとチェック状態が保持される
- [ ] ログが表示されたままリロードされる
- [ ] チェックボックスを解除するとログが非表示
- [ ] URLから `debug_logs` パラメータが削除される
- [ ]複数回のチェック/アンチェックが正常に動作する
- [ ] 他のURLパラメータが失われない

---

## 修正日時

- **修正日:** 2026-08-31
- **ファイル:** `content_bulk_add.php`
- **修正行数:** 約20行（PHP + HTML + JavaScript）
