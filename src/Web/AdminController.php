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

namespace PikaRelay\Web;

use PikaRelay\Api\ReaderStatusController;
use PikaRelay\App;
use PikaRelay\Auth\AdminAuth;
use PikaRelay\Auth\ApiAuth;
use PikaRelay\Config;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use PikaRelay\Http\Router;
use PikaRelay\Http\Validator;
use PikaRelay\Version;
use Throwable;

/**
 * The admin web UI: login, the readers dashboard, API keys, maintenance and settings.
 */
final class AdminController
{
    private const DAY_MS = 86400000;

    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    /** @var Request */
    private $request;

    /** @var AdminAuth|null */
    private $auth;

    public function __construct(App $app, Config $config, Request $request)
    {
        $this->app = $app;
        $this->config = $config;
        $this->request = $request;
    }

    public static function register(Router $router, App $app, Config $config, Request $request): void
    {
        $make = static function () use ($app, $config, $request): AdminController {
            return new AdminController($app, $config, $request);
        };
        $router->add('GET', '/', static function () use ($make): Response {
            return $make()->dashboard();
        });
        $router->add('GET', '/login', static function () use ($make): Response {
            return $make()->loginPage();
        });
        $router->add('POST', '/login', static function () use ($make): Response {
            return $make()->login();
        });
        $router->add('POST', '/logout', static function () use ($make): Response {
            return $make()->logout();
        });
        $router->add('GET', '/setPassword', static function () use ($make): Response {
            return $make()->setPasswordPage();
        });
        $router->add('POST', '/setPassword', static function () use ($make): Response {
            return $make()->setPassword();
        });
        $router->add('GET', '/admin', static function () use ($make): Response {
            return $make()->adminPage();
        });
        $router->add('POST', '/admin/{action}', static function (string $action) use ($make): Response {
            return $make()->adminAction($action);
        });
    }

    public function dashboard(): Response
    {
        $auth = $this->auth();
        if (!$auth->isLoggedIn()) {
            return $this->loginForm('');
        }
        if ($auth->isRecoveryMode()) {
            return Response::redirect($this->request->url('/setPassword'));
        }
        $now = $this->app->clock()->nowMs();
        $readers = [];
        foreach ($this->db()->all('SELECT id, status_json, status_ms, last_read_ms FROM readers ORDER BY id') as $row) {
            $summary = ReaderStatusController::summarize($row);
            $statusMs = $row['status_ms'] === null ? null : (int) $row['status_ms'];
            $age = $statusMs === null ? null : $now - $statusMs;
            $readers[] = $summary + [
                'id' => (string) $row['id'],
                'statusMs' => $statusMs,
                'lastReadMs' => $row['last_read_ms'] === null ? null : (int) $row['last_read_ms'],
                // Same thresholds PikaDownloader uses for its online indicator.
                'health' => $age === null ? 'offline' : ($age < 20000 ? 'online' : ($age <= 120000 ? 'stale' : 'offline')),
            ];
        }
        return $this->page('dashboard', 'Readers', ['readers' => $readers, 'now' => $now]);
    }

    public function loginPage(): Response
    {
        if ($this->auth()->isLoggedIn()) {
            return Response::redirect($this->request->url('/'));
        }
        return $this->loginForm('');
    }

    public function login(): Response
    {
        $auth = $this->auth();
        $auth->checkCsrf();
        $error = $auth->login(trim($this->request->form('username')), $this->request->form('password'));
        if ($error !== null) {
            return $this->loginForm($error, 401);
        }
        return Response::redirect($this->request->url($auth->isRecoveryMode() ? '/setPassword' : '/'));
    }

    public function logout(): Response
    {
        $auth = $this->auth();
        $auth->checkCsrf();
        $auth->logout();
        return Response::redirect($this->request->url('/'));
    }

    public function setPasswordPage(): Response
    {
        $auth = $this->auth();
        if (!$auth->isLoggedIn()) {
            return Response::redirect($this->request->url('/'));
        }
        return $this->page('set_password', 'Set admin password', [
            'recovery' => $auth->isRecoveryMode(),
            'username' => (string) $this->config->get('admin_user'),
            'errors' => [],
        ]);
    }

