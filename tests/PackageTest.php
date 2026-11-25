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
use PikaRelay\Update\Package;
use RuntimeException;
use ZipArchive;

final class PackageTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pikarelay-pkg-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        ReleaseZip::removeDir($this->dir);
    }

    public function testValidPackage(): void
    {
        $package = new Package(ReleaseZip::build($this->dir . '/r.zip', '1.2.0'));
        self::assertSame('1.2.0', $package->version());
        self::assertContains('src/App.php', $package->files());
        self::assertContains('config/.htaccess', $package->files());
        self::assertSame("<?php // App 1.2.0\n", $package->contents('src/App.php'));
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: string, 2?: string}>
     */
    public function badPackages(): array
    {
        return [
            'traversal' => [['../evil.php' => 'x'], 'unsafe path'],
            'nested traversal' => [['src/../../evil.php' => 'x'], 'unsafe path'],
            'odd characters' => [['src/a b.php' => 'x'], 'unsafe path'],
            'missing VERSION' => [['src/VERSION' => null], 'src/VERSION is missing'],
            'missing index.php' => [['index.php' => null], 'index.php is missing'],
            'bad VERSION' => [['src/VERSION' => '<b>1</b>'], 'invalid src/VERSION'],
            'source code zip' => [[], 'Source code', 'PikaRelay-1.2.0/'],
            'no prefix' => [[], 'inside a "pikarelay" folder', ''],
        ];
    }

    /**
     * @dataProvider badPackages
     * @param array<string, string|null> $changes
     */
    public function testRejectsBadPackages(array $changes, string $message, string $prefix = 'pikarelay/'): void
    {
        $file = ReleaseZip::build($this->dir . '/r.zip', '1.2.0', $changes, $prefix);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        new Package($file);
    }

    public function testRejectsSymlinkEntry(): void
    {
        $file = ReleaseZip::build($this->dir . '/r.zip', '1.2.0', ['src/link.php' => '/etc/passwd']);
        $zip = new ZipArchive();
        $zip->open($file);
        $zip->setExternalAttributesName('pikarelay/src/link.php', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();
        $this->expectExceptionMessage('symbolic link');
        new Package($file);
    }

    public function testRejectsNonZip(): void
    {
        file_put_contents($this->dir . '/r.zip', 'not a zip');
        $this->expectExceptionMessage('not a zip archive');
        new Package($this->dir . '/r.zip');
    }

    public function testLimits(): void
    {
        $file = ReleaseZip::build($this->dir . '/r.zip', '1.2.0', ['src/big.txt' => str_repeat('a', 5000)]);
        try {
            new Package($file, 5);
            self::fail('entry limit not enforced');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('more than 5 entries', $e->getMessage());
        }
        $this->expectExceptionMessage('unpacks to more than 4000 bytes');
        new Package($file, 2000, 4000);
    }
}
