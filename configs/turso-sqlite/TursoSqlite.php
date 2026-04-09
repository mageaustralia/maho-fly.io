<?php

declare(strict_types=1);

/**
 * Turso-aware SQLite adapter for Maho.
 *
 * When TURSO_SYNC_URL and TURSO_AUTH_TOKEN env vars are set, uses Turso's
 * Doctrine DBAL driver with embedded replica mode (local SQLite + remote sync).
 * Otherwise falls back to standard pdo_sqlite.
 */

namespace Maho\Db\Adapter\Pdo;

class TursoSqlite extends Sqlite
{
    /**
     * Creates a PDO connection — Turso embedded replica when configured,
     * standard pdo_sqlite otherwise.
     */
    #[\Override]
    protected function _connect(): void
    {
        if ($this->_connection) {
            return;
        }

        $syncUrl   = getenv('TURSO_SYNC_URL') ?: '';
        $authToken = getenv('TURSO_AUTH_TOKEN') ?: '';

        // If Turso is not configured, use standard SQLite
        if (empty($syncUrl) || empty($authToken)) {
            parent::_connect();
            return;
        }

        // Turso requires the libsql extension
        if (!extension_loaded('libsql_php')) {
            throw new \RuntimeException(
                'libsql_php extension is required for Turso embedded replicas. '
                . 'Falling back to pdo_sqlite is not possible when TURSO_SYNC_URL is set.'
            );
        }

        $this->_debugTimer();

        // Local replica path (same as standard SQLite path resolution)
        $path = $this->_config['path'] ?? $this->_config['dbname'] ?? '/data/maho.sqlite';
        if ($path[0] !== '/' && !str_contains($path, ':')) {
            $baseDir = defined('BP') ? BP : getcwd();
            $dbDir = $baseDir . '/var/db';
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            $path = $dbDir . '/' . $path;
        }

        $syncInterval = (int) (getenv('TURSO_SYNC_INTERVAL') ?: 5);
        $readYourWrites = getenv('TURSO_READ_YOUR_WRITES') !== 'false';

        $params = [
            'url'              => $path,
            'auth_token'       => $authToken,
            'sync_url'         => $syncUrl,
            'sync_interval'    => $syncInterval,
            'read_your_writes' => $readYourWrites,
            'driverClass'      => \Turso\Doctrine\DBAL\Driver::class,
        ];

        $this->_connection = \Doctrine\DBAL\DriverManager::getConnection($params);
        $this->_debugStat(self::DEBUG_CONNECT, '');

        $this->_initConnection();

        $this->_connectionFlagsSet = true;
    }
}
