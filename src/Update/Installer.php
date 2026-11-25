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

use FilesystemIterator;
use PikaRelay\Version;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Installs a validated release Package over the running installation.
 *
 * The order is chosen so that a failure leaves a working install:
 *  1. lock, so two updates cannot run at once;
 *  2. pre-flight: every file that will be written or deleted must be writable;
 *  3. back up the current code to a zip (a valid Package, so it can be restored the same way);
 *  4. stage every new file next to its target under a dot-name the web server never serves;
 *     any failure here removes the staged files and leaves the live files untouched;
 *  5. rename the staged files over the live ones (index.php last);
 *  6. delete files under src/ and assets/ that the new release no longer has.
 *
 * The config file and the data folder are never written: only the shipped placeholder files in
 * config/ and data/ (.htaccess, index.html, probe.txt) are updated, and only when PHP may write
 * them (data/ need not be writable when the database lives elsewhere).
 */
final class Installer
{
    private const KEEP_BACKUPS = 3;
    private const TOP_FILES = ['index.php', '.htaccess', 'LICENSE', 'README.md'];
    private const MANAGED_DIRS = ['src', 'assets', 'docs', 'server-configs'];
    private const PRUNED_DIRS = ['src', 'assets'];
    private const PRIVATE_DIRS = ['config', 'data'];
    private const PRIVATE_SHIPPED = ['.htaccess', 'index.html', 'probe.txt'];
    private const STAGE_PREFIX = '.pikanew-';
    private const BACKUP_NAME = '/^pikarelay-([0-9A-Za-z._+-]{1,64})-(\d{8}-\d{6})-[0-9a-f]{4}\.zip$/';

    /** @var string */
    private $root;

    /** @var string */
    private $configDir;

    public function __construct(string $root, string $configDir)
    {
        $this->root = rtrim($root, '/\\');
        $this->configDir = rtrim($configDir, '/\\');
    }

    /**
     * Reasons this installation cannot update itself; empty when it can.
     *
     * @return string[]
     */
    public function problems(): array
    {
        if (file_exists($this->root . '/.git')) {
            return ['This copy of PikaRelay is a git checkout. Update it with git instead.'];
        }
        $problems = [];
        if (!class_exists('ZipArchive')) {
            $problems[] = 'The PHP zip extension (ZipArchive) is not installed.';
        }
        $unwritable = [];
        foreach ($this->pathsToWrite() as $path) {
            if (!is_writable($path)) {
                $unwritable[] = $this->relative($path);
            }
        }
        if ($unwritable) {
            $problems[] = 'PHP cannot write to ' . self::listing($unwritable) . '.';
        }
        if ($this->backupDir() === null) {
            $problems[] = 'PHP cannot write to data/ or the config folder, where backups are kept.';
        }
        return $problems;
    }

