<?php // Included within the work edit form. ?>
<h2>人物</h2>
<p class="help">作品の標準人物・役割です。過去の掲載人物には反映しません。名前または別名で検索し、候補を選択してください。<br>
未登録の場合は <a href="creator_add.php" target="_blank" rel="noopener">人物を新規登録</a> してから検索してください。自動作成はしません。</p>
<input type="hidden" name="creators_fingerprint" value="<?= h($creators_fingerprint) ?>">
<input type="hidden" name="creators_present" value="1">
<div class="creator-list" id="workCreatorRows">
<?php foreach ($creator_rows as $index => $row): ?>
    <div class="creator-row" <?= !empty($row['delete']) ? 'hidden' : '' ?>>
        <input type="hidden" name="work_creators[<?= h($index) ?>][id]" value="<?= h($row['id'] ?? 0) ?>">
        <input class="creator-id" type="hidden" name="work_creators[<?= h($index) ?>][creator_id]" value="<?= h($row['creator_id'] ?? 0) ?>">
        <input class="creator-delete" type="hidden" name="work_creators[<?= h($index) ?>][delete]" value="<?= !empty($row['delete']) ? '1' : '0' ?>">
        <label class="creator-visible">
            <input type="hidden" name="work_creators[<?= h($index) ?>][default_visible]" value="0">
            <input type="checkbox" name="work_creators[<?= h($index) ?>][default_visible]" value="1" <?= (int)($row['default_visible'] ?? WorkCreatorEditor::defaultVisible($row['role'] ?? 'author')) === 1 ? 'checked' : '' ?>> 表示
        </label>
        <input type="text" maxlength="50" aria-label="役割" placeholder="役割" name="work_creators[<?= h($index) ?>][role]" value="<?= h($row['role'] ?? 'author') ?>">
        <div class="creator-person">
            <input class="creator-name" type="text" aria-label="人物名" placeholder="人物名" autocomplete="off" name="work_creators[<?= h($index) ?>][creator_name]" value="<?= h($row['creator_name'] ?? '') ?>">
            <div class="creator-candidates" aria-live="polite"></div>
            <span class="creator-selection"><?= !empty($row['creator_id']) ? 'ID: ' . h($row['creator_id']) : '' ?></span>
        </div>
        <input class="creator-order" type="number" aria-label="表示順" placeholder="表示順" required min="-2147483648" max="2147483647" name="work_creators[<?= h($index) ?>][display_order]" value="<?= h($row['display_order'] ?? 1) ?>">
        <button type="button" class="creator-remove">削除</button>
    </div>
<?php endforeach; ?>
</div>
<button type="button" class="creator-add" id="addWorkCreator">＋ 人物を追加</button>
<p class="help">表示チェックは目次へ展開する際の表示初期値です。役割を変更してもチェック状態を優先して保存します。<br>役割の表記はそのまま保存します。削除ボタンで外した人物行は、更新時に削除します。</p>
<template id="workCreatorTemplate">
    <div class="creator-row">
        <input type="hidden" data-field="id" value="0">
        <input class="creator-id" type="hidden" data-field="creator_id" value="0">
        <input class="creator-delete" type="hidden" data-field="delete" value="0">
        <label class="creator-visible">
            <input type="hidden" data-field="default_visible" value="0">
            <input type="checkbox" data-field="default_visible" value="1" checked> 表示
        </label>
        <input type="text" maxlength="50" aria-label="役割" placeholder="役割" data-field="role" value="author">
        <div class="creator-person">
            <input class="creator-name" type="text" aria-label="人物名" placeholder="人物名" autocomplete="off" data-field="creator_name">
            <div class="creator-candidates" aria-live="polite"></div>
            <span class="creator-selection"></span>
        </div>
        <input class="creator-order" type="number" aria-label="表示順" placeholder="表示順" required min="-2147483648" max="2147483647" data-field="display_order" value="1">
        <button type="button" class="creator-remove">削除</button>
    </div>
