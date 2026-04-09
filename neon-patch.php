<?php
/**
 * Patch Maho source for Neon Postgres compatibility.
 *
 * Neon's neon_superuser role CANNOT:
 *   - SET session_replication_role = replica (requires true superuser)
 *   - ALTER TABLE ... DISABLE TRIGGER ALL (blocks system triggers like FK constraints)
 *
 * Strategy:
 *   - Pgsql adapter startSetup()/endSetup(): no-op (called per-module, too expensive
 *     to iterate all tables over network). Schema scripts create their own tables/FKs.
 *   - Install.php: drop all FKs once before sample data import, re-enable after.
 *   - SampleData.php: drop all FKs once before import, re-enable after.
 */

$files = [
    '/app/lib/Maho/Db/Adapter/Pdo/Pgsql.php' => [
        // startSetup: no-op (called per-module during install — too expensive to iterate tables)
        [
            'search'  => <<<'PHP'
        // Disable foreign key checks in PostgreSQL session
        $this->raw_query('SET session_replication_role = replica');
PHP,
            'replace' => <<<'PHP'
        // Neon-compatible: no-op here (FK handling done once in Install.php/SampleData.php)
        // session_replication_role requires true superuser which Neon doesn't provide
PHP,
        ],
        // endSetup: no-op
        [
            'search'  => <<<'PHP'
        // Re-enable foreign key checks
        $this->raw_query('SET session_replication_role = DEFAULT');
PHP,
            'replace' => <<<'PHP'
        // Neon-compatible: no-op (matching startSetup)
PHP,
        ],
    ],
    '/app/lib/MahoCLI/Commands/Install.php' => [
        [
            'search'  => "                    \$pdo->exec('SET session_replication_role = replica');",
            'replace' => <<<'PHP'
                    // Neon-compatible: drop FK constraints + disable user triggers before sample data import
                    $__fks = $pdo->query("
                        SELECT tc.constraint_name, tc.table_name,
                               kcu.column_name, ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                        JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
                    ")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($__fks as $fk) {
                        try { $pdo->exec('ALTER TABLE "' . $fk['table_name'] . '" DROP CONSTRAINT "' . $fk['constraint_name'] . '"'); } catch (\Throwable $e) {}
                    }
                    $__tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($__tables as $t) {
                        try { $pdo->exec('ALTER TABLE "' . $t['tablename'] . '" DISABLE TRIGGER USER'); } catch (\Throwable $e) {}
                    }
PHP,
        ],
        [
            'search'  => "                    \$pdo->exec('SET session_replication_role = DEFAULT');",
            'replace' => <<<'PHP'
                    // Neon-compatible: re-enable user triggers + recreate FK constraints
                    $__tables2 = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($__tables2 as $t) {
                        try { $pdo->exec('ALTER TABLE "' . $t['tablename'] . '" ENABLE TRIGGER USER'); } catch (\Throwable $e) {}
                    }
                    // Recreate FKs that were dropped
                    if (!empty($__fks)) {
                        foreach ($__fks as $fk) {
                            try {
                                $pdo->exec('ALTER TABLE "' . $fk['table_name'] . '" ADD CONSTRAINT "' . $fk['constraint_name'] . '" FOREIGN KEY ("' . $fk['column_name'] . '") REFERENCES "' . $fk['foreign_table_name'] . '" ("' . $fk['foreign_column_name'] . '")');
                            } catch (\Throwable $e) {}
                        }
                    }
PHP,
        ],
    ],
    '/app/app/code/core/Mage/Install/Model/Installer/SampleData.php' => [
        [
            'search'  => "            \$pdo->exec('SET session_replication_role = replica');",
            'replace' => <<<'PHP'
            // Neon-compatible: drop FK constraints + disable user triggers before sample data
            $__fks = $pdo->query("
                SELECT tc.constraint_name, tc.table_name,
                       kcu.column_name, ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            ")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($__fks as $fk) {
                try { $pdo->exec('ALTER TABLE "' . $fk['table_name'] . '" DROP CONSTRAINT "' . $fk['constraint_name'] . '"'); } catch (\Throwable $e) {}
            }
            $__tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($__tables as $t) {
                try { $pdo->exec('ALTER TABLE "' . $t['tablename'] . '" DISABLE TRIGGER USER'); } catch (\Throwable $e) {}
            }
PHP,
        ],
        [
            'search'  => "            \$pdo->exec('SET session_replication_role = DEFAULT');",
            'replace' => <<<'PHP'
            // Neon-compatible: re-enable user triggers + recreate FKs
            $__tables2 = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($__tables2 as $t) {
                try { $pdo->exec('ALTER TABLE "' . $t['tablename'] . '" ENABLE TRIGGER USER'); } catch (\Throwable $e) {}
            }
            if (!empty($__fks)) {
                foreach ($__fks as $fk) {
                    try {
                        $pdo->exec('ALTER TABLE "' . $fk['table_name'] . '" ADD CONSTRAINT "' . $fk['constraint_name'] . '" FOREIGN KEY ("' . $fk['column_name'] . '") REFERENCES "' . $fk['foreign_table_name'] . '" ("' . $fk['foreign_column_name'] . '")');
                    } catch (\Throwable $e) {}
                }
            }
PHP,
        ],
    ],
];

$patched = 0;
$errors = 0;
foreach ($files as $file => $replacements) {
    if (!file_exists($file)) {
        echo "SKIP: $file not found\n";
        continue;
    }
    $content = file_get_contents($file);
    foreach ($replacements as $i => $r) {
        if (str_contains($content, $r['search'])) {
            $content = str_replace($r['search'], $r['replace'], $content);
            $patched++;
        } else {
            echo "ERROR: Search string not found in $file (replacement " . ($i + 1) . ")\n";
            $errors++;
        }
    }
    file_put_contents($file, $content);
    echo "PATCHED: $file\n";
}

echo "\nApplied $patched patches" . ($errors ? ", $errors errors" : "") . ".\n";
if ($errors) {
    exit(1);
}
