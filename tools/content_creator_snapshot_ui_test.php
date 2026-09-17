<?php
// CLI-only browser fixture built from the real form fragments and scripts; no DB/config.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? 'edit';
if (!in_array($mode, ['add','edit','bulk'], true)) { exit('Invalid mode'); }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$file = __DIR__ . '/../admin/content_' . ($mode === 'bulk' ? 'bulk_add' : $mode) . '.php';
$source = file_get_contents($file);
$creator_revision = 'fixture';
$creator_rows = [[ 'id'=>1, 'role'=>'監修', 'creator_name'=>'梅沢由香里', 'display_name'=>'YUKARI UMEZAWA',
    'display_title'=>'二段', 'display_affiliation'=>'日本棋院', 'display_order'=>1, 'is_visible'=>0 ]];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['saved'=>'1'];
preg_match('~<style>(.*?)</style>~s', $source, $style);
preg_match('~<script>(.*?)</script>~s', $source, $script);
if ($mode !== 'bulk') {
    $start = strpos($source, '<div class="creator-list" id="creatorList">');
    $end = strpos($source, '<div class="form-row">', $start);
    $fragment = substr($source, $start, $end-$start);
    $fragment = str_replace("__DIR__ . '/content_creator_snapshot_fields.php'", var_export(__DIR__ . '/../admin/content_creator_snapshot_fields.php', true), $fragment);
} else {
    $position = strpos($source, 'data-suggest-type="creator"');
    $start = strrpos(substr($source, 0, $position), '<td>');
    $end = strpos($source, '</td>', $position) + strlen('</td>');
    $fragment = substr($source, $start, $end-$start);
    $i = 0; $rows_input = [['creator_name'=>'監修：梅沢由香里']]; $creators_json = '[]';
}
?>
<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style><?= file_get_contents(__DIR__ . '/../assets/admin.css') ?><?= $style[1] ?>
<?= file_get_contents(__DIR__ . '/../admin/' . ($mode === 'bulk' ? 'content_bulk_creator_fields.css' : 'content_creator_fields.css')) ?></style>
<script>window.fetch = async () => ({ok:true,json:async()=>({creators:[{name:'梅沢由香里',role:'監修'}]})});</script>
<script><?php eval('?>' . $script[1]); ?></script>
<?php if ($mode === 'bulk'): ?><script><?= file_get_contents(__DIR__ . '/../admin/content_bulk_creator_fields.js') ?></script><?php endif; ?>
</head><body><div class="container"><form class="js-content-bulk-form" id="fixture">
<?php if ($mode === 'bulk'): ?><select name="issue_id"><option value="1">fixture</option></select><table class="bulk-table"><tbody><tr><?php endif; ?>
<?php eval('?>' . $fragment); ?>
<?php if ($mode === 'bulk'): ?></tr></tbody></table><?php endif; ?>
</form><pre id="result">RUNNING</pre></div>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const result = document.getElementById('result'); let count = 0;
    const check = (ok, label) => { if (!ok) throw new Error(label); count++; };
    const inputEvent = input => input.dispatchEvent(new Event('input', {bubbles:true}));
    const mode = <?= json_encode($mode) ?>;
    try {
        if (mode !== 'bulk') {
            const form = document.getElementById('fixture');
            const value = (index, field) => new FormData(form).getAll(`creators[${index}][${field}]`).pop();
            check(value(0,'display_name') === 'YUKARI UMEZAWA', 'Existing name');
            check(value(0,'display_title') === '二段' && value(0,'display_affiliation') === '日本棋院', 'Existing title/affiliation');
            check(value(0,'is_visible') === '0', 'Existing visibility');
            const first = document.querySelector('.creator-row');
            const fields = Array.from(first.querySelectorAll('input:not([type="hidden"])')).map(input=>input.name.match(/\[([^\]]+)\]$/)[1]);
            check(fields.join(',') === 'is_visible,role,creator_name,display_name,display_title,display_affiliation,display_order', 'Field order');
            check(first.scrollWidth <= first.clientWidth + 1, 'Row has no horizontal overflow');
            if (innerWidth > 1100) {
                const tops = Array.from(first.querySelectorAll('input[type="text"], input[type="number"]')).map(input=>input.getBoundingClientRect().top);
                check(Math.max(...tops)-Math.min(...tops) < 1, 'Desktop one-line fields');
            }
            document.getElementById('addCreatorButton').click();
            check(value(1,'display_name') === '' && value(1,'display_title') === '' && value(1,'display_affiliation') === '', 'New metadata blank');
            const added = document.querySelectorAll('.creator-row')[1];
            added.querySelector('[name$="[creator_name]"]').value = '高橋留美子';
            added.querySelector('[name$="[display_name]"]').value = 'RUMIKO TAKAHASHI';
            check(value(1,'creator_name') === '高橋留美子' && value(1,'display_name') === 'RUMIKO TAKAHASHI', 'Independent values posted');
            added.querySelector('.creator-remove').click();
            check(document.querySelectorAll('.creator-row').length === 1 && value(0,'display_title') === '二段', 'Removal preserves other row');
        } else {
            const details = document.querySelector('.bulk-creator-details');
            const json = document.querySelector('input[name$="[creators_json]"]');
            const summary = document.querySelector('input[name$="[creator_name]"]');
            check(!details.open, 'Details collapsed initially');
            details.open = true; await new Promise(resolve=>setTimeout(resolve,100));
            const row = document.querySelector('.bulk-person-row');
            check(row.querySelector('[data-field="name"]').value === '梅沢由香里', 'Existing parser result used');
            check(summary.readOnly, 'Summary protected from overwriting details');
            for (const [field,value] of Object.entries({display_name:'YUKARI UMEZAWA',display_title:'二段',display_affiliation:'日本棋院'})) {
                const input = row.querySelector(`[data-field="${field}"]`); input.value = value; inputEvent(input);
            }
            const person = JSON.parse(json.value)[0];
            check(person.name === '梅沢由香里' && person.display_name === 'YUKARI UMEZAWA' && person.display_title === '二段' && person.display_affiliation === '日本棋院', 'All independent fields serialized');
            check(person.role === '監修', 'Role retained');
            await new Promise(resolve=>setTimeout(resolve,350));
            const draft = JSON.parse(localStorage.getItem('indexindex:content_bulk_add:1'));
            check(draft['rows[0][creators_json]'] === json.value, 'Existing draft writer saves metadata JSON');
            details.open = false; details.open = true; await new Promise(resolve=>setTimeout(resolve,100));
            check(JSON.parse(json.value)[0].display_title === '二段', 'Toggle retains metadata');
            details.querySelector('.bulk-creator-add').click(); await new Promise(resolve=>setTimeout(resolve,50));
            check(JSON.parse(json.value).length === 2 && JSON.parse(json.value)[1].display_name === '', 'Add blank person');
            document.querySelectorAll('.bulk-person-row')[1].querySelector('button').click();
            check(JSON.parse(json.value).length === 1 && JSON.parse(json.value)[0].display_name === person.display_name, 'Delete only chosen person');
        }
        result.dataset.status = 'passed'; result.textContent = `PASS ${mode}: ${count} checks; viewport ${innerWidth}`;
    } catch (error) { result.dataset.status = 'failed'; result.textContent = 'FAIL ' + mode + ': ' + error.message; }
});
</script></body></html>
