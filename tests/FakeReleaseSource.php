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

use PikaRelay\Update\Release;
use PikaRelay\Update\ReleaseSource;
use RuntimeException;

/**
 * ReleaseSource for tests: never touches the network.
 */
final class FakeReleaseSource implements ReleaseSource
{
    /** @var Release|null the release latest() returns; null makes it throw $error */
    public $release;

    /** @var string */
    public $error = 'No release has been published on GitHub yet.';

    /** @var string|null zip file that download() copies */
    public $zip;

    /** @var int */
    public $latestCalls = 0;

    public static function release(string $version, ?string $sha256 = null): Release
    {
        return new Release(
            $version,
            'v' . $version,
            'https://github.com/PikaTimer/PikaRelay/releases/download/v' . $version . '/pikarelay-' . $version . '.zip',
            1000,
            $sha256,
            'https://github.com/PikaTimer/PikaRelay/releases/tag/v' . $version
        );
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function latest(): Release
    {
        $this->latestCalls++;
        if ($this->release === null) {
            throw new RuntimeException($this->error);
        }
        return $this->release;
    }

    public function download(Release $release, string $file): void
    {
        if ($this->zip === null) {
            throw new RuntimeException('Download failed.');
        }
        copy($this->zip, $file);
    }
}
