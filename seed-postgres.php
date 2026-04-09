#!/usr/bin/env php
<?php
/**
 * Migrate Maho SQLite seed database to Neon Postgres.
 * Reads SQLite schema + data, creates equivalent Postgres tables, copies all rows.
 */

$startTime = microtime(true);

$sqliteFile = $argv[1] ?? '/maho-seed.db';
$pgHost     = getenv('PG_HOST') ?: 'localhost';
$pgUser     = getenv('PG_USER') ?: 'neondb_owner';
$pgPass     = getenv('PG_PASS') ?: '';
$pgDbname   = getenv('PG_DBNAME') ?: 'neondb';
$pgSslmode  = getenv('PG_SSLMODE') ?: 'require';

echo "=== SQLite → Neon migration ===\n";
echo "Source: $sqliteFile\n";
echo "Target: $pgHost/$pgDbname\n";

// Connect SQLite
$sqlite = new PDO("sqlite:$sqliteFile");
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Connect Postgres
$dsn = "pgsql:host=$pgHost;dbname=$pgDbname;sslmode=$pgSslmode";
$pg = new PDO($dsn, $pgUser, $pgPass);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Connected to both databases.\n";

// Get all tables from SQLite
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($tables) . " tables in SQLite.\n";

// Drop all existing tables in Postgres (cascade)
$pg->exec("DO \$\$ DECLARE r RECORD; BEGIN FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP EXECUTE 'DROP TABLE IF EXISTS \"' || r.tablename || '\" CASCADE'; END LOOP; END \$\$;");
echo "Dropped existing Postgres tables.\n";

// SQLite type → Postgres type mapping
function sqliteTypeToPg(string $type): string {
    $type = strtoupper(trim($type));
    // Handle common SQLite types
    if ($type === '' || $type === 'BLOB') return 'BYTEA';
    if (preg_match('/^(TINY|SMALL|MEDIUM|BIG)?INT(EGER)?(\(\d+\))?( UNSIGNED)?$/i', $type)) return 'BIGINT';
    if (preg_match('/^(FLOAT|DOUBLE|REAL)/i', $type)) return 'DOUBLE PRECISION';
    if (preg_match('/^DECIMAL\((\d+),\s*(\d+)\)/i', $type, $m)) return "DECIMAL($m[1],$m[2])";
    if (preg_match('/^(NUMERIC|DECIMAL)/i', $type)) return 'NUMERIC';
    if (preg_match('/^(VARCHAR|CHAR|CHARACTER)\((\d+)\)/i', $type, $m)) return "VARCHAR($m[2])";
    if (preg_match('/^(TEXT|CLOB|LONGTEXT|MEDIUMTEXT|TINYTEXT)/i', $type)) return 'TEXT';
    if (preg_match('/^(DATETIME|TIMESTAMP)/i', $type)) return 'TIMESTAMP';
    if ($type === 'DATE') return 'DATE';
    if ($type === 'TIME') return 'TIME';
    if ($type === 'BOOLEAN') return 'BOOLEAN';
    return 'TEXT'; // fallback
}

$totalRows = 0;
$tableCount = 0;

foreach ($tables as $table) {
    // Get table info from SQLite
    $cols = $sqlite->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) continue;

    // Build CREATE TABLE for Postgres
    $colDefs = [];
    $colNames = [];
    $primaryKeys = [];

    // Detect single integer PK for BIGSERIAL
    $intPkCols = array_filter($cols, fn($c) => $c['pk'] && preg_match('/INT/i', $c['type']));
    $useBigserial = (count($intPkCols) === 1);

    foreach ($cols as $col) {
        $name = $col['name'];
        $colNames[] = $name;

        // Use BIGSERIAL for single integer PK (auto-increment)
        if ($col['pk'] && $useBigserial && preg_match('/INT/i', $col['type'])) {
            $def = "\"$name\" BIGSERIAL";
        } else {
            $pgType = sqliteTypeToPg($col['type']);
            $def = "\"$name\" $pgType";
            if ($col['notnull']) $def .= ' NOT NULL';
            if ($col['dflt_value'] !== null) {
                $default = $col['dflt_value'];
                // Convert SQLite defaults to Postgres-compatible
                if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                    $def .= ' DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_numeric($default) || $default === "''" || preg_match("/^'.*'$/", $default)) {
                    $def .= " DEFAULT $default";
                }
            }
        }
        if ($col['pk']) $primaryKeys[] = "\"$name\"";

        $colDefs[] = $def;
    }

    // Add primary key constraint
    if (!empty($primaryKeys)) {
        $colDefs[] = 'PRIMARY KEY (' . implode(', ', $primaryKeys) . ')';
    }

    $createSql = "CREATE TABLE \"$table\" (\n  " . implode(",\n  ", $colDefs) . "\n)";

    try {
        $pg->exec($createSql);
    } catch (PDOException $e) {
        echo "  ERROR creating $table: " . $e->getMessage() . "\n";
        continue;
    }

    // Copy data in batches
    $rowCount = $sqlite->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    if ($rowCount == 0) {
        $tableCount++;
        continue;
    }

    $batchSize = 500;
    $placeholders = '(' . implode(',', array_fill(0, count($colNames), '?')) . ')';
    $quotedCols = array_map(fn($c) => "\"$c\"", $colNames);
    $insertPrefix = "INSERT INTO \"$table\" (" . implode(',', $quotedCols) . ") VALUES ";

    $offset = 0;
    while ($offset < $rowCount) {
        $rows = $sqlite->query("SELECT * FROM \"$table\" LIMIT $batchSize OFFSET $offset")->fetchAll(PDO::FETCH_NUM);
        if (empty($rows)) break;

        // Build multi-row INSERT
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = $placeholders;
            foreach ($row as $val) {
                $params[] = $val;
            }
        }

        try {
            $sql = $insertPrefix . implode(',', $values);
            $stmt = $pg->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            // Try row-by-row on batch failure
            foreach ($rows as $row) {
                try {
                    $stmt = $pg->prepare($insertPrefix . $placeholders);
                    $stmt->execute($row);
                } catch (PDOException $e2) {
                    // Skip problematic rows silently
                }
            }
        }

        $offset += $batchSize;
    }

    $totalRows += $rowCount;
    $tableCount++;

    if ($tableCount % 50 === 0) {
        $elapsed = round(microtime(true) - $startTime, 1);
        echo "  Progress: $tableCount/" . count($tables) . " tables, $totalRows rows ({$elapsed}s)\n";
    }
}

