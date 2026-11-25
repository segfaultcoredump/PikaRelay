<?php
/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


declare(strict_types=1);

namespace PikaRelay\Db;

/**
 * Table definitions for SQLite and MySQL/MariaDB. Times are epoch milliseconds (BIGINT).
 * Reader ids, chips and API keys are case-sensitive ASCII.
 */
final class Schema
{
    public const VERSION = 1;

    /**
     * @return string[]
     */
    public static function statements(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                'CREATE TABLE IF NOT EXISTS meta (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
                'CREATE TABLE IF NOT EXISTS readers (
                    id TEXT PRIMARY KEY,
                    status_json TEXT NULL,
                    status_ms INTEGER NULL,
                    first_seen_ms INTEGER NOT NULL,
                    last_read_ms INTEGER NULL
                )',
                'CREATE TABLE IF NOT EXISTS tag_reads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reader_id TEXT NOT NULL,
                    chip TEXT NOT NULL,
                    epoch_milli INTEGER NOT NULL,
                    rfid_reader INTEGER NOT NULL DEFAULT -1,
                    antenna INTEGER NOT NULL DEFAULT -1,
                    received_ms INTEGER NOT NULL,
                    data TEXT NOT NULL
                )',
                'CREATE UNIQUE INDEX IF NOT EXISTS reads_unique ON tag_reads (reader_id, chip, epoch_milli, rfid_reader, antenna)',
                'CREATE INDEX IF NOT EXISTS reads_received ON tag_reads (reader_id, received_ms)',
                'CREATE INDEX IF NOT EXISTS reads_epoch ON tag_reads (reader_id, epoch_milli)',
                'CREATE TABLE IF NOT EXISTS commands (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reader_id TEXT NOT NULL,
                    command TEXT NOT NULL,
                    args_json TEXT NOT NULL,
                    created_ms INTEGER NOT NULL,
                    expires_ms INTEGER NOT NULL,
                    acked_ms INTEGER NULL,
                    api_key_id INTEGER NULL
                )',
                'CREATE INDEX IF NOT EXISTS commands_pending ON commands (reader_id, acked_ms, expires_ms)',
                'CREATE TABLE IF NOT EXISTS api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    api_key TEXT NOT NULL UNIQUE,
                    secret_hash TEXT NOT NULL,
                    role TEXT NOT NULL,
                    label TEXT NOT NULL,
                    created_ms INTEGER NOT NULL,
                    last_used_ms INTEGER NULL
                )',
                'CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, attempt_ms INTEGER NOT NULL)',
                'CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, attempt_ms)',
            ];
        }

        $id = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin';
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        return [
            "CREATE TABLE IF NOT EXISTS meta (name $id NOT NULL PRIMARY KEY, value TEXT NOT NULL) $tail",
            "CREATE TABLE IF NOT EXISTS readers (
                id $id NOT NULL PRIMARY KEY,
                status_json MEDIUMTEXT NULL,
                status_ms BIGINT NULL,
                first_seen_ms BIGINT NOT NULL,
                last_read_ms BIGINT NULL
            ) $tail",
            "CREATE TABLE IF NOT EXISTS tag_reads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                reader_id $id NOT NULL,
                chip $id NOT NULL,
                epoch_milli BIGINT NOT NULL,
                rfid_reader INT NOT NULL DEFAULT -1,
                antenna INT NOT NULL DEFAULT -1,
                received_ms BIGINT NOT NULL,
                data TEXT NOT NULL,
                UNIQUE KEY reads_unique (reader_id, chip, epoch_milli, rfid_reader, antenna),
                KEY reads_received (reader_id, received_ms),
                KEY reads_epoch (reader_id, epoch_milli)
            ) $tail",
            "CREATE TABLE IF NOT EXISTS commands (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                reader_id $id NOT NULL,
                command VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                args_json TEXT NOT NULL,
                created_ms BIGINT NOT NULL,
                expires_ms BIGINT NOT NULL,
                acked_ms BIGINT NULL,
                api_key_id BIGINT UNSIGNED NULL,
                KEY commands_pending (reader_id, acked_ms, expires_ms)
            ) $tail",
            "CREATE TABLE IF NOT EXISTS api_keys (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                api_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                label VARCHAR(100) NOT NULL,
                created_ms BIGINT NOT NULL,
                last_used_ms BIGINT NULL,
                UNIQUE KEY api_keys_key (api_key)
            ) $tail",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attempt_ms BIGINT NOT NULL,
                KEY login_attempts_ip (ip, attempt_ms)
            ) $tail",
        ];
    }

    public static function install(Database $db): void
    {
        foreach (self::statements($db->driver()) as $sql) {
            $db->pdo()->exec($sql);
        }
        $db->run($db->insertIgnoreSql('meta', ['name', 'value'], 'name'), ['schema_version', (string) self::VERSION]);
        $db->run('UPDATE meta SET value = ? WHERE name = ?', [(string) self::VERSION, 'schema_version']);
    }

    /**
     * Brings the tables of an older installation up to date (after a software update).
     */
    public static function upgrade(Database $db): void
    {
        if (self::version($db) < self::VERSION) {
            self::install($db);
        }
    }

    public static function version(Database $db): int
    {
        return (int) $db->value('SELECT value FROM meta WHERE name = ?', ['schema_version']);
    }
}
