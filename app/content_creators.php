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

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->lock = $pdo->prepare('SELECT id FROM issue_contents WHERE id = ? FOR UPDATE');
        $this->copy = $pdo->prepare('
            INSERT INTO content_creators
                (content_id, creator_id, role_id, role, display_order, is_visible)
            SELECT ?, wc.creator_id, wc.role_id, wc.role, wc.display_order, wc.default_visible
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
                (content_id, creator_id, role_id, role, display_order, is_visible)
            SELECT ?, ?, ?, ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM content_creators
                WHERE content_id = ? AND creator_id = ? AND role = ?
            )
        ');
        $this->role = $pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
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

    public function insertIfMissing($contentId, $creatorId, $roleId, $role, $order, $visible)
    {
        $this->insert->execute([
            $contentId, $creatorId, $roleId, $role, $order, $visible,
            $contentId, $creatorId, $role,
        ]);
    }

    public function findRoleId($name)
    {
        $this->role->execute([$name]);
        $id = $this->role->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}
