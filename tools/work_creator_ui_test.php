<?php
// Generate a standalone HTML fixture: php tools/work_creator_ui_test.php > fixture.html
// Open with a browser. No DB access; only candidate responses are stubbed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/work_creators.php';
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$creator_rows = [
    ['id'=>10, 'creator_id'=>2425, 'creator_name'=>'鳥山明', 'role'=>'author', 'display_order'=>1, 'default_visible'=>0],
    ['id'=>11, 'creator_id'=>42, 'creator_name'=>'人物二', 'role'=>'キャラクター原案', 'display_order'=>7, 'default_visible'=>1],
    ['id'=>12, 'creator_id'=>43, 'creator_name'=>'削除済み', 'role'=>'監修', 'display_order'=>99, 'default_visible'=>0, 'delete'=>'1'],
];
$creators_fingerprint = WorkCreatorEditor::fingerprint($creator_rows);
preg_match('~<style>(.*?)</style>~s', file_get_contents(__DIR__ . '/../admin/work_edit.php'), $styles);
?>
<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style><?= $styles[1] ?></style></head><body><div class="container"><form id="testForm">
<script>window.fetch = async () => ({ok: true, json: async () => [{id: 123, name: '候補人物'}]});</script>
<?php require __DIR__ . '/../admin/work_creator_fields.php'; ?>
</form><pre id="result">RUNNING</pre></div>
<script>
(async () => {
    const result = document.getElementById('result');
    let checks = 0;
    const check = (ok, message) => { if (!ok) throw new Error(message); ++checks; };
    const form = document.getElementById('testForm');
    const list = document.getElementById('workCreatorRows');
    const get = (index, field) => new FormData(form).getAll(`work_creators[${index}][${field}]`).pop();
    try {
        const first = list.children[0], second = list.children[1], deleted = list.children[2];
        check(!first.querySelector('[type="checkbox"]').checked && second.querySelector('[type="checkbox"]').checked, 'Existing visibility');
        check(deleted.hidden && get(2, 'id') === '12' && get(2, 'delete') === '1', 'Deleted row survives error re-render');
        check(get(0, 'default_visible') === '0' && get(1, 'default_visible') === '1', 'Checkbox POST values');
        const visible = first.querySelector('.creator-visible').getBoundingClientRect();
        const role = first.querySelector('[name$="[role]"]').getBoundingClientRect();
        const person = first.querySelector('.creator-name').getBoundingClientRect();
        const order = first.querySelector('.creator-order').getBoundingClientRect();
        const remove = first.querySelector('.creator-remove').getBoundingClientRect();
        if (window.innerWidth > 700) {
            check(visible.left < role.left && role.left < person.left && person.left < order.left && order.left < remove.left, 'Desktop column order');
            check(Math.abs(role.top - person.top) < 1 && Math.abs(role.top - order.top) < 1 && Math.abs(role.top - remove.top) < 1, 'Desktop one line');
            check(Math.abs(order.width - 90) < 1 && person.width > role.width, 'Input widths');
        } else {
            check(person.top > role.top, 'Mobile wrap');
        }
        check(document.documentElement.scrollWidth <= window.innerWidth, 'No horizontal overflow');
        document.getElementById('addWorkCreator').click();
        const added = list.lastElementChild;
        check(get(3, 'role') === 'author' && get(3, 'display_order') === '8' && get(3, 'default_visible') === '1', 'New defaults and next active order');
        const checkbox = added.querySelector('[type="checkbox"]');
        checkbox.checked = false; checkbox.dispatchEvent(new Event('change'));
        const roleInput = added.querySelector('[name$="[role]"]');
        roleInput.value = '原作'; roleInput.dispatchEvent(new Event('input')); roleInput.dispatchEvent(new Event('change'));
        check(get(3, 'default_visible') === '0', 'Role change preserves manual OFF');
        const name = added.querySelector('.creator-name');
        name.value = '候補'; name.dispatchEvent(new Event('input'));
        await new Promise(resolve => setTimeout(resolve, 400));
        added.querySelector('.creator-candidates button').click();
        check(get(3, 'creator_id') === '123' && get(3, 'creator_name') === '候補人物', 'Candidate selection preserves internal ID');
        check(added.querySelector('.creator-selection').textContent === 'ID: 123', 'Small ID display');
        name.value = '別人'; name.dispatchEvent(new Event('input'));
        check(get(3, 'creator_id') === '0', 'Typing clears selected ID');
        first.querySelector('.creator-remove').click();
        check(first.hidden && get(0, 'id') === '10' && get(0, 'delete') === '1', 'Existing deletion retains ID and flag');
        check(get(0, 'creator_name') === undefined && first.querySelector('.creator-order').disabled, 'Deleted controls excluded from validation');
        added.querySelector('.creator-remove').click();
        check(get(3, 'id') === '0' && get(3, 'delete') === '1', 'Unsaved row removed safely');
        document.getElementById('addWorkCreator').click();
        check(get(4, 'display_order') === '8', 'Next order ignores deleted rows');
        result.textContent = `PASS ${checks} checks; viewport ${window.innerWidth}`;
        result.dataset.status = 'passed';
    } catch (error) {
        result.textContent = 'FAIL ' + error.message;
        result.dataset.status = 'failed';
    }
})();
</script></body></html>