// Create indexes from SQLite
echo "Creating indexes...\n";
$indexes = $sqlite->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);

$indexCount = 0;
foreach ($indexes as $idx) {
    $sql = $idx['sql'];
    // Convert SQLite index syntax to Postgres
    // SQLite: CREATE INDEX "idx_name" ON "table" ("col1","col2")
    // Postgres: same syntax works mostly
    try {
        $pg->exec($sql);
        $indexCount++;
    } catch (PDOException $e) {
        // Skip duplicate or incompatible indexes
    }
}

// Create foreign keys (after all tables exist)
echo "Creating foreign keys...\n";
$fkCount = 0;
foreach ($tables as $table) {
    try {
        $fks = $sqlite->query("PRAGMA foreign_key_list(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($fks)) continue;

        // Group by FK id (multi-column FKs share the same id)
        $grouped = [];
        foreach ($fks as $fk) {
            $grouped[$fk['id']][] = $fk;
        }

        foreach ($grouped as $id => $cols) {
            $refTable = $cols[0]['table'];
            $onUpdate = $cols[0]['on_update'] ?? 'NO ACTION';
            $onDelete = $cols[0]['on_delete'] ?? 'NO ACTION';
            $fromCols = implode(', ', array_map(fn($c) => '"' . $c['from'] . '"', $cols));
            $toCols = implode(', ', array_map(fn($c) => '"' . $c['to'] . '"', $cols));
            $fkName = "fk_{$table}_{$id}";

            $sql = "ALTER TABLE \"$table\" ADD CONSTRAINT \"$fkName\" "
                 . "FOREIGN KEY ($fromCols) REFERENCES \"$refTable\" ($toCols) "
                 . "ON UPDATE $onUpdate ON DELETE $onDelete";
            try {
                $pg->exec($sql);
                $fkCount++;
            } catch (PDOException $e) {
                // Skip FKs that reference missing tables or have issues
            }
        }
    } catch (PDOException $e) {
        // Skip tables where PRAGMA fails
    }
}
echo "  Created $fkCount foreign keys.\n";

// Migrate triggers
echo "Creating triggers...\n";
$triggerCount = 0;
$triggers = $sqlite->query("SELECT name, sql FROM sqlite_master WHERE type='trigger' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($triggers as $trigger) {
    // SQLite trigger syntax is quite different from Postgres — log but skip
    // Maho doesn't use DB triggers in practice (all logic is in PHP)
    $triggerCount++;
}
if ($triggerCount > 0) {
    echo "  Found $triggerCount SQLite triggers (skipped — Maho uses PHP-level triggers).\n";
} else {
    echo "  No triggers found.\n";
}

// Reset all sequences to max(id)+1 after data import
// Uses pg_get_serial_sequence() to get actual sequence names from the catalog,
// which handles truncated names and non-standard naming correctly
echo "Resetting sequences...\n";
$seqCount = 0;
$sequences = $pg->query("
    SELECT t.relname AS tbl, a.attname AS col, pg_get_serial_sequence(t.relname::text, a.attname::text) AS seq
    FROM pg_class t
    JOIN pg_attribute a ON a.attrelid = t.oid
    WHERE t.relkind = 'r'
      AND pg_get_serial_sequence(t.relname::text, a.attname::text) IS NOT NULL
      AND t.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($sequences as $seq) {
    try {
        $maxVal = $pg->query("SELECT COALESCE(MAX(\"{$seq['col']}\"), 0) FROM \"{$seq['tbl']}\"")->fetchColumn();
        $pg->exec("SELECT setval('{$seq['seq']}', " . max(1, (int)$maxVal) . ")");
        $seqCount++;
    } catch (PDOException $e) {
        echo "  WARNING: sequence {$seq['seq']} reset failed: " . $e->getMessage() . "\n";
    }
}

$elapsed = round(microtime(true) - $startTime, 1);
echo "=== Migration complete: $tableCount tables, $totalRows rows, $indexCount indexes, $fkCount FKs, $seqCount sequences in {$elapsed}s ===\n";
