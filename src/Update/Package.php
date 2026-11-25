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

use PikaRelay\Version;
use RuntimeException;
use ZipArchive;

/**
 * A release zip (as built by tools/build-release.sh), fully validated before anything is
 * installed: every entry is a regular file or folder under "pikarelay/" with a plain relative
 * path, the required files are present and the version in src/VERSION is valid. Size and entry
 * limits guard against zip bombs.
 */
final class Package
{
    public const PREFIX = 'pikarelay/';
    public const REQUIRED = ['index.php', 'src/bootstrap.php', 'src/App.php', 'src/VERSION'];

    /** @var ZipArchive */
    private $zip;

    /** @var array<string, int> relative path => zip index */
    private $files = [];

    /** @var string */
    private $version;

    public function __construct(string $file, int $maxEntries = 2000, int $maxBytes = 52428800)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension (ZipArchive) is not installed.');
        }
        $zip = new ZipArchive();
        if (!is_file($file) || $zip->open($file) !== true) {
            throw new RuntimeException('The file is not a zip archive.');
        }
        $this->zip = $zip;
        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException('The zip has more than ' . $maxEntries . ' entries; it is not a PikaRelay release.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException('The zip archive is damaged.');
            }
            $name = (string) $stat['name'];
            if (strncmp($name, self::PREFIX, strlen(self::PREFIX)) !== 0) {
                if (preg_match('#^PikaRelay-[^/]+/#i', $name)) {
                    throw new RuntimeException('This is GitHub\'s "Source code" zip. Use the pikarelay-x.y.z.zip file attached to the release instead.');
                }
                throw new RuntimeException('This is not a PikaRelay release zip: everything must be inside a "pikarelay" folder.');
            }
            $rel = substr($name, strlen(self::PREFIX));
            $isDir = substr($rel, -1) === '/';
            if ($isDir) {
                $rel = substr($rel, 0, -1);
            }
            if ($rel === '') {
                continue;
            }
            if (!self::isSafePath($rel)) {
                throw new RuntimeException('The zip contains an unsafe path: ' . self::printable($name));
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
                && $opsys === ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('The zip contains a symbolic link: ' . self::printable($name));
            }
            if ($isDir) {
                continue;
            }
            $total += (int) $stat['size'];
            if ($total > $maxBytes) {
                throw new RuntimeException('The zip unpacks to more than ' . $maxBytes . ' bytes; it is not a PikaRelay release.');
            }
            $this->files[$rel] = $i;
        }
        foreach (self::REQUIRED as $required) {
            if (!isset($this->files[$required])) {
                throw new RuntimeException('This is not a PikaRelay release zip: ' . $required . ' is missing.');
            }
        }
        $version = trim($this->contents('src/VERSION'));
        if (!Version::isValid($version)) {
            throw new RuntimeException('The zip has an invalid src/VERSION.');
        }
        $this->version = $version;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Relative paths of the files in the package, e.g. "src/App.php".
     *
     * @return string[]
     */
    public function files(): array
    {
        return array_keys($this->files);
    }

    public function contents(string $rel): string
    {
        if (!isset($this->files[$rel])) {
            throw new RuntimeException('The package has no ' . $rel . '.');
        }
        $data = $this->zip->getFromIndex($this->files[$rel]);
        if ($data === false) {
            throw new RuntimeException('Could not unpack ' . $rel . ' from the zip.');
        }
        return $data;
    }

    /**
     * A relative path made only of plain names: no absolute paths, "." or ".." segments,
     * backslashes or unusual characters.
     */
    public static function isSafePath(string $rel): bool
    {
        if ($rel === '' || strlen($rel) > 255) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._+@-]{1,100}$/', $segment)) {
                return false;
            }
        }
        return true;
    }

    private static function printable(string $name): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', substr($name, 0, 120));
    }
}
