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
use PikaRelay\Update\GitHubReleases;
use PikaRelay\Update\HttpClient;
use RuntimeException;

final class GitHubReleasesTest extends TestCase
{
    private const DL = 'https://github.com/PikaTimer/PikaRelay/releases/download/';

    /**
     * @param string|RuntimeException $response
     */
    private static function source($response): GitHubReleases
    {
        $http = new class ($response) extends HttpClient {
            /** @var string|RuntimeException */
            private $response;

            /** @param string|RuntimeException $response */
            public function __construct($response)
            {
                parent::__construct('test');
                $this->response = $response;
            }

            public function get(string $url, string $accept, int $timeoutSec, int $maxBytes): string
            {
                if ($this->response instanceof RuntimeException) {
                    throw $this->response;
                }
                return $this->response;
            }
        };
        return new GitHubReleases($http);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private static function json(string $tag, array $assets): string
    {
        return (string) json_encode([
            'tag_name' => $tag,
            'html_url' => 'https://github.com/PikaTimer/PikaRelay/releases/tag/' . $tag,
            'assets' => $assets,
        ]);
    }

    public function testPicksTheReleaseZip(): void
    {
        $sha = str_repeat('ab', 32);
        $release = self::source(self::json('v1.2.0', [
            ['name' => 'other.zip', 'browser_download_url' => self::DL . 'v1.2.0/other.zip'],
            ['name' => 'pikarelay-1.2.0.zip', 'browser_download_url' => self::DL . 'v1.2.0/pikarelay-1.2.0.zip', 'size' => 56000, 'digest' => 'sha256:' . $sha],
        ]))->latest();
        self::assertSame('1.2.0', $release->version);
        self::assertSame('v1.2.0', $release->tag);
        self::assertSame(self::DL . 'v1.2.0/pikarelay-1.2.0.zip', $release->zipUrl);
        self::assertSame(56000, $release->size);
        self::assertSame($sha, $release->sha256);
        self::assertSame('https://github.com/PikaTimer/PikaRelay/releases/tag/v1.2.0', $release->htmlUrl);
    }

    public function testDigestIsOptional(): void
    {
        $release = self::source(self::json('1.2.0', [
            ['name' => 'pikarelay-1.2.0.zip', 'browser_download_url' => self::DL . '1.2.0/pikarelay-1.2.0.zip'],
        ]))->latest();
        self::assertNull($release->sha256);
    }

    public function testRejectsForeignAssetUrl(): void
    {
        $this->expectExceptionMessage('has no pikarelay-1.2.0.zip');
        self::source(self::json('v1.2.0', [
            ['name' => 'pikarelay-1.2.0.zip', 'browser_download_url' => 'https://evil.example/pikarelay-1.2.0.zip'],
        ]))->latest();
    }

    public function testMissingAsset(): void
    {
        $this->expectExceptionMessage('has no pikarelay-1.2.0.zip');
        self::source(self::json('v1.2.0', []))->latest();
    }

    public function testBadJson(): void
    {
        $this->expectExceptionMessage('unexpected response');
        self::source('<html>')->latest();
    }

    public function testNoReleaseYet(): void
    {
        $this->expectExceptionMessage('No release has been published');
        self::source(new RuntimeException('api.github.com answered HTTP 404.', 404))->latest();
    }

    public function testBadTag(): void
    {
        $this->expectExceptionMessage('unusable tag');
        self::source(self::json('v1 <b>', []))->latest();
    }
}
