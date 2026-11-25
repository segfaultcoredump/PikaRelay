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

namespace PikaRelay;

/**
 * The release version, read from src/VERSION. tools/build-release.sh stamps that file into the
 * release zip from the git tag; a git checkout has no such file and reports "dev".
 */
final class Version
{
    public const UNKNOWN = 'dev';

    /** @var string|null */
    private static $current = null;

    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = self::fromFile(PIKA_ROOT . '/src/VERSION');
        }
        return self::$current;
    }

    public static function fromFile(string $file): string
    {
        if (!is_file($file)) {
            return self::UNKNOWN;
        }
        $raw = @file_get_contents($file, false, null, 0, 256);
        if ($raw === false) {
            return self::UNKNOWN;
        }
        $version = trim($raw);
        return preg_match('/^[0-9A-Za-z._+-]{1,64}$/', $version) ? $version : self::UNKNOWN;
    }
}