    /**
     * Where backups go: data/backups if possible, else backups/ in the config folder.
     */
    public function backupDir(): ?string
    {
        foreach ($this->backupDirs() as $dir) {
            if (is_dir($dir) ? is_writable($dir) : (is_dir(dirname($dir)) && is_writable(dirname($dir)))) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Backups, newest first.
     *
     * @return array<int, array{file: string, path: string, version: string, createdMs: int}>
     */
    public function backups(): array
    {
        $list = [];
        foreach ($this->backupDirs() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $name) {
                if (preg_match(self::BACKUP_NAME, $name, $m) && is_file($dir . '/' . $name)) {
                    $list[] = [
                        'file' => $name,
                        'path' => $dir . '/' . $name,
                        'version' => $m[1],
                        'createdMs' => (int) filemtime($dir . '/' . $name) * 1000,
                    ];
                }
            }
        }
        usort($list, static function (array $a, array $b): int {
            return [$b['createdMs'], $b['file']] <=> [$a['createdMs'], $a['file']];
        });
        return $list;
    }

    /**
     * Backs up the running version, then installs $package.
     *
     * @return array{from: string, to: string}
     */
    public function install(Package $package): array
    {
        return $this->run($package, true);
    }

    /**
     * Reinstalls the backup named $file (a name from backups()).
     *
     * @return array{from: string, to: string}
     */
    public function restore(string $file): array
    {
        foreach ($this->backups() as $backup) {
            if ($backup['file'] === $file) {
                return $this->run(new Package($backup['path']), false);
            }
        }
        throw new RuntimeException('That backup no longer exists.');
    }

    /**
     * True for package paths that are never written: the config file and anything in config/
     * or data/ other than the shipped placeholder files.
     */
    public static function isProtected(string $rel): bool
    {
        $slash = strpos($rel, '/');
        if ($slash === false || !in_array(substr($rel, 0, $slash), self::PRIVATE_DIRS, true)) {
            return false;
        }
        return !in_array(substr($rel, $slash + 1), self::PRIVATE_SHIPPED, true);
    }

    private static function isPrivatePlaceholder(string $rel): bool
    {
        $slash = strpos($rel, '/');
        return $slash !== false && in_array(substr($rel, 0, $slash), self::PRIVATE_DIRS, true) && !self::isProtected($rel);
    }

    private function canWrite(string $file): bool
    {
        if (file_exists($file)) {
            return is_file($file) && is_writable($file) && is_writable(dirname($file));
        }
        return is_dir(dirname($file)) && is_writable(dirname($file));
    }

    /**
     * @return array{from: string, to: string}
     */
    private function run(Package $package, bool $backup): array
    {
        $problems = $this->problems();
        if ($problems) {
            throw new RuntimeException('Nothing was changed: ' . implode(' ', $problems));
        }
        $lock = $this->lock();
        try {
            $from = Version::fromFile($this->root . '/src/VERSION');
            $targets = [];
            foreach ($package->files() as $rel) {
                if (self::isProtected($rel)) {
                    continue;
                }
                if (self::isPrivatePlaceholder($rel) && !$this->canWrite($this->root . '/' . $rel)) {
                    continue;
                }
                $targets[] = $rel;
            }
            $keep = array_fill_keys($package->files(), true);
            $obsolete = [];
            foreach (self::PRUNED_DIRS as $dir) {
                foreach ($this->filesUnder($dir, false) as $rel) {
                    if (!isset($keep[$rel])) {
                        $obsolete[] = $rel;
                    }
                }
            }
            $this->preflight($targets, $obsolete);
            if ($backup) {
                $this->backup($from);
            }
            $staged = $this->stage($package, $targets);
            $this->swap($staged);
            foreach ($obsolete as $rel) {
                @unlink($this->root . '/' . $rel);
            }
            foreach (self::PRUNED_DIRS as $dir) {
                $this->removeEmptyDirs($this->root . '/' . $dir);
            }
            clearstatcache();
            self::resetOpcache($this->root, $targets);
            return ['from' => $from, 'to' => $package->version()];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource
     */
    private function lock()
    {
        $dir = $this->backupDir();
        if ($dir === null || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            throw new RuntimeException('Nothing was changed: could not create the backup folder.');
        }
        $handle = @fopen($dir . '/.update.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Nothing was changed: could not create the update lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another update is already running. Try again in a minute.');
        }
        return $handle;
    }

    /**
     * @param string[] $targets
     * @param string[] $obsolete
     */
    private function preflight(array $targets, array $obsolete): void
    {
        $bad = [];
        foreach ($targets as $rel) {
            $dest = $this->root . '/' . $rel;
            if (is_link($dest) || (file_exists($dest) && !is_file($dest))) {
                $bad[] = $rel . ' (not a regular file)';
            } elseif (file_exists($dest)) {
                if (!is_writable($dest) || !is_writable(dirname($dest))) {
                    $bad[] = $rel;
                }
            } else {
                $parent = dirname($dest);
                while (!file_exists($parent) && strlen($parent) > strlen($this->root)) {
                    $parent = dirname($parent);
                }
                if (!is_dir($parent) || !is_writable($parent)) {
                    $bad[] = $this->relative($parent);
                }
            }
        }
        foreach ($obsolete as $rel) {
            if (!is_writable(dirname($this->root . '/' . $rel))) {
                $bad[] = $this->relative(dirname($this->root . '/' . $rel));
            }
        }
        if ($bad) {
            throw new RuntimeException('Nothing was changed: PHP cannot write to ' . self::listing(array_values(array_unique($bad))) . '.');
        }
    }

    private function backup(string $from): void
    {
        $dir = (string) $this->backupDir();
        $path = $dir . '/pikarelay-' . $from . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Nothing was changed: could not create the backup ' . $this->relative($path) . '.');
        }
        $files = $this->managedFiles();
        foreach ($files as $rel) {
            $zip->addFile($this->root . '/' . $rel, Package::PREFIX . $rel);
        }
        if (!in_array('src/VERSION', $files, true)) {
            $zip->addFromString(Package::PREFIX . 'src/VERSION', $from);
        }
        if (!$zip->close()) {
            @unlink($path);
            throw new RuntimeException('Nothing was changed: could not write the backup ' . $this->relative($path) . '.');
        }
        @chmod($path, 0600);
        foreach (array_slice($this->backups(), self::KEEP_BACKUPS) as $old) {
            @unlink($old['path']);
        }
    }

    /**
     * Writes every new file next to its target. Returns relative path => staged file.
     *
     * @param string[] $targets
     * @return array<string, string>
     */
    private function stage(Package $package, array $targets): array
    {
        $staged = [];
        try {
            foreach ($targets as $rel) {
                $dest = $this->root . '/' . $rel;
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('could not create ' . $this->relative($dir) . '.');
                }
                $tmp = $dir . '/' . self::STAGE_PREFIX . bin2hex(random_bytes(4)) . '-' . basename($dest);
                $data = $package->contents($rel);
                $written = @file_put_contents($tmp, $data);
                if ($written !== strlen($data)) {
                    @unlink($tmp);
                    throw new RuntimeException('could not write ' . $rel . ' (is the disk full?).');
                }
                $staged[$rel] = $tmp;
                @chmod($tmp, is_file($dest) ? (fileperms($dest) & 0777) : 0644);
            }
        } catch (RuntimeException $e) {
            foreach ($staged as $tmp) {
                @unlink($tmp);
            }
            throw new RuntimeException('Nothing was changed: ' . $e->getMessage(), 0, $e);
        }
        return $staged;
    }

