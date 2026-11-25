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

/**
 * Releases of the upstream repository on GitHub. Uses the "latest release" API, which skips
 * drafts and pre-releases, and the release's pikarelay-<version>.zip asset built by
 * .github/workflows/release.yml.
 */
final class GitHubReleases implements ReleaseSource
{
    public const REPO = 'PikaTimer/PikaRelay';
    public const RELEASES_URL = 'https://github.com/' . self::REPO . '/releases';
    private const API_URL = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
    private const DOWNLOAD_PREFIX = self::RELEASES_URL . '/download/';
    private const CHECK_TIMEOUT_SEC = 5;
    private const DOWNLOAD_TIMEOUT_SEC = 60;
    private const MAX_API_BYTES = 1048576;
    public const MAX_ZIP_BYTES = 20971520;

    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function unavailableReason(): ?string
    {
        return $this->http->unavailableReason();
    }

    public function latest(): Release
    {
        try {
            $body = $this->http->get(self::API_URL, 'application/vnd.github+json', self::CHECK_TIMEOUT_SEC, self::MAX_API_BYTES);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                throw new RuntimeException('No release has been published on GitHub yet.');
            }
            if ($e->getCode() === 403 || $e->getCode() === 429) {
                throw new RuntimeException('GitHub is limiting requests from this server. Try again later.');
            }
            throw new RuntimeException('Could not check GitHub for updates: ' . $e->getMessage());
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['tag_name']) || !is_string($data['tag_name'])) {
            throw new RuntimeException('GitHub returned an unexpected response.');
        }
        $tag = $data['tag_name'];
        $version = Version::fromTag($tag);
        if (!Version::isValid($version)) {
            throw new RuntimeException('The latest release has an unusable tag.');
        }
        $htmlUrl = isset($data['html_url']) && is_string($data['html_url']) && strpos($data['html_url'], self::RELEASES_URL . '/') === 0
            ? $data['html_url']
            : self::RELEASES_URL;
        $wanted = 'pikarelay-' . $version . '.zip';
        foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $wanted) {
                continue;
            }
            $url = $asset['browser_download_url'] ?? null;
            if (!is_string($url) || strpos($url, self::DOWNLOAD_PREFIX) !== 0) {
                continue;
            }
            $sha = null;
            if (isset($asset['digest']) && is_string($asset['digest']) && preg_match('/^sha256:([0-9a-f]{64})$/', $asset['digest'], $m)) {
                $sha = $m[1];
            }
            return new Release($version, $tag, $url, (int) ($asset['size'] ?? 0), $sha, $htmlUrl);
        }
        throw new RuntimeException('Release ' . $tag . ' has no ' . $wanted . ' file attached yet.');
    }

    public function download(Release $release, string $file): void
    {
        if (strpos($release->zipUrl, self::DOWNLOAD_PREFIX) !== 0) {
            throw new RuntimeException('Refusing to download an update from outside ' . self::RELEASES_URL . '.');
        }
        try {
            $this->http->download($release->zipUrl, $file, self::DOWNLOAD_TIMEOUT_SEC, self::MAX_ZIP_BYTES);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not download ' . $release->tag . ': ' . $e->getMessage());
        }
    }
}
