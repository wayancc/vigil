<?php

declare(strict_types=1);

namespace Vigil;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper over a single SQLite file, with versioned migrations.
 */
final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create the storage folder: {$dir}");
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException("The storage folder isn't writable by PHP: {$dir}");
            }
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        // Switching a new file to WAL can fail with "locked" without waiting when another process
        // opens it at the same moment (worker and web on first start). It sticks once set, so retry briefly.
        for ($try = 1; ; $try++) {
            try {
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                break;
            } catch (\PDOException $e) {
                if ($try >= 50) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        // Bind with real types: SQLite compares an integer with a string-bound value as text
        // inside expressions such as COALESCE(), which silently breaks time-range queries.
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null                 => PDO::PARAM_NULL,
                default                         => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $this->query(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(fn ($c) => ':' . $c, $cols))),
            $data,
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn ($c) => "{$c} = :set_{$c}", array_keys($data)));
        $bound = [];
        foreach ($data as $k => $v) {
            $bound['set_' . $k] = $v;
        }

        return $this->query("UPDATE {$table} SET {$set} WHERE {$where}", $bound + $params)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $migrations = [
            1 => <<<'SQL'
                CREATE TABLE wyn_settings (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE wyn_users (
                    id            INTEGER PRIMARY KEY,
                    email         TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at    INTEGER NOT NULL
                );
                CREATE TABLE wyn_monitors (
                    id                INTEGER PRIMARY KEY,
                    name              TEXT NOT NULL,
                    slug              TEXT NOT NULL UNIQUE,
                    type              TEXT NOT NULL,                 -- http | keyword | tcp | heartbeat
                    url               TEXT,
                    method            TEXT NOT NULL DEFAULT 'GET',
                    expected_status   TEXT NOT NULL DEFAULT '200-299',
                    keyword           TEXT,
                    keyword_absent    INTEGER NOT NULL DEFAULT 0,
                    host              TEXT,
                    port              INTEGER,
                    interval_sec      INTEGER NOT NULL DEFAULT 60,
                    timeout_sec       INTEGER NOT NULL DEFAULT 10,
                    confirmations     INTEGER NOT NULL DEFAULT 2,
                    verify_ssl        INTEGER NOT NULL DEFAULT 1,
                    ssl_warn_days     INTEGER NOT NULL DEFAULT 14,
                    ssl_expires_at    INTEGER,
                    ssl_checked_at    INTEGER,
                    ssl_warned_at     INTEGER,
                    heartbeat_token   TEXT UNIQUE,
                    last_heartbeat_at INTEGER,
                    is_public         INTEGER NOT NULL DEFAULT 1,
                    paused            INTEGER NOT NULL DEFAULT 0,
                    group_name        TEXT NOT NULL DEFAULT '',
                    sort              INTEGER NOT NULL DEFAULT 0,
                    status            TEXT NOT NULL DEFAULT 'pending', -- pending | up | down
                    status_since      INTEGER,
                    fail_count        INTEGER NOT NULL DEFAULT 0,
                    last_checked_at   INTEGER,
                    next_check_at     INTEGER NOT NULL DEFAULT 0,
                    last_response_ms  INTEGER,
                    last_error        TEXT,
                    created_at        INTEGER NOT NULL
                );
                CREATE TABLE wyn_checks (
                    id          INTEGER PRIMARY KEY,
                    monitor_id  INTEGER NOT NULL REFERENCES wyn_monitors(id) ON DELETE CASCADE,
                    checked_at  INTEGER NOT NULL,
                    ok          INTEGER NOT NULL,
                    response_ms INTEGER,
                    status_code INTEGER,
                    error       TEXT
                );
                CREATE INDEX wyn_checks_monitor_time ON wyn_checks (monitor_id, checked_at);
                CREATE TABLE wyn_daily_stats (
                    monitor_id INTEGER NOT NULL REFERENCES wyn_monitors(id) ON DELETE CASCADE,
                    day        TEXT NOT NULL,
                    checks     INTEGER NOT NULL DEFAULT 0,
                    ok         INTEGER NOT NULL DEFAULT 0,
                    total_ms   INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (monitor_id, day)
                );
                CREATE TABLE wyn_incidents (
                    id          INTEGER PRIMARY KEY,
                    monitor_id  INTEGER NOT NULL REFERENCES wyn_monitors(id) ON DELETE CASCADE,
                    started_at  INTEGER NOT NULL,
                    resolved_at INTEGER,
                    cause       TEXT
                );
                CREATE INDEX wyn_incidents_monitor ON wyn_incidents (monitor_id, started_at);
                CREATE TABLE wyn_channels (
                    id         INTEGER PRIMARY KEY,
                    type       TEXT NOT NULL,
                    name       TEXT NOT NULL,
                    config     TEXT NOT NULL DEFAULT '{}',
                    enabled    INTEGER NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL
                );
                CREATE TABLE wyn_notification_log (
                    id         INTEGER PRIMARY KEY,
                    channel_id INTEGER,
                    event      TEXT NOT NULL,
                    ok         INTEGER NOT NULL,
                    error      TEXT,
                    sent_at    INTEGER NOT NULL
                );
                CREATE TABLE wyn_notices (
                    id         INTEGER PRIMARY KEY,
                    title      TEXT NOT NULL,
                    body       TEXT NOT NULL DEFAULT '',
                    kind       TEXT NOT NULL DEFAULT 'info',         -- info | maintenance | incident
                    starts_at  INTEGER NOT NULL,
                    ends_at    INTEGER,
                    created_at INTEGER NOT NULL
                );
                CREATE TABLE wyn_login_attempts (
                    ip           TEXT NOT NULL,
                    attempted_at INTEGER NOT NULL
                );
                SQL,
        ];

        $version = fn (): int => (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version() >= array_key_last($migrations)) {
            return;
        }

        // The worker and the first web request can start at the same moment (Docker).
        // Take the write lock first, then re-read the version, so only one of them migrates.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $version();
            foreach ($migrations as $v => $sql) {
                if ($v > $current) {
                    $this->pdo->exec($sql);
                    $this->pdo->exec('PRAGMA user_version = ' . $v);
                }
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
