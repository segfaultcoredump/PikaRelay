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

use PikaRelay\Update\UpdateChecker;

final class UpdateCheckerTest extends AppTestCase
{
    public function testCachesSuccessFor12Hours(): void
    {
        $this->releases->release = FakeReleaseSource::release('1.2.0');
        $checker = new UpdateChecker($this->db, $this->releases);

        $status = $checker->status(self::START_MS, true);
        self::assertSame('1.2.0', $status['release']->version);
        self::assertSame(self::START_MS, $status['checkedMs']);
        self::assertSame(1, $this->releases->latestCalls);

        $this->releases->release = FakeReleaseSource::release('1.3.0');
        self::assertSame('1.2.0', $checker->status(self::START_MS + UpdateChecker::OK_TTL_MS - 1, true)['release']->version);
        self::assertSame(1, $this->releases->latestCalls);
        self::assertSame('1.2.0', $checker->status(self::START_MS + UpdateChecker::OK_TTL_MS, false)['release']->version);
        self::assertSame('1.3.0', $checker->status(self::START_MS + UpdateChecker::OK_TTL_MS, true)['release']->version);
        self::assertSame(2, $this->releases->latestCalls);
    }

    public function testCachesFailureForOneHour(): void
    {
        $checker = new UpdateChecker($this->db, $this->releases);
        $status = $checker->status(self::START_MS, true);
        self::assertNull($status['release']);
        self::assertStringContainsString('No release', (string) $status['error']);
        $checker->status(self::START_MS + UpdateChecker::FAIL_TTL_MS - 1, true);
        self::assertSame(1, $this->releases->latestCalls);
        $checker->status(self::START_MS + UpdateChecker::FAIL_TTL_MS, true);
        self::assertSame(2, $this->releases->latestCalls);
    }

    public function testRefreshForcesCheck(): void
    {
        $checker = new UpdateChecker($this->db, $this->releases);
        $checker->status(self::START_MS, true);
        $this->releases->release = FakeReleaseSource::release('2.0.0');
        self::assertSame('2.0.0', $checker->refresh(self::START_MS + 1)['release']->version);
        self::assertSame('2.0.0', $checker->status(self::START_MS + 2, false)['release']->version);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public function versions(): array
    {
        return [
            ['1.2.0', '1.1.9', true],
            ['1.10.0', '1.9.0', true],
            ['v1.2.0', '1.2.0', false],
            ['1.2.0', 'v1.2.0', false],
            ['1.1.0', '1.2.0', false],
            ['1.0.0', 'dev', true],
            ['1.2.0', '1.2.0-rc1', true],
            ['1.2.0', '1.2.0-3-gabc1234', false],
            ['1.2.1', '1.2.0-3-gabc1234', true],
            ['1.2.0-rc1', '1.1.0', true],
            ['banana', '1.0.0', false],
        ];
    }

    /**
     * @dataProvider versions
     */
    public function testIsNewer(string $latest, string $current, bool $expected): void
    {
        self::assertSame($expected, UpdateChecker::isNewer($latest, $current));
    }
}
