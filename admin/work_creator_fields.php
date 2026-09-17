<?php // Included within the work edit form. ?>
<h2>人物</h2>
<p class="help">作品の標準人物・役割です。過去の掲載人物には反映しません。名前または別名で検索し、候補を選択してください。<br>
未登録の場合は <a href="creator_add.php" target="_blank" rel="noopener">人物を新規登録</a> してから検索してください。自動作成はしません。</p>
<input type="hidden" name="creators_fingerprint" value="<?= h($creators_fingerprint) ?>">
<input type="hidden" name="creators_present" value="1">
<div id="workCreatorRows">
<?php foreach ($creator_rows as $index => $row): ?>
    <div class="work-creator-row">
        <input type="hidden" name="work_creators[<?= h($index) ?>][id]" value="<?= h($row['id'] ?? 0) ?>">
        <input class="creator-id" type="hidden" name="work_creators[<?= h($index) ?>][creator_id]" value="<?= h($row['creator_id'] ?? 0) ?>">
        <label>人物名 <input class="creator-name" type="text" autocomplete="off" name="work_creators[<?= h($index) ?>][creator_name]" value="<?= h($row['creator_name'] ?? '') ?>"></label>
        <div class="creator-candidates" aria-live="polite"></div>
        <span class="creator-selection help"><?= !empty($row['creator_id']) ? '人物ID: ' . h($row['creator_id']) : '' ?></span>
        <label>役割 <input type="text" maxlength="50" name="work_creators[<?= h($index) ?>][role]" value="<?= h($row['role'] ?? 'author') ?>"></label>
        <label>表示順 <input type="number" required min="-2147483648" max="2147483647" name="work_creators[<?= h($index) ?>][display_order]" value="<?= h($row['display_order'] ?? 1) ?>"></label>
        <label><input class="creator-delete" type="checkbox" name="work_creators[<?= h($index) ?>][delete]" value="1" <?= !empty($row['delete']) ? 'checked' : '' ?>> この人物行を削除</label>
    </div>
<?php endforeach; ?>
</div>
<button type="button" id="addWorkCreator">人物を追加</button>
<p class="help">役割の表記はそのまま保存します。人物名を空にするだけでは削除しません。</p>
<template id="workCreatorTemplate">
    <div class="work-creator-row">
        <input type="hidden" data-field="id" value="0">
        <input class="creator-id" type="hidden" data-field="creator_id" value="0">
        <label>人物名 <input class="creator-name" type="text" autocomplete="off" data-field="creator_name"></label>
        <div class="creator-candidates" aria-live="polite"></div>
        <span class="creator-selection help"></span>
        <label>役割 <input type="text" maxlength="50" data-field="role" value="author"></label>
        <label>表示順 <input type="number" required min="-2147483648" max="2147483647" data-field="display_order" value="1"></label>
        <label><input class="creator-delete" type="checkbox" data-field="delete" value="1"> この人物行を削除</label>
    </div>
</template>
<style>
    .work-creator-row { border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 12px; }
    .work-creator-row label { margin-top: 8px; }
    .work-creator-row input[type="number"] { padding: 8px; width: 140px; }
    .creator-candidates button { display: block; background: #f3f3f3; color: #0066cc; border: 1px solid #ccc; margin-top: 4px; text-align: left; }
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
            row.querySelector('input[type="number"]').required = !deleted.checked;
        }
        deleted.addEventListener('change', setDeleted);
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
                            selection.textContent = '人物ID: ' + person.id;
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
    rows.querySelectorAll('.work-creator-row').forEach(attach);
    document.getElementById('addWorkCreator').addEventListener('click', () => {
        const fragment = document.getElementById('workCreatorTemplate').content.cloneNode(true);
        const row = fragment.querySelector('.work-creator-row');
        row.querySelectorAll('[data-field]').forEach(input => {
            input.name = 'work_creators[' + nextIndex + '][' + input.dataset.field + ']';
        });
        ++nextIndex; attach(row); rows.appendChild(fragment); row.querySelector('.creator-name').focus();
    });
})();
</script>
