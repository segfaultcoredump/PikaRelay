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

use RuntimeException;

/**
 * Where updates come from. Production uses GitHubReleases; tests substitute a fake.
 */
interface ReleaseSource
{
    /**
     * Null when the source can be reached from this server, otherwise the reason it cannot.
     */
    public function unavailableReason(): ?string;

    /**
     * The newest published release.
     *
     * @throws RuntimeException with a message fit to show the admin
     */
    public function latest(): Release;

    /**
     * Downloads the release's zip to $file.
     *
     * @throws RuntimeException with a message fit to show the admin
     */
    public function download(Release $release, string $file): void;
}
