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

namespace PikaRelay\Tests;

use PHPUnit\Framework\TestCase;
use PikaRelay\App;
use PikaRelay\Auth\ApiAuth;
use PikaRelay\Config;
use PikaRelay\Db\Database;
use PikaRelay\Db\Schema;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;

/**
 * Runs the app in-process against a fresh database.
 *
 * SQLite (a temp file) by default. To run against MySQL/MariaDB instead, set
 * PIKA_TEST_DB=mysql plus PIKA_TEST_MYSQL_HOST, _PORT, _NAME, _USER and _PASSWORD.
 * Every table in that database is dropped and recreated.
 */
abstract class AppTestCase extends TestCase
{
    protected const START_MS = 1795961730000;

    /** @var string */
    protected $dir;

    /** @var FakeReleaseSource stands in for GitHub; tests set ->release */
    protected $releases;

    /** @var FixedClock */
    protected $clock;

    /** @var Database */
    protected $db;

    /** @var Config */
    protected $config;

    /** @var App */
    protected $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pikarelay-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->clock = new FixedClock(self::START_MS);
        $this->db = $this->makeDatabase();
        Schema::install($this->db);
        $this->config = new Config($this->dir . '/config.php', [
            'db' => [],
            'admin_user' => 'admin',
            'admin_hash' => password_hash('correct horse', PASSWORD_DEFAULT, ['cost' => 4]),
            'secure_mode' => true,
            'pepper' => 'test-pepper',
            'settle_ms' => 2000,
            'command_ttl_sec' => 600,
            'max_rewind_days' => 7,
        ]);
        $this->app = $this->makeApp();
        $this->addKey('reader-key', 'reader-secret', ApiAuth::ROLE_READER);
        $this->addKey('client-key', 'client-secret', ApiAuth::ROLE_CLIENT);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
    }

    protected function makeApp(int $pageLimit = 10000, int $maxBatch = 2000): App
    {
        $app = new App($this->config, $this->db, $this->clock, $pageLimit, $maxBatch);
        $this->releases = new FakeReleaseSource();
        $app->setReleaseSource($this->releases);
        return $app;
    }

    protected function addKey(string $key, string $secret, string $role): void
    {
        $this->db->run(
            'INSERT INTO api_keys (api_key, secret_hash, role, label, created_ms) VALUES (?, ?, ?, ?, ?)',
            [$key, ApiAuth::hashSecret($secret, 'test-pepper'), $role, $role, self::START_MS]
        );
    }

    /**
     * Calls the app. $as is 'reader', 'client' or null (no credentials). Array/object bodies
     * are JSON-encoded and sent as application/json.
     *
     * @param mixed                 $body
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $path, $body = null, ?string $as = null, array $headers = []): Response
    {
        if ($as !== null) {
            $headers += ['X-Pika-Key' => $as . '-key', 'X-Pika-Secret' => $as . '-secret'];
        }
        if ($body !== null && !is_string($body)) {
            $body = (string) json_encode($body, Response::JSON_FLAGS);
        }
        if ($body !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }
        return $this->app->handle(new Request($method, $path, $headers, $body));
    }

    /**
     * A PikaReader-style READ object.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function read(string $chip, int $epochMilli, array $extra = []): array
    {
        return $extra + [
            'chip' => $chip,
            'epc' => strtoupper(dechex((int) $chip)),
            'timestamp' => gmdate('Y-m-d H:i:s', intdiv($epochMilli, 1000)) . '.' . sprintf('%03d', $epochMilli % 1000),
            'reader' => 1,
            'antenna' => 2,
            'rssi' => -52.5,
            'tz' => 'Z',
            'epochMilli' => $epochMilli,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(Response $response): array
    {
        $data = json_decode($response->body(), true);
        self::assertIsArray($data, 'Response is not a JSON object: ' . $response->body());
        return $data;
    }

    private function makeDatabase(): Database
    {
        if (getenv('PIKA_TEST_DB') === 'mysql') {
            $db = Database::fromConfig([
                'driver' => 'mysql',
                'host' => getenv('PIKA_TEST_MYSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('PIKA_TEST_MYSQL_PORT') ?: 3306),
                'name' => getenv('PIKA_TEST_MYSQL_NAME') ?: 'pikarelay_test',
                'user' => getenv('PIKA_TEST_MYSQL_USER') ?: 'root',
                'password' => getenv('PIKA_TEST_MYSQL_PASSWORD') ?: '',
            ], PIKA_ROOT);
            foreach (['meta', 'readers', 'tag_reads', 'commands', 'api_keys', 'login_attempts'] as $table) {
                $db->pdo()->exec('DROP TABLE IF EXISTS ' . $table);
            }
            return $db;
        }
        return Database::fromConfig(['driver' => 'sqlite', 'sqlite_path' => $this->dir . '/test.sqlite'], PIKA_ROOT);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
