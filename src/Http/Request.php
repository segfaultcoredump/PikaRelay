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

namespace PikaRelay\Http;

/**
 * An incoming request. Built from PHP globals in production and directly in tests.
 */
final class Request
{
    /** @var string */
    private $method;

    /** @var string route path, e.g. "/readerStatus/pr4-01" */
    private $path;

    /** @var array<string, string> lower-case header name => value */
    private $headers;

    /** @var string|null */
    private $body;

    /** @var bool */
    private $bodyFromInput;

    /** @var array<string, mixed> */
    private $form;

    /** @var string URL prefix for links: the install's base path, plus "/index.php" when rewriting is not in use */
    private $prefix;

    /** @var string the install's base path ("" at the web root), used for cookies and static files */
    private $basePath;

    /** @var bool */
    private $https;

    /** @var string */
    private $ip;

    /** @var array<string, array{tmp_name: string, name: string, size: int, error: int}> */
    private $files;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $form
     * @param array<string, array{tmp_name: string, name: string, size: int, error: int}> $files uploaded files
     */
    public function __construct(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        array $form = [],
        string $prefix = '',
        string $basePath = '',
        bool $https = false,
        string $ip = '127.0.0.1',
        array $files = []
    ) {
        $this->method = strtoupper($method);
        $this->path = $path === '' ? '/' : $path;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
        $this->body = $body;
        $this->bodyFromInput = false;
        $this->form = $form;
        $this->prefix = $prefix;
        $this->basePath = $basePath;
        $this->https = $https;
        $this->ip = $ip;
        $this->files = $files;
    }

    public static function fromGlobals(): Request
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', strtolower($key))] = $value;
            }
        }

        $uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '/';
        $qpos = strpos($uri, '?');
        $uriPath = $qpos === false ? $uri : substr($uri, 0, $qpos);
        $script = isset($server['SCRIPT_NAME']) ? (string) $server['SCRIPT_NAME'] : '/index.php';
        if (substr($script, -10) !== '/index.php') {
            $script = '/index.php';
        }
        $base = rtrim(dirname($script), '/\\');
        if ($base === '.') {
            $base = '';
        }

        if (strpos($uriPath, $script) === 0) {
            // Requested as /base/index.php/route: no URL rewriting needed.
            $route = substr($uriPath, strlen($script));
            $prefix = $script;
        } else {
            $route = strpos($uriPath, $base . '/') === 0 || $uriPath === $base ? substr($uriPath, strlen($base)) : $uriPath;
            // At the bare base URL we cannot tell whether rewriting works, so links use index.php.
            $prefix = ($route === '' || $route === '/') ? $script : $base;
        }

        $https = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || (isset($server['SERVER_PORT']) && (string) $server['SERVER_PORT'] === '443')
            || (isset($server['REQUEST_SCHEME']) && strtolower((string) $server['REQUEST_SCHEME']) === 'https');

        $request = new Request(
            isset($server['REQUEST_METHOD']) ? (string) $server['REQUEST_METHOD'] : 'GET',
            $route === '' ? '/' : $route,
            $headers,
            null,
            $_POST,
            $prefix,
            $base,
            $https,
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '',
            self::uploadedFiles($_FILES)
        );
        $request->bodyFromInput = true;
        return $request;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Decoded path segments; "/" gives [].
     *
     * @return string[]
     */
    public function segments(): array
    {
        $trimmed = trim($this->path, '/');
        if ($trimmed === '') {
            return [];
        }
        return array_map('rawurldecode', explode('/', $trimmed));
    }

    public function segment(int $index): string
    {
        $segments = $this->segments();
        return $segments[$index] ?? '';
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }

    /**
     * Returns the raw body, refusing bodies larger than $maxBytes with a 413.
     */
    public function body(int $maxBytes): string
    {
        $length = $this->header('content-length');
        if ($length !== null && ctype_digit($length) && (int) $length > $maxBytes) {
            throw new HttpException(413, 'Request body is larger than ' . $maxBytes . ' bytes');
        }
        if ($this->bodyFromInput && $this->body === null) {
            $handle = fopen('php://input', 'rb');
            $this->body = $handle === false ? '' : (string) stream_get_contents($handle, $maxBytes + 1);
            if ($handle !== false) {
                fclose($handle);
            }
        }
        $body = $this->body ?? '';
        if (strlen($body) > $maxBytes) {
            throw new HttpException(413, 'Request body is larger than ' . $maxBytes . ' bytes');
        }
        return $body;
    }

    /**
     * A submitted form field as a string ('' when missing or not a string).
     */
    public function form(string $name): string
    {
        $value = $this->form[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * A single uploaded file field: tmp_name, name, size and error (an UPLOAD_ERR_* code),
     * or null when the field was not sent.
     *
     * @return array{tmp_name: string, name: string, size: int, error: int}|null
     */
    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    /**
     * Single-file uploads from $_FILES. A file reported as received must really be an upload.
     *
     * @param array<mixed> $files
     * @return array<string, array{tmp_name: string, name: string, size: int, error: int}>
     */
    private static function uploadedFiles(array $files): array
    {
        $result = [];
        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
                continue;
            }
            $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
            if ($file['error'] === UPLOAD_ERR_OK && ($tmp === '' || !is_uploaded_file($tmp))) {
                continue;
            }
            $result[(string) $field] = [
                'tmp_name' => $tmp,
                'name' => isset($file['name']) && is_string($file['name']) ? $file['name'] : '',
                'size' => isset($file['size']) ? (int) $file['size'] : 0,
                'error' => $file['error'],
            ];
        }
        return $result;
    }

    public function hasForm(string $name): bool
    {
        return isset($this->form[$name]) && is_string($this->form[$name]);
    }

    /**
     * Builds a link to a route inside this installation, e.g. url('/admin').
     */
    public function url(string $route): string
    {
        return $this->prefix . $route;
    }

    /**
     * Link to a static file (asset) inside this installation; never goes through index.php.
     */
    public function assetUrl(string $file): string
    {
        return $this->basePath . '/' . ltrim($file, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isHttps(): bool
    {
        return $this->https;
    }

    public function ip(): string
    {
        return $this->ip;
    }
}