    public function setPassword(): Response
    {
        $auth = $this->requireLogin(false);
        $auth->checkCsrf();
        $r = $this->request;
        $recovery = $auth->isRecoveryMode();
        $username = trim($r->form('username'));
        $password = $r->form('password');
        $errors = [];
        if (!$recovery && !password_verify($r->form('current'), (string) $this->config->get('admin_hash'))) {
            $errors[] = 'Current password is wrong.';
        }
        if (!preg_match(AdminAuth::USERNAME, $username)) {
            $errors[] = 'User name: use 1-64 letters, digits, ".", "_", "@" or "-".';
        }
        if (strlen($password) < AdminAuth::MIN_PASSWORD || strlen($password) > 200) {
            $errors[] = 'New password: use ' . AdminAuth::MIN_PASSWORD . ' to 200 characters.';
        } elseif (!hash_equals($password, $r->form('password2'))) {
            $errors[] = 'The two new passwords do not match.';
        }
        if (!$errors) {
            $this->config->set('admin_user', $username);
            $this->config->set('admin_hash', password_hash($password, PASSWORD_DEFAULT));
            try {
                $this->config->save();
                AdminAuth::flash('ok', 'Admin password saved.');
                return Response::redirect($r->url('/'));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $this->page('set_password', 'Set admin password', [
            'recovery' => $recovery,
            'username' => $username,
            'errors' => $errors,
        ], 422);
    }

    public function adminPage(): Response
    {
        $auth = $this->auth();
        if (!$auth->isLoggedIn() || $auth->isRecoveryMode()) {
            return Response::redirect($this->request->url('/'));
        }
        $db = $this->db();
        $now = $this->app->clock()->nowMs();
        $keys = [];
        foreach ($db->all('SELECT id, api_key, role, label, created_ms, last_used_ms FROM api_keys ORDER BY role, label, id') as $row) {
            $keys[] = [
                'id' => (int) $row['id'],
                'key' => (string) $row['api_key'],
                'role' => (string) $row['role'],
                'label' => (string) $row['label'],
                'createdMs' => (int) $row['created_ms'],
                'lastUsedMs' => $row['last_used_ms'] === null ? null : (int) $row['last_used_ms'],
            ];
        }
        $readers = [];
        foreach ($db->all('SELECT id, status_ms, first_seen_ms, last_read_ms FROM readers ORDER BY id') as $row) {
            $readers[] = [
                'id' => (string) $row['id'],
                'statusMs' => $row['status_ms'] === null ? null : (int) $row['status_ms'],
                'firstSeenMs' => (int) $row['first_seen_ms'],
                'lastReadMs' => $row['last_read_ms'] === null ? null : (int) $row['last_read_ms'],
            ];
        }
        return $this->page('admin', 'Admin', [
            'now' => $now,
            'version' => Version::current(),
            'secure' => $this->config->isSecure(),
            'https' => $this->request->isHttps(),
            'settings' => [
                'command_ttl_sec' => (int) $this->config->get('command_ttl_sec'),
                'max_rewind_days' => (int) $this->config->get('max_rewind_days'),
                'settle_ms' => (int) $this->config->get('settle_ms'),
            ],
            'keys' => $keys,
            'readers' => $readers,
            'readCount' => (int) $db->value('SELECT COUNT(*) FROM tag_reads'),
            'pendingCommands' => (int) $db->value(
                'SELECT COUNT(*) FROM commands WHERE acked_ms IS NULL AND expires_ms > ?',
                [$now]
            ),
            'oldCommands' => (int) $db->value(
                'SELECT COUNT(*) FROM commands WHERE acked_ms IS NOT NULL OR expires_ms <= ?',
                [$now]
            ),
            'suggestedKey' => bin2hex(random_bytes(8)),
            'suggestedSecret' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
            'csrf' => $auth->csrfToken(),
        ]);
    }

    public function adminAction(string $action): Response
    {
        $auth = $this->requireLogin();
        $auth->checkCsrf();
        $handlers = [
            'settings' => 'saveSettings',
            'createKey' => 'createKey',
            'deleteKey' => 'deleteKey',
            'purgeReads' => 'purgeReads',
            'purgeCommands' => 'purgeCommands',
            'deleteReader' => 'deleteReader',
            'deleteStaleReaders' => 'deleteStaleReaders',
        ];
        if (!isset($handlers[$action])) {
            throw new HttpException(404, 'Not found');
        }
        $method = $handlers[$action];
        $this->$method();
        return Response::redirect($this->request->url('/admin'));
    }

    private function saveSettings(): void
    {
        $r = $this->request;
        $ttl = self::intField($r->form('command_ttl_sec'), 10, 86400);
        $rewind = self::intField($r->form('max_rewind_days'), 1, 365);
        $settle = self::intField($r->form('settle_ms'), 0, 60000);
        if ($ttl === null || $rewind === null || $settle === null) {
            AdminAuth::flash('error', 'Settings not saved: command lifetime must be 10-86400 seconds, rewind range 1-365 days and settle time 0-60000 ms.');
            return;
        }
        $this->config->set('secure_mode', $r->form('secure_mode') === '1');
        $this->config->set('command_ttl_sec', $ttl);
        $this->config->set('max_rewind_days', $rewind);
        $this->config->set('settle_ms', $settle);
        try {
            $this->config->save();
            AdminAuth::flash('ok', 'Settings saved.');
        } catch (Throwable $e) {
            AdminAuth::flash('error', $e->getMessage());
        }
    }

    private function createKey(): void
    {
        $r = $this->request;
        $key = trim($r->form('api_key'));
        $secret = trim($r->form('api_secret'));
        $role = $r->form('role');
        $label = trim($r->form('label'));
        if (!in_array($role, ApiAuth::ROLES, true)) {
            AdminAuth::flash('error', 'Choose whether the key is for a reader or a client.');
            return;
        }
        if (!Validator::isCredential($key) || !Validator::isCredential($secret)) {
            AdminAuth::flash('error', 'Key and secret must each be 4-128 printable characters without spaces.');
            return;
        }
        if (!preg_match('/^[\x20-\x7E]{0,100}$/', $label)) {
            AdminAuth::flash('error', 'Label must be at most 100 printable characters.');
            return;
        }
        $db = $this->db();
        if ($db->value('SELECT 1 FROM api_keys WHERE api_key = ?', [$key]) !== null) {
            AdminAuth::flash('error', 'That key already exists. Choose a different key.');
            return;
        }
        $db->run(
            'INSERT INTO api_keys (api_key, secret_hash, role, label, created_ms) VALUES (?, ?, ?, ?, ?)',
            [$key, ApiAuth::hashSecret($secret, (string) $this->config->get('pepper')), $role, $label, $this->app->clock()->nowMs()]
        );
        AdminAuth::flash('secret', 'API key created. Copy the secret now: it is stored only as a hash and cannot be shown again.', [
            'key' => $key,
            'secret' => $secret,
            'role' => $role,
        ]);
    }

    private function deleteKey(): void
    {
        $id = self::intField($this->request->form('id'), 1, PHP_INT_MAX);
        if ($id !== null && $this->db()->run('DELETE FROM api_keys WHERE id = ?', [$id])->rowCount() > 0) {
            AdminAuth::flash('ok', 'API key deleted.');
        } else {
            AdminAuth::flash('error', 'API key not found.');
        }
    }

    private function purgeReads(): void
    {
        $days = self::intField($this->request->form('days'), 0, 3650);
        if ($days === null) {
            AdminAuth::flash('error', 'Days must be a whole number from 0 to 3650.');
            return;
        }
        $cutoff = $this->app->clock()->nowMs() - $days * self::DAY_MS;
        $deleted = $this->db()->run('DELETE FROM tag_reads WHERE received_ms < ?', [$cutoff])->rowCount();
        AdminAuth::flash('ok', 'Deleted ' . $deleted . ' reads.');
    }

    private function purgeCommands(): void
    {
        $deleted = $this->db()->run(
            'DELETE FROM commands WHERE acked_ms IS NOT NULL OR expires_ms <= ?',
            [$this->app->clock()->nowMs()]
        )->rowCount();
        AdminAuth::flash('ok', 'Deleted ' . $deleted . ' acknowledged or expired commands.');
    }

    private function deleteReader(): void
    {
        $id = $this->request->form('id');
        if (!preg_match(Validator::READER_ID, $id) || !$this->deleteReaders([$id])) {
            AdminAuth::flash('error', 'Reader not found.');
            return;
        }
        AdminAuth::flash('ok', 'Deleted reader ' . $id . ' with its reads and commands.');
    }

    private function deleteStaleReaders(): void
    {
        $days = self::intField($this->request->form('days'), 1, 3650);
        if ($days === null) {
            AdminAuth::flash('error', 'Days must be a whole number from 1 to 3650.');
            return;
        }
        $cutoff = $this->app->clock()->nowMs() - $days * self::DAY_MS;
        $ids = [];
        $rows = $this->db()->all(
            'SELECT id FROM readers WHERE COALESCE(status_ms, first_seen_ms) < ? AND COALESCE(last_read_ms, 0) < ?',
            [$cutoff, $cutoff]
        );
        foreach ($rows as $row) {
            $ids[] = (string) $row['id'];
        }
        $this->deleteReaders($ids);
        AdminAuth::flash('ok', 'Deleted ' . count($ids) . ' readers not heard from in ' . $days . ' days.');
    }

    /**
     * @param string[] $ids
     */
    private function deleteReaders(array $ids): bool
    {
        return (bool) $this->db()->transaction(function (Database $db) use ($ids): bool {
            $any = false;
            foreach ($ids as $id) {
                $db->run('DELETE FROM tag_reads WHERE reader_id = ?', [$id]);
                $db->run('DELETE FROM commands WHERE reader_id = ?', [$id]);
                $any = $db->run('DELETE FROM readers WHERE id = ?', [$id])->rowCount() > 0 || $any;
            }
            return $any;
        });
    }

    private function auth(): AdminAuth
    {
        if ($this->auth === null) {
            AdminAuth::startSession($this->request);
            $this->auth = new AdminAuth($this->config, $this->db(), $this->app->clock(), $this->request);
        }
        return $this->auth;
    }

    /**
     * Rejects the request (403) unless an admin is logged in. When $redirectRecovery is true,
     * it is also rejected while the admin still has to set a password (recovery mode).
     */
    private function requireLogin(bool $redirectRecovery = true): AdminAuth
    {
        $auth = $this->auth();
        if (!$auth->isLoggedIn()) {
            throw new HttpException(403, 'Please log in first.');
        }
        if ($redirectRecovery && $auth->isRecoveryMode()) {
            throw new HttpException(403, 'Set a new admin password first.');
        }
        return $auth;
    }

    private function db(): Database
    {
        return $this->app->db();
    }

    private function loginForm(string $error, int $status = 200): Response
    {
        return $this->page('login', 'Log in', [
            'error' => $error,
            'recovery' => $this->auth()->isRecoveryMode(),
        ], $status);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function page(string $template, string $title, array $vars, int $status = 200): Response
    {
        $auth = $this->auth();
        $loggedIn = !empty($_SESSION['admin']);
        $csrf = $auth->csrfToken();
        return View::page($this->request, $template, $title, $vars + ['csrf' => $csrf], [
            'loggedIn' => $loggedIn,
            'csrf' => $csrf,
            'flash' => AdminAuth::takeFlash(),
        ], $status);
    }

    private static function intField(string $value, int $min, int $max): ?int
    {
        $value = trim($value);
        if (!preg_match('/^[0-9]{1,18}$/', $value)) {
            return null;
        }
        $n = (int) $value;
        return $n < $min || $n > $max ? null : $n;
    }
}