</template>
<style>
    /* Match content_edit.php's creator-list / creator-row grid and control sizes. */
    #workCreatorRows { display: grid; gap: 8px; }
    #workCreatorRows .creator-row { display: grid; grid-template-columns: auto minmax(0, 1fr) minmax(0, 2fr) 90px auto; gap: 8px; align-items: start; }
    #workCreatorRows .creator-row[hidden] { display: none; }
    #workCreatorRows input { margin: 0; min-width: 0; }
    #workCreatorRows .creator-visible { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; font-weight: bold; margin: 0; padding-top: 10px; }
    #workCreatorRows .creator-visible input { width: auto; }
    #workCreatorRows .creator-order { width: 100%; box-sizing: border-box; padding: 10px; font-size: 16px; border: 1px solid #ccc; border-radius: 4px; }
    #workCreatorRows .creator-remove { padding: 8px 12px; white-space: nowrap; }
    #addWorkCreator { margin-top: 10px; }
    #workCreatorRows .creator-person { min-width: 0; position: relative; }
    #workCreatorRows .creator-selection { display: block; font-size: 12px; color: #666; line-height: 1.4; }
    #workCreatorRows .creator-selection:empty { display: none; }
    #workCreatorRows .creator-candidates:not(:empty) { position: absolute; z-index: 10; top: 44px; left: 0; right: 0; max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #ccc; padding: 4px; }
    #workCreatorRows .creator-candidates button { display: block; width: 100%; background: #f3f3f3; color: #0066cc; border: 1px solid #ccc; margin-top: 4px; padding: 8px; text-align: left; overflow-wrap: anywhere; }
    @media (max-width: 700px) {
        #workCreatorRows .creator-row { grid-template-columns: auto minmax(0, 1fr) 90px auto; padding: 10px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
        #workCreatorRows .creator-person { grid-column: 1 / -1; grid-row: 2; }
    }
    @media (max-width: 400px) {
        #workCreatorRows .creator-row { grid-template-columns: auto minmax(0, 1fr); }
        #workCreatorRows .creator-order { grid-column: 1; width: 90px; }
        #workCreatorRows .creator-remove { justify-self: end; }
    }
</style>
<script>
(() => {
    const rows = document.getElementById('workCreatorRows');
    let nextIndex = <?= (int)count($creator_rows) ?>;
    function attach(row) {
        const input = row.querySelector('.creator-name');
        const id = row.querySelector('.creator-id');
        const candidates = row.querySelector('.creator-candidates');
        const selection = row.querySelector('.creator-selection');
        const deleted = row.querySelector('.creator-delete');
        let timer, version = 0;
        function setDeleted() {
            row.hidden = deleted.value === '1';
            row.querySelectorAll('input:not([type="hidden"])').forEach(field => { field.disabled = row.hidden; });
        }
        row.querySelector('.creator-remove').addEventListener('click', () => {
            clearTimeout(timer); ++version;
            deleted.value = '1'; candidates.replaceChildren(); setDeleted();
        });
        setDeleted();
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const requestVersion = ++version;
            id.value = '0'; selection.textContent = ''; candidates.replaceChildren();
            const q = input.value.trim();
            if (!q) return;
            timer = setTimeout(async () => {
                try {
                    const response = await fetch('work_creator_suggest.php?q=' + encodeURIComponent(q));
                    if (!response.ok) throw new Error();
                    const data = await response.json();
                    if (requestVersion !== version) return;
                    candidates.replaceChildren();
                    if (!data.length) candidates.textContent = '候補がありません。未登録の場合は人物を新規登録してください。';
                    data.forEach(person => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = person.name + '（ID: ' + person.id + '）';
                        button.addEventListener('click', () => {
                            ++version; input.value = person.name; id.value = person.id;
                            selection.textContent = 'ID: ' + person.id;
                            candidates.replaceChildren();
                        });
                        candidates.appendChild(button);
                    });
                } catch (_) {
                    if (requestVersion === version) candidates.textContent = '候補の取得に失敗しました。入力し直してください。';
                }
            }, 250);
        });
    }
    rows.querySelectorAll('.creator-row').forEach(attach);
    document.getElementById('addWorkCreator').addEventListener('click', () => {
        const fragment = document.getElementById('workCreatorTemplate').content.cloneNode(true);
        const row = fragment.querySelector('.creator-row');
        const orders = Array.from(rows.querySelectorAll('.creator-row:not([hidden]) .creator-order'))
            .map(input => Number(input.value)).filter(Number.isInteger);
        row.querySelector('.creator-order').value = Math.min(2147483647, Math.max(0, ...orders) + 1);
        row.querySelectorAll('[data-field]').forEach(input => {
            input.name = 'work_creators[' + nextIndex + '][' + input.dataset.field + ']';
        });
        ++nextIndex; attach(row); rows.appendChild(fragment); row.querySelector('.creator-name').focus();
    });
})();
</script>
