<?php

// Prepared statements are reused for every content in a bulk registration.
// All writes must be called inside the caller's transaction.
class ContentCreatorWriter
{
    private $pdo;
    private $lock;
    private $copy;
    private $insert;
    private $role;
    private $update;

    const SNAPSHOT_FIELDS = ['display_name', 'display_title', 'display_affiliation'];

    // Preserve untouched NULL/empty strings, and never derive one field from another.
    public static function snapshotValues(array $input, array $old = [])
    {
        $values = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $previous = $old[$field] ?? null;
            if (!array_key_exists($field, $input)) {
                $values[$field] = $previous;
                continue;
            }
            if ($input[$field] !== null && !is_string($input[$field])) {
                throw new RuntimeException('表示名・肩書・所属の入力が不正です。');
            }
            $value = (string)$input[$field];
            if ($value === (string)$previous) {
                $values[$field] = $previous;
                continue;
            }
            if (mb_strlen($value, 'UTF-8') > 255) {
                throw new RuntimeException('表示名・肩書・所属はそれぞれ255文字以内で入力してください。');
            }
            if ($field === 'display_affiliation' && preg_match('/^(?:（.*）|\\(.*\\))$/us', trim($value))) {
                throw new RuntimeException('所属は外側の括弧を付けずに入力してください。');
            }
            $values[$field] = $value === '' ? null : $value;
        }
        return $values;
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->lock = $pdo->prepare('SELECT id FROM issue_contents WHERE id = ? FOR UPDATE');
        $this->copy = $pdo->prepare('
            INSERT INTO content_creators
                (content_id, creator_id, role_id, role, display_order, is_visible,
                 display_name, display_title, display_affiliation)
            SELECT ?, wc.creator_id, wc.role_id, wc.role, wc.display_order, wc.default_visible, NULL, NULL, NULL
            FROM work_creators wc
            WHERE wc.work_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM content_creators cc
                  WHERE cc.content_id = ? AND cc.creator_id = wc.creator_id AND cc.role = wc.role
              )
            ORDER BY wc.display_order, wc.id
        ');
        $this->insert = $pdo->prepare('
            INSERT INTO content_creators
                (content_id, creator_id, role_id, role, display_order, is_visible,
                 display_name, display_title, display_affiliation)
            SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM content_creators
                WHERE content_id = ? AND creator_id = ? AND role = ?
            )
        ');
        $this->role = $pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
        $this->update = $pdo->prepare('UPDATE content_creators
            SET creator_id = ?, role_id = ?, role = ?, display_order = ?, is_visible = ?,
                display_name = ?, display_title = ?, display_affiliation = ?
            WHERE id = ? AND content_id = ?');
    }

    public function lockContent($contentId)
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('人物の保存にはトランザクションが必要です。');
        }
        $this->lock->execute([$contentId]);
        $exists = $this->lock->fetchColumn();
        $this->lock->closeCursor();
        if ($exists === false) {
            throw new RuntimeException('目次が見つかりません。');
        }
    }

    // The caller locks the parent content before any creator writes.
    public function syncWorkCreatorsToContentCreators($workId, $contentId)
    {
        if ($workId > 0) {
            $this->copy->execute([$contentId, $workId, $contentId]);
            return $this->copy->rowCount();
        }
        return 0;
    }

    public function insertIfMissing($contentId, $creatorId, $roleId, $role, $order, $visible, array $snapshot = [])
    {
        $snapshot = self::snapshotValues($snapshot);
        $this->insert->execute([
            $contentId, $creatorId, $roleId, $role, $order, $visible,
            $snapshot['display_name'], $snapshot['display_title'], $snapshot['display_affiliation'],
            $contentId, $creatorId, $role,
        ]);
        return $this->insert->rowCount() > 0;
    }

    // Caller validates ownership, detects stale edits and holds the parent lock.
    public function updateIfChanged($contentId, array $old, $creatorId, $roleId, $role, $order, $visible, array $snapshot)
    {
        $snapshot = self::snapshotValues($snapshot, $old);
        $changed = (int)$old['creator_id'] !== (int)$creatorId
            || ($old['role_id'] === null ? null : (int)$old['role_id']) !== ($roleId === null ? null : (int)$roleId)
            || $old['role'] !== $role || (int)$old['display_order'] !== (int)$order
            || (int)$old['is_visible'] !== (int)$visible;
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $changed = $changed || ($old[$field] ?? null) !== $snapshot[$field];
        }
        if (!$changed) { return false; }
        $this->update->execute([$creatorId, $roleId, $role, $order, $visible,
            $snapshot['display_name'], $snapshot['display_title'], $snapshot['display_affiliation'],
            $old['id'], $contentId]);
        return true;
    }

    public function findRoleId($name)
    {
        $this->role->execute([$name]);
        $id = $this->role->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}
