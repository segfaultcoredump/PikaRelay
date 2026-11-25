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

use ZipArchive;

/**
 * Builds release zips (and fake installations) for the update tests.
 */
final class ReleaseZip
{
    /**
     * Files of a minimal release, keyed by path relative to the install folder.
     *
     * @return array<string, string>
     */
    public static function files(string $version): array
    {
        return [
            'index.php' => "<?php // index $version\n",
            '.htaccess' => "# htaccess $version\n",
            'LICENSE' => "license\n",
            'src/bootstrap.php' => "<?php // bootstrap $version\n",
            'src/App.php' => "<?php // App $version\n",
            'src/VERSION' => $version,
            'src/Web/View.php' => "<?php // View $version\n",
            'assets/app.css' => "/* css $version */\n",
            'config/.htaccess' => "deny $version\n",
            'config/index.html' => '',
            'data/.htaccess' => "deny $version\n",
            'data/probe.txt' => "PIKARELAY-PROBE\n",
        ];
    }

    /**
     * @param array<string, string|null> $changes extra or replaced files (null removes one), relative to the install folder
     */
    public static function build(string $zipFile, string $version, array $changes = [], string $prefix = 'pikarelay/'): string
    {
        $files = array_merge(self::files($version), $changes);
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $path => $contents) {
            if ($contents !== null) {
                $zip->addFromString($prefix . $path, $contents);
            }
        }
        $zip->close();
        return $zipFile;
    }

    /**
     * Writes a fake installation of $version into $root.
     *
     * @param array<string, string> $extra
     */
    public static function install(string $root, string $version, array $extra = []): void
    {
        foreach (array_merge(self::files($version), $extra) as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0755, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        chmod($dir, 0755);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
