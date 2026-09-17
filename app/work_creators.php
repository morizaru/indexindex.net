<?php

// Work-side editing only: never writes to content_creators.
class WorkCreatorEditor
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function rows($workId, $lock = false)
    {
        $stmt = $this->pdo->prepare('SELECT wc.*, c.name AS creator_name
            FROM work_creators wc LEFT JOIN creators c ON c.id = wc.creator_id
            WHERE wc.work_id = ? ORDER BY wc.display_order, wc.id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$workId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fingerprint(array $rows)
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function defaultVisible($role)
    {
        return in_array($role, ['author', '漫画', 'まんが', 'マンガ', '作画', '画', '原作', '作'], true) ? 1 : 0;
    }

    public function search($query)
    {
        $like = '%' . strtr($query, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
        $stmt = $this->pdo->prepare("SELECT id, name, MIN(priority) AS priority FROM (
            SELECT id, name, 0 AS priority FROM creators WHERE name LIKE ? ESCAPE '='
            UNION ALL
            SELECT c.id, c.name, 1 AS priority FROM creator_aliases a
            JOIN creators c ON c.id = a.creator_id WHERE a.alias_name LIKE ? ESCAPE '='
            ) candidates GROUP BY id, name ORDER BY priority, name, id LIMIT 20");
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveCreator($name, $selectedId, $old)
    {
        if ($old && $name === (string)$old['creator_name'] &&
            ($selectedId === 0 || $selectedId === (int)$old['creator_id'])) {
            return (int)$old['creator_id'];
        }
        if ($selectedId > 0) {
            $stmt = $this->pdo->prepare('SELECT name FROM creators WHERE id = ?');
            $stmt->execute([$selectedId]);
            if ($stmt->fetchColumn() !== $name) {
                throw new RuntimeException('人物の選択が変わっています。候補から選び直してください。');
            }
            return $selectedId;
        }
        // Same precedence as content_add/content_edit, but ambiguity and unknown names
        // require explicit selection/registration instead of silently creating a person.
        foreach (['SELECT id FROM creators WHERE name = ? ORDER BY id LIMIT 2',
            'SELECT DISTINCT c.id FROM creator_aliases a JOIN creators c ON c.id = a.creator_id
             WHERE a.alias_name = ? ORDER BY c.id LIMIT 2'] as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$name]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) > 1) {
                throw new RuntimeException('同名または同じ別名の人物が複数あります。候補のIDを確認して選択してください。');
            }
            if ($ids) { return (int)$ids[0]; }
        }
        throw new RuntimeException('未登録の人物です。「人物を新規登録」で登録してから選択してください。');
    }

    public function save($workId, array $posted, $fingerprint)
    {
        if (!$this->pdo->inTransaction()) { throw new LogicException('トランザクションが必要です。'); }
        $stmt = $this->pdo->prepare('SELECT id FROM works WHERE id = ? FOR UPDATE');
        $stmt->execute([$workId]);
        if (!$stmt->fetchColumn()) { throw new RuntimeException('作品が見つかりません。'); }
        $stored = $this->rows($workId, true);
        if (!hash_equals(self::fingerprint($stored), (string)$fingerprint)) {
            throw new RuntimeException('人物情報が他の操作で更新されています。再読み込みして編集し直してください。');
        }
        $byId = [];
        foreach ($stored as $row) { $byId[(int)$row['id']] = $row; }
        $seen = $deleted = $desired = [];
        foreach ($posted as $row) {
            if (!is_array($row)) { throw new RuntimeException('人物行が不正です。'); }
            $id = self::integer($row['id'] ?? '0', 0);
            if ($id && (!isset($byId[$id]) || isset($seen[$id]))) {
                throw new RuntimeException('人物行IDが不正です。');
            }
            if ($id) { $seen[$id] = true; }
            if (($row['delete'] ?? '') === '1') {
                if ($id) { $deleted[] = $id; }
                continue;
            }
            $old = $id ? $byId[$id] : null;
            $name = (string)($row['creator_name'] ?? '');
            if (!$old || $name !== (string)$old['creator_name']) { $name = trim($name); }
            if ($name === '') { throw new RuntimeException('人物名を入力するか、削除を選択してください。'); }
            $creatorId = $this->resolveCreator($name, self::integer($row['creator_id'] ?? '0', 0), $old);
            $role = (string)($row['role'] ?? ''); // Preserve spelling, whitespace and empty roles.
            if (mb_strlen($role, 'UTF-8') > 50) { throw new RuntimeException('役割は50文字以内で入力してください。'); }
            $order = self::integer($row['display_order'] ?? '');
            $roleChanged = !$old || $old['role'] !== $role;
            $roleId = $old['role_id'] ?? null;
            if ($roleChanged) {
                $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
                $stmt->execute([$role]);
                $value = $stmt->fetchColumn();
                $roleId = $value === false ? null : (int)$value;
            }
            // Explicit checkbox state wins, including OFF on an otherwise visible role.
            // Old forms without this field retain the previous role-based behavior.
            $visible = $roleChanged ? self::defaultVisible($role) : (int)$old['default_visible'];
            if (array_key_exists('default_visible', $row)) {
                if (!in_array($row['default_visible'], [0, 1, '0', '1'], true)) {
                    throw new RuntimeException('表示チェックの値が不正です。');
                }
                $visible = (int)$row['default_visible'];
            }
            $desired[] = ['id' => $id, 'creator_id' => $creatorId, 'role' => $role,
                'role_id' => $roleId, 'display_order' => $order,
                'default_visible' => $visible,
                'old' => $old];
        }
        if (count($seen) !== count($byId)) {
            throw new RuntimeException('人物行が不足しています。再読み込みしてください。削除は削除ボタンで指定してください。');
        }
        // Compare final rows with the same collation as the existing UNIQUE index.
        $equal = $this->pdo->prepare('SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci');
        foreach ($desired as $i => $row) {
            for ($j = 0; $j < $i; $j++) {
                if ($row['creator_id'] !== $desired[$j]['creator_id']) { continue; }
                $equal->execute([$row['role'], $desired[$j]['role']]);
                if ($equal->fetchColumn()) { throw new RuntimeException('同じ人物・役割が重複しています。'); }
            }
        }
        $delete = $this->pdo->prepare('DELETE FROM work_creators WHERE id = ? AND work_id = ?');
        foreach ($deleted as $id) { $delete->execute([$id, $workId]); }
        // Park only changed keys to allow swaps without deleting/reinserting existing IDs.
        $park = $this->pdo->prepare('UPDATE work_creators SET role = ? WHERE id = ? AND work_id = ?');
        foreach ($desired as $row) {
            $old = $row['old'];
            if ($old && ((int)$old['creator_id'] !== $row['creator_id'] || $old['role'] !== $row['role'])) {
                $park->execute(['__edit_' . bin2hex(random_bytes(20)), $row['id'], $workId]);
            }
        }
        $update = $this->pdo->prepare('UPDATE work_creators SET creator_id = ?, role_id = ?, role = ?,
            display_order = ?, default_visible = ? WHERE id = ? AND work_id = ?');
        $insert = $this->pdo->prepare('INSERT INTO work_creators
            (creator_id, role_id, role, display_order, default_visible, work_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($desired as $row) {
            $old = $row['old'];
            $values = [$row['creator_id'], $row['role_id'], $row['role'], $row['display_order'], $row['default_visible']];
            if (!$old) { $insert->execute(array_merge($values, [$workId])); continue; }
            if ((int)$old['creator_id'] !== $row['creator_id'] || $old['role'] !== $row['role'] ||
                (int)$old['display_order'] !== $row['display_order'] ||
                (int)$old['default_visible'] !== $row['default_visible']) {
                $update->execute(array_merge($values, [$row['id'], $workId]));
            }
        }
    }

    private static function integer($value, $min = -2147483648)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]);
        if ($value === false) { throw new RuntimeException('人物ID・表示順には範囲内の整数を入力してください。'); }
        return $value;
    }
}
