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

/**
 * A published release and its zip asset, as reported by a ReleaseSource.
 */
final class Release
{
    /** @var string e.g. "1.2.0" */
    public $version;

    /** @var string e.g. "v1.2.0" */
    public $tag;

    /** @var string https download URL of pikarelay-<version>.zip */
    public $zipUrl;

    /** @var int size in bytes as reported by the source (0 if unknown) */
    public $size;

    /** @var string|null lower-case hex SHA-256 of the zip, when the source provides one */
    public $sha256;

    /** @var string release notes page */
    public $htmlUrl;

    public function __construct(string $version, string $tag, string $zipUrl, int $size, ?string $sha256, string $htmlUrl)
    {
        $this->version = $version;
        $this->tag = $tag;
        $this->zipUrl = $zipUrl;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->htmlUrl = $htmlUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'zipUrl' => $this->zipUrl,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'htmlUrl' => $this->htmlUrl,
        ];
    }

    /**
     * Rebuilds a release stored with toArray(); null if the data is not valid.
     *
     * @param mixed $data
     */
    public static function fromArray($data): ?Release
    {
        if (!is_array($data)) {
            return null;
        }
        foreach (['version', 'tag', 'zipUrl', 'htmlUrl'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return null;
            }
        }
        $sha = $data['sha256'] ?? null;
        if (!Version::isValid($data['version'])
            || strncmp($data['zipUrl'], 'https://', 8) !== 0
            || strncmp($data['htmlUrl'], 'https://', 8) !== 0
            || ($sha !== null && !(is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha)))) {
            return null;
        }
        return new Release($data['version'], $data['tag'], $data['zipUrl'], (int) ($data['size'] ?? 0), $sha, $data['htmlUrl']);
    }
}
