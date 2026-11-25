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

use PikaRelay\Auth\AdminAuth;
use PikaRelay\Config;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use PikaRelay\Update\Installer;
use PikaRelay\Version;
use RuntimeException;

final class ConfigAndAdminTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testConfigCreateSaveLoad(): void
    {
        $path = $this->dir . '/new-config.php';
        self::assertNull(Config::load($path));

        $config = Config::create($path, ['admin_user' => 'it\'s me', 'secure_mode' => false]);
        self::assertSame(0600, fileperms($path) & 0777);
        $config->set('settle_ms', 500);
        $config->save();

        $loaded = Config::load($path);
        self::assertNotNull($loaded);
        self::assertSame('it\'s me', $loaded->get('admin_user'));
        self::assertSame(500, $loaded->get('settle_ms'));
        self::assertFalse($loaded->isSecure());
        self::assertSame(600, $loaded->get('command_ttl_sec'), 'defaults fill missing keys');
        self::assertSame([], glob($this->dir . '/.config.php.*'), 'no temp files left behind');
    }

    public function testConfigCreateRefusesToOverwrite(): void
    {
        $path = $this->dir . '/new-config.php';
        Config::create($path, []);
        $this->expectException(RuntimeException::class);
        Config::create($path, []);
    }

    public function testAdminLoginAndThrottle(): void
    {
        $auth = $this->adminAuth();
        self::assertNotNull($auth->login('admin', 'wrong'));
        self::assertNull($auth->login('admin', 'correct horse'));
        self::assertTrue($auth->isLoggedIn());

        $auth->logout();
        for ($i = 0; $i < 5; $i++) {
            $auth->login('admin', 'wrong');
        }
        self::assertStringContainsString('Too many', (string) $auth->login('admin', 'correct horse'));
        self::assertFalse($auth->isLoggedIn());

        $this->clock->advance(900001);
        self::assertNull($auth->login('admin', 'correct horse'));
    }

    public function testRecoveryModeAcceptsBlankPasswordOnly(): void
    {
        $this->config->set('admin_hash', '');
        $auth = $this->adminAuth();
        self::assertTrue($auth->isRecoveryMode());
        self::assertNotNull($auth->login('anyone', 'guess'));
        self::assertNull($auth->login('anyone', ''));
        self::assertTrue($auth->isLoggedIn());
    }

    public function testSessionIdleTimeout(): void
    {
        $auth = $this->adminAuth();
        $auth->login('admin', 'correct horse');
        $this->clock->advance(14400001);
        self::assertFalse($auth->isLoggedIn());
    }

    public function testCsrfIsChecked(): void
    {
        $token = $this->adminAuth()->csrfToken();
        (new AdminAuth($this->config, $this->db, $this->clock, new Request('POST', '/admin/x', [], null, ['csrf' => $token])))->checkCsrf();
        $this->expectExceptionMessage('form is stale');
        (new AdminAuth($this->config, $this->db, $this->clock, new Request('POST', '/admin/x', [], null, ['csrf' => 'forged'])))->checkCsrf();
    }

    public function testDashboardShowsLoginWhenLoggedOut(): void
    {
        $response = $this->call('GET', '/');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Admin log in', $response->body());
        self::assertSame(303, $this->call('GET', '/admin')->status());
        self::assertSame(403, $this->call('POST', '/admin/purgeReads')->status());
    }

    public function testDashboardListsReadersWhenLoggedIn(): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => '<b>Finish</b>', 'battery' => 50], 'reader');
        $this->adminAuth()->login('admin', 'correct horse');
        $body = $this->call('GET', '/')->body();
        self::assertStringContainsString('pr4-01', $body);
        self::assertStringContainsString('&lt;b&gt;Finish&lt;/b&gt;', $body, 'reader data is HTML-escaped');
        self::assertStringNotContainsString('<b>Finish</b>', $body);
        self::assertStringContainsString('50%', $body);
    }

    public function testAdminCreatesKeyThatThenWorks(): void
    {
        $auth = $this->adminAuth();
        $auth->login('admin', 'correct horse');
        $form = ['csrf' => $auth->csrfToken(), 'role' => 'reader', 'label' => 'Finish', 'api_key' => 'abcd', 'api_secret' => 'wxyz'];
        $response = $this->app->handle(new Request('POST', '/admin/createKey', [], null, $form));
        self::assertSame(303, $response->status());
        self::assertSame('secret', $_SESSION['flash'][0]['type']);

        $put = $this->call('PUT', '/readerStatus/pr4-01', ['a' => 1], null, ['X-Pika-Key' => 'abcd', 'X-Pika-Secret' => 'wxyz']);
        self::assertSame(204, $put->status());
    }

    public function testAdminPageShowsVersion(): void
    {
        $this->adminAuth()->login('admin', 'correct horse');
        $body = $this->call('GET', '/admin')->body();
        self::assertStringContainsString('PikaRelay ' . Version::current() . '</p>', $body);
    }

    public function testUpdateCardOnGitCheckoutSaysManual(): void
    {
        $this->releases->release = FakeReleaseSource::release('9.9.9');
        $this->adminAuth()->login('admin', 'correct horse');
        $body = $this->call('GET', '/admin')->body();
        self::assertStringContainsString('Latest release: <strong>9.9.9</strong>', $body);
        self::assertStringContainsString('cannot update PikaRelay automatically', $body);
        self::assertStringContainsString('git checkout', $body);
        self::assertStringNotContainsString('/admin/uploadUpdate', $body);
    }

    public function testUpdateCardOffersUpdateUploadAndRestore(): void
    {
        $root = $this->fakeInstall();
        $this->releases->release = FakeReleaseSource::release('1.1.0');
        $this->adminAuth()->login('admin', 'correct horse');
        $body = $this->call('GET', '/admin')->body();
        self::assertStringContainsString('Update to 1.1.0', $body);
        self::assertStringContainsString('/admin/uploadUpdate', $body);
        self::assertStringContainsString('enctype="multipart/form-data"', $body);
        self::assertStringNotContainsString('/admin/restoreUpdate', $body, 'no backups yet');

        // Upload and install 1.2.0.
        $zip = ReleaseZip::build($this->dir . '/upload.zip', '1.2.0');
        $response = $this->adminPost('uploadUpdate', [], ['package' => ['tmp_name' => $zip, 'name' => 'pikarelay-1.2.0.zip', 'size' => (int) filesize($zip), 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(303, $response->status());
        self::assertSame(['ok', 'Updated PikaRelay from 1.0.0 to 1.2.0.'], self::lastFlash());
        self::assertSame('1.2.0', file_get_contents($root . '/src/VERSION'));

        // The backup can be restored from the card.
        $body = $this->call('GET', '/admin')->body();
        self::assertStringContainsString('/admin/restoreUpdate', $body);
        $backup = $this->app->installer()->backups()[0]['file'];
        $this->adminPost('restoreUpdate', ['backup' => $backup]);
        self::assertSame(['ok', 'Restored PikaRelay 1.0.0 (was 1.2.0).'], self::lastFlash());
        self::assertSame('1.0.0', file_get_contents($root . '/src/VERSION'));
    }

    public function testInstallUpdateDownloadsAndChecksDigest(): void
    {
        $root = $this->fakeInstall();
        $zip = ReleaseZip::build($this->dir . '/dl.zip', '1.1.0');
        $this->releases->zip = $zip;
        $this->releases->release = FakeReleaseSource::release('1.1.0', str_repeat('0', 64));
        $this->adminAuth()->login('admin', 'correct horse');
        $this->call('GET', '/admin');   // runs the update check

        $this->adminPost('installUpdate', ['version' => '1.1.0']);
        self::assertStringContainsString('does not match the checksum', self::lastFlash()[1]);
        self::assertSame('1.0.0', file_get_contents($root . '/src/VERSION'));

        $this->releases->release = FakeReleaseSource::release('1.1.0', (string) hash_file('sha256', $zip));
        $this->adminPost('checkUpdate');
        $this->adminPost('installUpdate', ['version' => '1.0.9']);
        self::assertStringContainsString('changed while the page was open', self::lastFlash()[1]);
        $this->adminPost('installUpdate', ['version' => '1.1.0']);
        self::assertSame(['ok', 'Updated PikaRelay from 1.0.0 to 1.1.0.'], self::lastFlash());
        self::assertSame('1.1.0', file_get_contents($root . '/src/VERSION'));
    }

    public function testUploadErrorsAreExplained(): void
    {
        $this->fakeInstall();
        $this->adminAuth()->login('admin', 'correct horse');
        $this->adminPost('uploadUpdate');
        self::assertSame(['error', 'Choose a release zip to upload.'], self::lastFlash());

        $this->adminPost('uploadUpdate', [], ['package' => ['tmp_name' => '', 'name' => 'x.zip', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]]);
        self::assertStringContainsString('upload_max_filesize', self::lastFlash()[1]);

        file_put_contents($this->dir . '/junk.zip', 'junk');
        $this->adminPost('uploadUpdate', [], ['package' => ['tmp_name' => $this->dir . '/junk.zip', 'name' => 'junk.zip', 'size' => 4, 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(['error', 'The file is not a zip archive.'], self::lastFlash());
    }

    public function testOversizedPostGetsAClearMessage(): void
    {
        $this->adminAuth()->login('admin', 'correct horse');
        $request = new Request('POST', '/admin/uploadUpdate', ['Content-Length' => (string) PHP_INT_MAX], null, []);
        $response = $this->app->handle($request);
        self::assertSame(303, $response->status());
        self::assertStringContainsString('post_max_size', self::lastFlash()[1]);
    }

    /**
     * A fake 1.0.0 installation that the app's installer updates instead of this repository.
     */
    private function fakeInstall(): string
    {
        $root = $this->dir . '/install';
        ReleaseZip::install($root, '1.0.0', ['config/config.php' => '<?php return [];']);
        $this->app->setInstaller(new Installer($root, $root . '/config'));
        return $root;
    }

    /**
     * @param array<string, string> $form
     * @param array<string, array{tmp_name: string, name: string, size: int, error: int}> $files
     */
    private function adminPost(string $action, array $form = [], array $files = []): Response
    {
        $form += ['csrf' => $this->adminAuth()->csrfToken()];
        return $this->app->handle(new Request('POST', '/admin/' . $action, [], null, $form, '', '', false, '127.0.0.1', $files));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function lastFlash(): array
    {
        $flash = end($_SESSION['flash']);
        return [(string) $flash['type'], (string) $flash['message']];
    }

    private function adminAuth(): AdminAuth
    {
        return new AdminAuth($this->config, $this->db, $this->clock, new Request('GET', '/'));
    }
}
