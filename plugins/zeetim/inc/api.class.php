<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

class PluginZeetimApi
{
    private $config;
    private $deadline;
    private $initialUrl;
    private $receivedBytes = 0;

    public function __construct(array $config, float $deadline)
    {
        $this->config = $config;
        $this->deadline = $deadline;
        $base = $this->validateUrl($config['base_url']);
        if (isset($base['query'])) {
            throw new RuntimeException('WMC base URL must not contain a query string.');
        }
        $this->initialUrl = $config['base_url'] . $config['endpoint'];
        $this->validateUrl($this->initialUrl);
        if ($config['token'] === '' || preg_match('/[\x00-\x20\x7f]/', $config['token'])) {
            throw new RuntimeException('Configure a non-empty Bearer token without whitespace.');
        }
        if ($config['ca_file'] !== '' && !is_readable($config['ca_file'])) {
            throw new RuntimeException('Configured CA bundle is not readable.');
        }
    }

    // Read an exact dot-separated JSON path; missing fields are schema errors.
    public static function value($document, string $path)
    {
        if ($path === '') {
            return $document;
        }
        foreach (explode('.', $path) as $part) {
            if (!is_object($document) || !property_exists($document, $part)) {
                throw new RuntimeException('Missing configured JSON path: ' . $path);
            }
            $document = $document->{$part};
        }
        return $document;
    }

    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('WMC requires a valid HTTPS URL without embedded credentials.');
        }
        return $parts;
    }

    private function resolveNext(string $next): string
    {
        $base = $this->validateUrl($this->initialUrl);
        $origin = 'https://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($next, '?') === 0) {
            $next = $origin . $base['path'] . $next;
        } elseif (strpos($next, '/') === 0 && strpos($next, '//') !== 0) {
            $next = $origin . $next;
        }
        $parts = $this->validateUrl($next);
        if (strtolower($parts['host']) !== strtolower($base['host'])
            || ($parts['port'] ?? 443) !== ($base['port'] ?? 443)
            || ($parts['path'] ?? '') !== ($base['path'] ?? '')) {
            throw new RuntimeException('Pagination URL leaves the configured API origin or path.');
        }
        return $next;
    }

    private function request(string $url)
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('Synchronization time budget exhausted.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }
        $body = '';
        $tooLarge = false;
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['token'],
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT => 'GLPI-ZeeTim/1.0.0',
            CURLOPT_CONNECTTIMEOUT => min($remaining, $this->config['connect_timeout']),
            CURLOPT_TIMEOUT => min($remaining, $this->config['request_timeout']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > $this->config['max_response_bytes']
                    || $this->receivedBytes + strlen($chunk) > $this->config['max_total_response_bytes']) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                $this->receivedBytes += strlen($chunk);
                return strlen($chunk);
            },
        ];
        if ($this->config['ca_file'] !== '') {
            $options[CURLOPT_CAINFO] = $this->config['ca_file'];
        }
        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Unable to configure cURL.');
            }
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($tooLarge) {
                throw new RuntimeException('WMC response exceeds the configured size limit.');
            }
            if ($result === false) {
                // Do not log response bodies, tokens, URLs, or cURL verbose traces.
                throw new RuntimeException('WMC transport failure; cURL error ' . curl_errno($handle));
            }
            if ($status !== 200) {
                throw new RuntimeException('WMC returned HTTP ' . $status . '; expected 200.');
            }
            try {
                return json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $exception) {
                throw new RuntimeException('WMC returned invalid JSON.');
            }
        } finally {
            curl_close($handle);
        }
    }

    public function fetchAll(): array
    {
        $records = [];
        $visited = [];
        $url = $this->initialUrl;
        $expectedTotal = null;
        for ($page = 1; $page <= $this->config['max_pages']; ++$page) {
            if (isset($visited[$url])) {
                throw new RuntimeException('Pagination loop detected.');
            }
            $visited[$url] = true;
            $document = $this->request($url);
            $items = self::value($document, $this->config['items_path']);
            if (!is_array($items)) {
                throw new RuntimeException('Configured items_path must identify a JSON array.');
            }
            if (count($records) + count($items) > $this->config['max_records']) {
                throw new RuntimeException('Inventory exceeds the configured record limit.');
            }
            foreach ($items as $item) {
                if (!is_object($item)) {
                    throw new RuntimeException('Every endpoint must be a JSON object.');
                }
                $records[] = $item;
            }
            if ($this->config['total_path'] !== '') {
                $total = self::value($document, $this->config['total_path']);
                if (!is_int($total) || $total < 0
                    || ($expectedTotal !== null && $expectedTotal !== $total)) {
                    throw new RuntimeException('Invalid or changing inventory total.');
                }
                $expectedTotal = $total;
            }
            $next = $this->config['next_path'] === '' ? null
                : self::value($document, $this->config['next_path']);
            if ($next === null || $next === '') {
                if ($expectedTotal !== null && $expectedTotal !== count($records)) {
                    throw new RuntimeException('Incomplete inventory: fetched count differs from total.');
                }
                return $records;
            }
            if (!is_string($next)) {
                throw new RuntimeException('Next page must be a URL string or null.');
            }
            $url = $this->resolveNext($next);
        }
        throw new RuntimeException('Pagination exceeds the configured page limit.');
    }
}
