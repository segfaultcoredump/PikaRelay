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

    private function adminAuth(): AdminAuth
    {
        return new AdminAuth($this->config, $this->db, $this->clock, new Request('GET', '/'));
    }
}
