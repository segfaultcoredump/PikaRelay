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

namespace PikaRelay\Update;

use PikaRelay\Db\Database;
use PikaRelay\Version;
use Throwable;

/**
 * Remembers the result of the last update check in the meta table, so the Admin page only asks
 * GitHub every few hours. A failed check is remembered too (for less time), so an unreachable
 * GitHub does not slow down every page load.
 */
final class UpdateChecker
{
    private const KEY = 'update_check';
    public const OK_TTL_MS = 43200000;    // 12 hours
    public const FAIL_TTL_MS = 3600000;   // 1 hour

    /** @var Database */
    private $db;

    /** @var ReleaseSource */
    private $source;

    public function __construct(Database $db, ReleaseSource $source)
    {
        $this->db = $db;
        $this->source = $source;
    }

    /**
     * The last check: ['checkedMs' => int|null, 'release' => Release|null, 'error' => string|null].
     * With $autoRefresh, a missing or stale result is refreshed first.
     *
     * @return array{checkedMs: int|null, release: Release|null, error: string|null}
     */
    public function status(int $nowMs, bool $autoRefresh): array
    {
        $status = $this->stored();
        if ($autoRefresh) {
            $ttl = $status['error'] === null ? self::OK_TTL_MS : self::FAIL_TTL_MS;
            if ($status['checkedMs'] === null || $nowMs - $status['checkedMs'] >= $ttl || $status['checkedMs'] > $nowMs) {
                return $this->refresh($nowMs);
            }
        }
        return $status;
    }

    /**
     * Asks the release source now and stores the answer.
     *
     * @return array{checkedMs: int|null, release: Release|null, error: string|null}
     */
    public function refresh(int $nowMs): array
    {
        $release = null;
        $error = null;
        try {
            $release = $this->source->latest();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $json = (string) json_encode([
            'checkedMs' => $nowMs,
            'release' => $release === null ? null : $release->toArray(),
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->run($this->db->insertIgnoreSql('meta', ['name', 'value'], 'name'), [self::KEY, $json]);
        $this->db->run('UPDATE meta SET value = ? WHERE name = ?', [$json, self::KEY]);
        return ['checkedMs' => $nowMs, 'release' => $release, 'error' => $error];
    }

    /**
     * @return array{checkedMs: int|null, release: Release|null, error: string|null}
     */
    private function stored(): array
    {
        $raw = $this->db->value('SELECT value FROM meta WHERE name = ?', [self::KEY]);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['checkedMs'])) {
            return ['checkedMs' => null, 'release' => null, 'error' => null];
        }
        return [
            'checkedMs' => (int) $data['checkedMs'],
            'release' => Release::fromArray($data['release'] ?? null),
            'error' => isset($data['error']) && is_string($data['error']) ? $data['error'] : null,
        ];
    }

    /**
     * True when release $latest is newer than the installed version $current.
     * A current version without a release number ("dev") counts as older than any release;
     * a local build ahead of a release ("1.2.0-3-gabc1234") counts as that release.
     */
    public static function isNewer(string $latest, string $current): bool
    {
        $latest = Version::fromTag($latest);
        $current = Version::fromTag($current);
        $number = '/^\d+(?:\.\d+)*/';
        if (!preg_match($number, $current, $c)) {
            return true;
        }
        if (!preg_match($number, $latest, $l)) {
            return false;
        }
        $cmp = version_compare($l[0], $c[0]);
        if ($cmp !== 0) {
            return $cmp > 0;
        }
        // Same release number: an installed pre-release (1.2.0-rc1) is older than the final 1.2.0,
        // but a build made after the tag (1.2.0-3-gabc1234) is not.
        $suffix = substr($current, strlen($c[0]));
        return $latest === $l[0] && $suffix !== '' && !preg_match('/^-\d+-g[0-9a-f]+$/', $suffix);
    }
}
