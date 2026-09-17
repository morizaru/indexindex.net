document.addEventListener('DOMContentLoaded', () => {
    // Registered after the existing draft restoration, so restored JSON is the source of truth.
    document.querySelectorAll('.bulk-creator-details').forEach(details => {
        const cell = details.parentElement;
        const name = cell.querySelector('input[name$="[creator_name]"]');
        const json = cell.querySelector('input[name$="[creators_json]"]');
        const list = details.querySelector('.bulk-creator-list');
        const status = details.querySelector('.bulk-creator-status');
        const add = details.querySelector('.bulk-creator-add');
        let active = false, loading = false;
        function sync() {
            const people = Array.from(list.children).map(row => {
                const person = {};
                row.querySelectorAll('input').forEach(input => { person[input.dataset.field] = input.value; });
                return person;
            });
            json.value = JSON.stringify(people);
            name.value = people.filter(person => person.name.trim()).map(person => (person.role ? person.role + '：' : '') + person.name).join('、');
            name.readOnly = true;
            active = true;
            // Existing localStorage draft handler persists this JSON too.
            json.dispatchEvent(new Event('input', {bubbles: true}));
        }
        function append(person = {}) {
            const row = document.createElement('div');
            row.className = 'bulk-person-row';
            Object.entries({role: '役割', name: '人物名', display_name: '表示名', display_title: '肩書', display_affiliation: '所属'}).forEach(([field, label]) => {
                const input = document.createElement('input');
                input.type = 'text'; input.dataset.field = field;
                input.value = person[field] ?? (field === 'role' ? 'author' : '');
                input.placeholder = label; input.setAttribute('aria-label', label);
                input.maxLength = field === 'role' ? 50 : field === 'name' ? 150 : 255;
                input.addEventListener('input', sync);
                row.appendChild(input);
            });
            const remove = document.createElement('button');
            remove.type = 'button'; remove.textContent = '削除';
            remove.addEventListener('click', () => { row.remove(); sync(); });
            row.appendChild(remove); list.appendChild(row);
        }
        async function initialize() {
            if (active || loading) return;
            loading = true; add.disabled = true; status.textContent = '';
            const original = name.value;
            try {
                let people = JSON.parse(json.value || '[]');
                if (!Array.isArray(people)) throw new Error();
                if (!people.length && original.trim()) {
                    const response = await fetch('content_bulk_add.php?action=parse_creator_input&name=' + encodeURIComponent(original));
                    if (!response.ok) throw new Error();
                    people = (await response.json()).creators;
                    if (!Array.isArray(people)) throw new Error();
                }
                if (name.value !== original) throw new Error();
                list.replaceChildren(); people.forEach(append); sync();
                const suggestions = cell.querySelector('.suggest-list');
                if (suggestions) { suggestions.replaceChildren(); suggestions.classList.remove('is-open'); }
            } catch (_) {
                status.textContent = '人物情報を読み込めませんでした。閉じてから再度開いてください。';
            } finally { loading = false; add.disabled = false; }
        }
        // Imported or restored structured data must not be overwritten by the summary input.
        try {
            const people = JSON.parse(json.value || '[]');
            if (Array.isArray(people) && people.length) {
                people.forEach(append); name.readOnly = true; active = true;
            }
        } catch (_) { /* initialize() reports an error when opened. */ }
        details.addEventListener('toggle', () => { if (details.open) initialize(); });
        add.addEventListener('click', async () => {
            await initialize(); if (!active) return;
            append(); sync(); list.lastElementChild.querySelector('[data-field="name"]').focus();
        });
    });
});