    /**
     * Moves the staged files over the live ones, index.php last.
     *
     * @param array<string, string> $staged
     */
    private function swap(array $staged): void
    {
        if (isset($staged['index.php'])) {
            $index = $staged['index.php'];
            unset($staged['index.php']);
            $staged['index.php'] = $index;
        }
        $total = count($staged);
        $done = 0;
        foreach ($staged as $rel => $tmp) {
            if (!@rename($tmp, $this->root . '/' . $rel)) {
                foreach ($staged as $left) {
                    @unlink($left);
                }
                throw new RuntimeException('The update stopped after replacing ' . $done . ' of ' . $total
                    . ' files (could not replace ' . $rel . '). Restore the backup, or upload the release again.');
            }
            unset($staged[$rel]);
            $done++;
        }
    }

    /**
     * The files that make up the running code, as package-relative paths.
     *
     * @return string[]
     */
    private function managedFiles(): array
    {
        $files = [];
        foreach (self::TOP_FILES as $name) {
            if (is_file($this->root . '/' . $name)) {
                $files[] = $name;
            }
        }
        foreach (self::MANAGED_DIRS as $dir) {
            foreach ($this->filesUnder($dir, true) as $rel) {
                $files[] = $rel;
            }
        }
        foreach (self::PRIVATE_DIRS as $dir) {
            foreach (self::PRIVATE_SHIPPED as $name) {
                if (is_file($this->root . '/' . $dir . '/' . $name)) {
                    $files[] = $dir . '/' . $name;
                }
            }
        }
        return $files;
    }

    /**
     * Regular files under $dir (relative to the root), as relative paths. With $packable, only
     * names a Package accepts, and never leftover staging files.
     *
     * @return string[]
     */
    private function filesUnder(string $dir, bool $packable): array
    {
        $base = $this->root . '/' . $dir;
        if (!is_dir($base) || is_link($base)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            if (!$info->isFile() || $info->isLink()) {
                continue;
            }
            $rel = $dir . '/' . str_replace('\\', '/', substr($info->getPathname(), strlen($base) + 1));
            if ($packable && (!Package::isSafePath($rel) || strpos($info->getFilename(), self::STAGE_PREFIX) === 0)) {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);
        return $files;
    }

    /**
     * Everything an update may need to write: the install folder, its top-level files and every
     * file and folder of the code trees.
     *
     * @return string[]
     */
    private function pathsToWrite(): array
    {
        $paths = [$this->root];
        foreach (self::TOP_FILES as $name) {
            if (file_exists($this->root . '/' . $name)) {
                $paths[] = $this->root . '/' . $name;
            }
        }
        foreach (self::MANAGED_DIRS as $dir) {
            $base = $this->root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $paths[] = $base;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $info) {
                $paths[] = $info->getPathname();
            }
        }
        return $paths;
    }

    private function removeEmptyDirs(string $base): void
    {
        if (!is_dir($base)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $info) {
            if ($info->isDir() && !$info->isLink()) {
                @rmdir($info->getPathname());
            }
        }
    }

    /**
     * @return string[]
     */
    private function backupDirs(): array
    {
        return array_values(array_unique([$this->root . '/data/backups', $this->configDir . '/backups']));
    }

    /**
     * @param string[] $targets
     */
    private static function resetOpcache(string $root, array $targets): void
    {
        if (function_exists('opcache_invalidate')) {
            foreach ($targets as $rel) {
                if (substr($rel, -4) === '.php') {
                    @opcache_invalidate($root . '/' . $rel, true);
                }
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function relative(string $path): string
    {
        if ($path === $this->root) {
            return 'the PikaRelay folder';
        }
        return strpos($path, $this->root . '/') === 0 ? substr($path, strlen($this->root) + 1) : $path;
    }

    /**
     * @param string[] $items
     */
    private static function listing(array $items): string
    {
        $shown = array_slice($items, 0, 5);
        $more = count($items) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '');
    }
}
