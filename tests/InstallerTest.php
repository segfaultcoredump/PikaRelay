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
use PikaRelay\Update\Installer;
use PikaRelay\Update\Package;
use RuntimeException;

final class InstallerTest extends TestCase
{
    /** @var string */
    private $tmp;

    /** @var string */
    private $root;

    /** @var Installer */
    private $installer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pikarelay-inst-' . bin2hex(random_bytes(6));
        $this->root = $this->tmp . '/pikarelay';
        mkdir($this->root, 0755, true);
        ReleaseZip::install($this->root, '1.0.0', [
            'src/Old.php' => "<?php // removed in 1.1.0\n",
            'src/Gone/Only.php' => "<?php // whole folder removed\n",
            'config/config.php' => "<?php return ['secret' => 1];\n",
            'data/relay.sqlite' => 'DATABASE',
        ]);
        $this->installer = new Installer($this->root, $this->root . '/config');
    }

    protected function tearDown(): void
    {
        ReleaseZip::removeDir($this->tmp);
    }

    public function testInstallReplacesPrunesAndKeepsPrivateFiles(): void
    {
        self::assertSame([], $this->installer->problems());
        $zip = ReleaseZip::build($this->tmp . '/new.zip', '1.1.0', [
            'src/New.php' => "<?php // new\n",
            'config/config.php' => "<?php return ['evil' => 1];\n",
            'data/relay.sqlite' => 'EVIL',
        ]);

        $result = $this->installer->install(new Package($zip));

        self::assertSame(['from' => '1.0.0', 'to' => '1.1.0'], $result);
        self::assertSame("<?php // App 1.1.0\n", file_get_contents($this->root . '/src/App.php'));
        self::assertSame("<?php // index 1.1.0\n", file_get_contents($this->root . '/index.php'));
        self::assertSame("deny 1.1.0\n", file_get_contents($this->root . '/data/.htaccess'));
        self::assertFileExists($this->root . '/src/New.php');
        self::assertFileDoesNotExist($this->root . '/src/Old.php');
        self::assertDirectoryDoesNotExist($this->root . '/src/Gone');
        self::assertSame("<?php return ['secret' => 1];\n", file_get_contents($this->root . '/config/config.php'));
        self::assertSame('DATABASE', file_get_contents($this->root . '/data/relay.sqlite'));
        self::assertSame([], glob($this->root . '/{,*/,*/*/}.pikanew-*', GLOB_BRACE) ?: []);

        $backups = $this->installer->backups();
        self::assertCount(1, $backups);
        self::assertSame('1.0.0', $backups[0]['version']);
        self::assertStringStartsWith($this->root . '/data/backups/', $backups[0]['path']);
        $backup = new Package($backups[0]['path']);
        self::assertSame('1.0.0', $backup->version());
        self::assertContains('src/Old.php', $backup->files());
        self::assertNotContains('config/config.php', $backup->files());
        self::assertNotContains('data/relay.sqlite', $backup->files());

        $restored = $this->installer->restore($backups[0]['file']);
        self::assertSame(['from' => '1.1.0', 'to' => '1.0.0'], $restored);
        self::assertSame("<?php // App 1.0.0\n", file_get_contents($this->root . '/src/App.php'));
        self::assertFileExists($this->root . '/src/Old.php');
        self::assertFileDoesNotExist($this->root . '/src/New.php');
        self::assertSame('DATABASE', file_get_contents($this->root . '/data/relay.sqlite'));
    }

    public function testKeepsThreeBackups(): void
    {
        $seen = [];
        foreach (['1.1.0', '1.2.0', '1.3.0', '1.4.0'] as $i => $version) {
            $this->installer->install(new Package(ReleaseZip::build($this->tmp . '/r.zip', $version)));
            foreach ($this->installer->backups() as $b) {
                if (!isset($seen[$b['path']])) {
                    // Give each backup a distinct age; the test takes less than a second.
                    touch($b['path'], time() - 100 + $i);
                    $seen[$b['path']] = true;
                }
            }
        }
        $versions = array_column($this->installer->backups(), 'version');
        self::assertSame(['1.3.0', '1.2.0', '1.1.0'], $versions);
    }

    public function testBackupFallsBackToConfigFolder(): void
    {
        chmod($this->root . '/data', 0555);
        if (is_writable($this->root . '/data')) {
            self::markTestSkipped('running as root');
        }
        self::assertSame($this->root . '/config/backups', $this->installer->backupDir());
        chmod($this->root . '/data', 0755);
    }

    public function testReadOnlyDataFolderOnlySkipsItsPlaceholders(): void
    {
        chmod($this->root . '/data', 0555);
        if (is_writable($this->root . '/data')) {
            self::markTestSkipped('running as root');
        }
        $this->installer->install(new Package(ReleaseZip::build($this->tmp . '/r.zip', '1.1.0')));
        chmod($this->root . '/data', 0755);
        self::assertSame("<?php // App 1.1.0\n", file_get_contents($this->root . '/src/App.php'));
        self::assertSame("deny 1.0.0\n", file_get_contents($this->root . '/data/.htaccess'));
        self::assertSame("deny 1.1.0\n", file_get_contents($this->root . '/config/.htaccess'));
        self::assertSame('1.0.0', $this->installer->backups()[0]['version']);
        self::assertStringStartsWith($this->root . '/config/backups/', $this->installer->backups()[0]['path']);
    }

    public function testGitCheckoutIsRefused(): void
    {
        mkdir($this->root . '/.git');
        self::assertStringContainsString('git checkout', implode(' ', $this->installer->problems()));
        $this->expectExceptionMessage('Nothing was changed');
        $this->installer->install(new Package(ReleaseZip::build($this->tmp . '/r.zip', '1.1.0')));
    }

    public function testReadOnlyFileRefusesBeforeChangingAnything(): void
    {
        chmod($this->root . '/src/Web/View.php', 0444);
        chmod($this->root . '/src/Web', 0555);
        if (is_writable($this->root . '/src/Web')) {
            self::markTestSkipped('running as root');
        }
        self::assertStringContainsString('src/Web', implode(' ', $this->installer->problems()));
        try {
            $this->installer->install(new Package(ReleaseZip::build($this->tmp . '/r.zip', '1.1.0')));
            self::fail('install should have been refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        self::assertSame("<?php // App 1.0.0\n", file_get_contents($this->root . '/src/App.php'));
        self::assertSame([], $this->installer->backups());
    }

    public function testConcurrentUpdateIsRefused(): void
    {
        mkdir($this->root . '/data/backups');
        $lock = fopen($this->root . '/data/backups/.update.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX);
        try {
            $this->expectExceptionMessage('already running');
            $this->installer->install(new Package(ReleaseZip::build($this->tmp . '/r.zip', '1.1.0')));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testRestoreUnknownBackup(): void
    {
        $this->expectExceptionMessage('no longer exists');
        $this->installer->restore('../../etc/passwd');
    }

    public function testIsProtected(): void
    {
        self::assertTrue(Installer::isProtected('config/config.php'));
        self::assertTrue(Installer::isProtected('config/.config.php.tmp'));
        self::assertTrue(Installer::isProtected('data/relay.sqlite'));
        self::assertTrue(Installer::isProtected('data/backups/x.zip'));
        self::assertFalse(Installer::isProtected('config/.htaccess'));
        self::assertFalse(Installer::isProtected('data/probe.txt'));
        self::assertFalse(Installer::isProtected('src/Config.php'));
        self::assertFalse(Installer::isProtected('index.php'));
    }
}
