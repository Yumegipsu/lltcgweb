<?php

declare(strict_types=1);

namespace LLTCG\Db;

use PDO;

final class Migrator
{
    public static function run(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            applied_at INTEGER NOT NULL
        )');

        $dir = dirname(__DIR__, 2) . '/migrations';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
            $stmt->execute([$version]);
            if ($stmt->fetchColumn()) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            if (trim($sql) === '') {
                continue;
            }
            $db->exec($sql);
            $ins = $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $ins->execute([$version, time()]);
        }
    }
}
