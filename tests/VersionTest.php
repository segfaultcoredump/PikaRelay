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
use PikaRelay\Version;

final class VersionTest extends TestCase
{
    /** @var string */
    private $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/pikarelay-version-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testMissingFileIsDev(): void
    {
        self::assertSame('dev', Version::fromFile($this->file));
    }

    public function testReadsTrimmedVersion(): void
    {
        file_put_contents($this->file, "1.2.0\n");
        self::assertSame('1.2.0', Version::fromFile($this->file));
        file_put_contents($this->file, '1.2.0-3-gabc1234');
        self::assertSame('1.2.0-3-gabc1234', Version::fromFile($this->file));
    }

    public function testRejectsJunk(): void
    {
        foreach (['', "  \n", '<script>', '1.0 beta', str_repeat('9', 65)] as $junk) {
            file_put_contents($this->file, $junk);
            self::assertSame('dev', Version::fromFile($this->file), var_export($junk, true));
        }
    }
}
