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
 * Minimal HTTPS-only GET client for the update check and download. Uses ext-curl when it is
 * installed, otherwise PHP's https stream wrapper. Redirects are followed (at most 5) but only to
 * https URLs, and responses larger than the given limit are refused.
 *
 * Failures throw RuntimeException; for an HTTP error status the exception code is that status.
 */
class HttpClient
{
    private const MAX_REDIRECTS = 5;

    /** @var string */
    private $userAgent;

    public function __construct(string $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Null when outgoing HTTPS requests are possible, otherwise the reason they are not.
     */
    public function unavailableReason(): ?string
    {
        if (function_exists('curl_init')) {
            return null;
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return 'PHP cannot make outgoing HTTPS requests here: install the curl extension or enable allow_url_fopen.';
        }
        if (!extension_loaded('openssl')) {
            return 'PHP cannot make outgoing HTTPS requests here: the openssl extension is missing.';
        }
        return null;
    }

    public function get(string $url, string $accept, int $timeoutSec, int $maxBytes): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            throw new RuntimeException('Could not open a temporary buffer.');
        }
        try {
            $this->fetch($url, $accept, $out, $timeoutSec, $maxBytes);
            rewind($out);
            return (string) stream_get_contents($out);
        } finally {
            fclose($out);
        }
    }

    public function download(string $url, string $file, int $timeoutSec, int $maxBytes): void
    {
        $out = @fopen($file, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write the temporary file ' . $file . '.');
        }
        try {
            $this->fetch($url, 'application/octet-stream', $out, $timeoutSec, $maxBytes);
        } finally {
            fclose($out);
        }
    }

    /**
     * @param resource $out
     */
    private function fetch(string $url, string $accept, $out, int $timeoutSec, int $maxBytes): void
    {
        if (strncmp($url, 'https://', 8) !== 0) {
            throw new RuntimeException('Refusing to fetch a non-https URL.');
        }
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        if (function_exists('curl_init')) {
            $this->fetchCurl($url, $accept, $out, $timeoutSec, $maxBytes);
        } else {
            $this->fetchStream($url, $accept, $out, $timeoutSec, $maxBytes);
        }
    }

    /**
     * @param resource $out
     */
    private function fetchCurl(string $url, string $accept, $out, int $timeoutSec, int $maxBytes): void
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not start an HTTPS request.');
        }
        $written = 0;
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($out, &$written, &$tooLarge, $maxBytes): int {
                $written += strlen($data);
                if ($written > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                return (int) fwrite($out, $data);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle);
        if ($tooLarge) {
            throw new RuntimeException('The response is larger than ' . $maxBytes . ' bytes.');
        }
        if ($ok === false) {
            throw new RuntimeException('Could not reach ' . self::host($url) . ': ' . $error);
        }
        if ($status !== 200) {
            throw new RuntimeException(self::host($url) . ' answered HTTP ' . $status . '.', $status);
        }
    }

    /**
     * @param resource $out
     */
    private function fetchStream(string $url, string $accept, $out, int $timeoutSec, int $maxBytes): void
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: ' . $this->userAgent . "\r\nAccept: " . $accept . "\r\n",
                    'timeout' => $timeoutSec,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $in = @fopen($url, 'rb', false, $context);
            if ($in === false) {
                $last = error_get_last();
                throw new RuntimeException('Could not reach ' . self::host($url) . ($last ? ': ' . $last['message'] : '.'));
            }
            try {
                $meta = stream_get_meta_data($in);
                $headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];
                $status = 0;
                $location = null;
                foreach ($headers as $line) {
                    if (!is_string($line)) {
                        continue;
                    }
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                        $location = null;
                    } elseif (preg_match('/^Location:\s*(\S+)/i', $line, $m)) {
                        $location = $m[1];
                    }
                }
                if ($status >= 300 && $status < 400 && $location !== null) {
                    if ($location[0] === '/') {
                        $location = 'https://' . self::host($url) . $location;
                    }
                    if (strncmp($location, 'https://', 8) !== 0) {
                        throw new RuntimeException('Refusing a redirect to a non-https URL.');
                    }
                    $url = $location;
                    continue;
                }
                if ($status !== 200) {
                    throw new RuntimeException(self::host($url) . ' answered HTTP ' . $status . '.', $status);
                }
                stream_set_timeout($in, $timeoutSec);
                $written = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException('The download from ' . self::host($url) . ' was interrupted.');
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        throw new RuntimeException('The response is larger than ' . $maxBytes . ' bytes.');
                    }
                    fwrite($out, $chunk);
                }
                return;
            } finally {
                fclose($in);
            }
        }
        throw new RuntimeException('Too many redirects from ' . self::host($url) . '.');
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : 'the server';
    }
}
