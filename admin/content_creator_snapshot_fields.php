<?php foreach (['display_name' => '表示名', 'display_title' => '肩書', 'display_affiliation' => '所属'] as $field => $label): ?>
    <input type="text" class="creator-snapshot <?= h($field) ?>" maxlength="255"
           name="creators[<?= h($index) ?>][<?= h($field) ?>]"
           value="<?= h($creator_row[$field] ?? '') ?>" placeholder="<?= h($label) ?>" aria-label="<?= h($label) ?>">
<?php endforeach; ?>
