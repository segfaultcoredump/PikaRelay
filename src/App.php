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

namespace PikaRelay;

use ErrorException;
use PDOException;
use PikaRelay\Api\CommandsController;
use PikaRelay\Api\ReaderStatusController;
use PikaRelay\Api\ReadsController;
use PikaRelay\Auth\ApiAuth;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use PikaRelay\Http\Router;
use PikaRelay\Update\GitHubReleases;
use PikaRelay\Update\HttpClient;
use PikaRelay\Update\Installer;
use PikaRelay\Update\ReleaseSource;
use PikaRelay\Web\AdminController;
use PikaRelay\Web\SetupController;
use PikaRelay\Web\View;
use Throwable;

final class App
{
    /** First path segments that belong to the JSON API; everything else is the web UI. */
    public const API_ROOTS = ['readerStatus', 'readerList', 'putReads', 'getReads', 'commands'];

    /** @var Config|null null until setup has run */
    private $config;

    /** @var Clock */
    private $clock;

    /** @var Database|null */
    private $db;

    /** @var int */
    private $pageLimit;

    /** @var ReleaseSource|null */
    private $releaseSource;

    /** @var Installer|null */
    private $installer;

    /** @var int */
    private $maxBatch;

    public function __construct(?Config $config, ?Database $db = null, ?Clock $clock = null, int $pageLimit = 10000, int $maxBatch = 2000)
    {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock ?? new Clock();
        $this->pageLimit = $pageLimit;
        $this->maxBatch = $maxBatch;
    }

    public static function fromEnvironment(): App
    {
        ini_set('display_errors', '0');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity) || $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        return new App(Config::load(Config::defaultPath()));
    }

    public function config(): ?Config
    {
        return $this->config;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function db(): Database
    {
        if ($this->db === null) {
            if ($this->config === null) {
                throw new HttpException(503, 'PikaRelay has not been set up yet');
            }
            $this->db = Database::fromConfig((array) $this->config->get('db'), PIKA_ROOT);
        }
        return $this->db;
    }

    /**
     * Where software updates come from (GitHub unless a test substitutes a fake).
     */
    public function releaseSource(): ReleaseSource
    {
        if ($this->releaseSource === null) {
            $this->releaseSource = new GitHubReleases(new HttpClient('PikaRelay/' . Version::current()));
        }
        return $this->releaseSource;
    }

    public function setReleaseSource(ReleaseSource $source): void
    {
        $this->releaseSource = $source;
    }

    /**
     * Installs software updates over this installation.
     */
    public function installer(): Installer
    {
        if ($this->installer === null) {
            $configPath = $this->config !== null ? $this->config->path() : Config::defaultPath();
            $this->installer = new Installer(PIKA_ROOT, dirname($configPath));
        }
        return $this->installer;
    }

    public function setInstaller(Installer $installer): void
    {
        $this->installer = $installer;
    }

    public function handle(Request $request): Response
    {
        $isApi = in_array($request->segment(0), self::API_ROOTS, true);
        try {
            try {
                if ($this->config === null) {
                    if ($isApi) {
                        throw new HttpException(503, 'PikaRelay has not been set up yet');
                    }
                    $response = (new SetupController($this, $request))->handle();
                } else {
                    $response = $this->router($request)->dispatch($request);
                }
            } catch (PDOException $e) {
                error_log('PikaRelay database error: ' . $e->getMessage());
                throw new HttpException(503, 'Database unavailable');
            }
        } catch (HttpException $e) {
            $response = $isApi
                ? Response::json(['error' => $e->getMessage()], $e->status())
                : View::errorPage($request, $e->status(), $e->getMessage());
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (Throwable $e) {
            error_log('PikaRelay error: ' . $e);
            $response = $isApi
                ? Response::json(['error' => 'Internal server error'], 500)
                : View::errorPage($request, 500, 'Internal server error. Details are in the PHP error log.');
        }
        return self::withSecurityHeaders($response);
    }

    private function router(Request $request): Router
    {
        $config = $this->config;
        assert($config !== null);
        $router = new Router();

        $apiAuth = function () use ($config): ApiAuth {
            return new ApiAuth($config, $this->db(), $this->clock);
        };
        $status = function () use ($apiAuth): ReaderStatusController {
            return new ReaderStatusController($this->db(), $apiAuth(), $this->clock);
        };
        $reads = function () use ($apiAuth, $config): ReadsController {
            return new ReadsController(
                $this->db(),
                $apiAuth(),
                $this->clock,
                max(0, (int) $config->get('settle_ms')),
                $this->pageLimit,
                $this->maxBatch
            );
        };
        $commands = function () use ($apiAuth, $config): CommandsController {
            return new CommandsController(
                $this->db(),
                $apiAuth(),
                $this->clock,
                max(1, (int) $config->get('command_ttl_sec')) * 1000,
                max(1, (int) $config->get('max_rewind_days')) * 86400000
            );
        };

        $router->add('PUT', '/readerStatus/{id}', function (string $id) use ($status, $request): Response {
            return $status()->put($request, $id);
        });
        $router->add('GET', '/readerStatus/{id}', function (string $id) use ($status, $request): Response {
            return $status()->get($request, $id);
        });
        $router->add('GET', '/readerList', function () use ($status, $request): Response {
            return $status()->list($request);
        });
        $router->add('POST', '/putReads/{id}', function (string $id) use ($reads, $request): Response {
            return $reads()->put($request, $id);
        });
        $router->add('GET', '/getReads/{id}/{since}', function (string $id, string $since) use ($reads, $request): Response {
            return $reads()->since($request, $id, $since);
        });
        $router->add('GET', '/getReads/{id}/{from}/{to}', function (string $id, string $from, string $to) use ($reads, $request): Response {
            return $reads()->range($request, $id, $from, $to);
        });
        $router->add('POST', '/commands/{id}', function (string $id) use ($commands, $request): Response {
            return $commands()->post($request, $id);
        });
        $router->add('GET', '/commands/{id}', function (string $id) use ($commands, $request): Response {
            return $commands()->pending($request, $id);
        });
        $router->add('DELETE', '/commands/{id}/{commandId}', function (string $id, string $commandId) use ($commands, $request): Response {
            return $commands()->ack($request, $id, $commandId);
        });

        AdminController::register($router, $this, $config, $request);
        return $router;
    }

    private static function withSecurityHeaders(Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
        ];
        foreach ($headers as $name => $value) {
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }
}
