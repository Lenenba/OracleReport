<?php

namespace App\Services\BiPublisher;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class BiPublisherService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('bi-publisher', []);
    }

    public function environments(): array
    {
        $envs = $this->config['environments'] ?? [];
        $output = [];

        foreach ($envs as $key => $env) {
            $output[$key] = [
                'key' => $key,
                'label' => $env['label'] ?? strtoupper((string) $key),
                'configured' => $this->validateEnvironment($env) === null,
                'base_url' => $env['base_url'] ?? null,
            ];
        }

        return $output;
    }

    public function parseParameters(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return [];
        }

        if (Str::startsWith($raw, ['{', '['])) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('Parameters JSON is invalid.');
            }

            return $this->normalizeParameters($decoded);
        }

        $pairs = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (!str_contains($line, '=')) {
                throw new InvalidArgumentException('Parameters must use key=value format.');
            }

            [$name, $valueRaw] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '') {
                throw new InvalidArgumentException('Parameter names cannot be empty.');
            }

            $values = array_map('trim', explode('|', (string) $valueRaw));
            $values = array_values(array_filter($values, static fn ($value) => $value !== ''));

            $pairs[$name] = $values === [] ? [''] : $values;
        }

        return $this->normalizeParameters($pairs);
    }

    public function runReport(
        string $reportPath,
        string $format,
        ?string $parametersRaw,
        ?array $selectedEnvironments = null,
        ?array $reportPaths = null
    ): array
    {
        $format = $this->normalizeFormat($format);
        $parameterNameValues = $this->parseParameters($parametersRaw);
        $payload = [
            'attributeFormat' => $format,
        ];

        if ($parameterNameValues !== []) {
            $payload['parameterNameValues'] = $parameterNameValues;
        }

        $http = $this->config['http'] ?? [];
        $timeout = (int) ($http['timeout'] ?? 120);
        $connectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $verify = $this->normalizeVerify($http['verify'] ?? true);
        $accept = $this->formatToMime($format);

        $envs = $this->resolveEnvironments($selectedEnvironments);
        $results = [];
        $stats = [];
        $requestInfo = [];

        $responses = Http::pool(function (Pool $pool) use (
            $envs,
            $reportPath,
            $reportPaths,
            $payload,
            $accept,
            $timeout,
            $connectTimeout,
            $verify,
            &$stats,
            &$requestInfo,
        ) {
            $requests = [];

            foreach ($envs as $key => $env) {
                $error = $this->validateEnvironment($env);
                $label = $env['label'] ?? strtoupper((string) $key);

                if ($error !== null) {
                    $requestInfo[$key] = [
                        'label' => $label,
                        'error' => $error,
                    ];
                    continue;
                }

                $resolvedPath = $this->resolveReportPath($reportPath, $reportPaths, $key);
                $url = $this->buildReportUrl($env['base_url'], $resolvedPath);
                $requestInfo[$key] = [
                    'label' => $label,
                    'url' => $url,
                    'report_path' => $this->normalizeReportPath($resolvedPath),
                ];

                $requests[$key] = $pool->as($key)
                    ->accept($accept)
                    ->withBasicAuth($env['username'], $env['password'])
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->withOptions([
                        'verify' => $verify,
                        'on_stats' => function ($transferStats) use (&$stats, $key) {
                            $stats[$key] = $transferStats;
                        },
                    ])
                    ->post($url, $payload);
            }

            return $requests;
        });

        foreach ($envs as $key => $env) {
            $label = $env['label'] ?? strtoupper((string) $key);

            if (isset($requestInfo[$key]['error'])) {
                $results[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'ok' => false,
                    'status' => null,
                    'duration_ms' => null,
                    'content_type' => null,
                    'size_bytes' => null,
                    'sha256' => null,
                    'preview' => null,
                    'error' => $requestInfo[$key]['error'],
                    'url' => null,
                ];
                continue;
            }

            $response = $responses[$key] ?? null;
            $results[$key] = $this->summarizeResponse(
                $key,
                $label,
                $requestInfo[$key]['url'] ?? null,
                $requestInfo[$key]['report_path'] ?? $this->normalizeReportPath($reportPath),
                $response,
                $stats[$key] ?? null,
            );
        }

        return [
            'report_path' => $this->normalizeReportPath($reportPath),
            'report_paths' => $this->normalizeReportPaths($reportPaths),
            'format' => $format,
            'parameters' => $parameterNameValues,
            'requested_at' => now()->toIso8601String(),
            'results' => $results,
            'comparison' => $this->buildComparison($results),
        ];
    }

    private function resolveEnvironments(?array $selectedKeys): array
    {
        $envs = $this->config['environments'] ?? [];

        if ($selectedKeys === null || $selectedKeys === []) {
            return $envs;
        }

        $filtered = [];
        foreach ($selectedKeys as $key) {
            if (is_string($key) && isset($envs[$key])) {
                $filtered[$key] = $envs[$key];
            }
        }

        return $filtered;
    }

    private function resolveReportPath(string $defaultPath, ?array $reportPaths, string $envKey): string
    {
        if (! $reportPaths) {
            return $defaultPath;
        }

        $candidate = $reportPaths[$envKey] ?? null;
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }

        return $defaultPath;
    }

    private function normalizeReportPaths(?array $reportPaths): array
    {
        if (! $reportPaths) {
            return [];
        }

        $normalized = [];
        foreach ($reportPaths as $key => $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $normalized[$key] = $this->normalizeReportPath($path);
        }

        return $normalized;
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        $allowed = ['pdf', 'csv', 'xlsx', 'xml', 'html', 'text', 'rtf', 'pptx'];

        return in_array($format, $allowed, true) ? $format : 'pdf';
    }

    private function normalizeVerify(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered ?? true;
    }

    private function formatToMime(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'text' => 'text/plain',
            'rtf' => 'application/rtf',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    private function validateEnvironment(array $env): ?string
    {
        if (empty($env['base_url'])) {
            return 'Base URL is missing.';
        }

        if (empty($env['username']) || empty($env['password'])) {
            return 'Credentials are missing.';
        }

        return null;
    }

    private function buildReportUrl(string $baseUrl, string $reportPath): string
    {
        $encodedPath = $this->encodeReportPath($reportPath);

        return rtrim($baseUrl, '/').'/xmlpserver/services/rest/v1/reports/'.$encodedPath;
    }

    private function normalizeReportPath(string $reportPath): string
    {
        $reportPath = trim($reportPath);
        $reportPath = ltrim($reportPath, '/');

        return '/'.$reportPath;
    }

    private function encodeReportPath(string $reportPath): string
    {
        $reportPath = ltrim($this->normalizeReportPath($reportPath), '/');
        $segments = array_filter(explode('/', $reportPath), static fn ($segment) => $segment !== '');

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function normalizeParameters(mixed $parameters): array
    {
        if ($parameters === null || $parameters === []) {
            return [];
        }

        if (!is_array($parameters)) {
            throw new InvalidArgumentException('Parameters must be an object, array, or key=value lines.');
        }

        if (array_key_exists('parameterNameValues', $parameters) && is_array($parameters['parameterNameValues'])) {
            return $parameters['parameterNameValues'];
        }

        if (array_key_exists('listOfParamNameValues', $parameters) && is_array($parameters['listOfParamNameValues'])) {
            return $parameters;
        }

        if ($this->isParamList($parameters)) {
            return [
                'listOfParamNameValues' => $parameters,
            ];
        }

        $list = [];
        foreach ($parameters as $name => $value) {
            if ($name === '') {
                continue;
            }

            $values = is_array($value) ? array_values($value) : [$value];
            $list[] = [
                'name' => (string) $name,
                'values' => $values,
            ];
        }

        return $list === [] ? [] : ['listOfParamNameValues' => $list];
    }

    private function isParamList(array $parameters): bool
    {
        if (!array_is_list($parameters)) {
            return false;
        }

        foreach ($parameters as $item) {
            if (!is_array($item)) {
                return false;
            }

            if (!array_key_exists('name', $item) || !array_key_exists('values', $item)) {
                return false;
            }
        }

        return true;
    }

    private function summarizeResponse(
        string $key,
        string $label,
        ?string $url,
        ?string $reportPath,
        mixed $response,
        mixed $transferStats,
    ): array {
        if ($response instanceof ConnectionException || $response instanceof RequestException) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'status' => null,
                'duration_ms' => $this->transferStatsToMs($transferStats),
                'content_type' => null,
                'size_bytes' => null,
                'sha256' => null,
                'preview' => null,
                'error' => $response->getMessage(),
                'url' => $url,
                'report_path' => $reportPath,
            ];
        }

        if (!$response instanceof Response) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'status' => null,
                'duration_ms' => $this->transferStatsToMs($transferStats),
                'content_type' => null,
                'size_bytes' => null,
                'sha256' => null,
                'preview' => null,
                'error' => 'No response received.',
                'url' => $url,
                'report_path' => $reportPath,
            ];
        }

        $contentType = $response->header('Content-Type');
        $payload = $this->extractPayload($response, $contentType);
        $sizeBytes = $payload === null ? null : strlen($payload);
        $hash = $payload === null ? null : hash('sha256', $payload);

        return [
            'key' => $key,
            'label' => $label,
            'ok' => $response->successful(),
            'status' => $response->status(),
            'duration_ms' => $this->transferStatsToMs($transferStats ?? $response->handlerStats()),
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
            'sha256' => $hash,
            'preview' => $this->buildPreview($payload, $contentType),
            'error' => $response->successful() ? null : $this->extractErrorMessage($response),
            'url' => $url,
            'report_path' => $reportPath,
        ];
    }

    private function transferStatsToMs(mixed $transferStats): ?int
    {
        if (is_array($transferStats) && isset($transferStats['total_time'])) {
            return (int) round($transferStats['total_time'] * 1000);
        }

        if (is_object($transferStats) && method_exists($transferStats, 'getTransferTime')) {
            return (int) round($transferStats->getTransferTime() * 1000);
        }

        return null;
    }

    private function extractPayload(Response $response, ?string $contentType): ?string
    {
        $body = $response->body();

        if ($body === '') {
            return null;
        }

        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'application/json')) {
            $decoded = $response->json();
            if (is_array($decoded) && isset($decoded['reportBytes'])) {
                $decodedBytes = base64_decode((string) $decoded['reportBytes'], true);
                if ($decodedBytes !== false) {
                    return $decodedBytes;
                }
            }
        }

        return $body;
    }

    private function buildPreview(?string $payload, ?string $contentType): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $contentType = strtolower((string) $contentType);
        $hasTextType = str_contains($contentType, 'text/')
            || str_contains($contentType, 'json')
            || str_contains($contentType, 'xml')
            || str_contains($contentType, 'csv')
            || str_contains($contentType, 'html');

        if (!$hasTextType && !$this->looksPrintable($payload)) {
            return null;
        }

        return $this->truncate($payload, 2000);
    }

    private function extractErrorMessage(Response $response): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'application/json')) {
            $decoded = $response->json();
            if (is_array($decoded)) {
                $encoded = json_encode($decoded);
                if (is_string($encoded)) {
                    return $this->truncate($encoded, 2000);
                }
            }
        }

        return $this->truncate($response->body(), 2000);
    }

    private function looksPrintable(string $payload): bool
    {
        $sample = substr($payload, 0, 200);

        return (bool) preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $sample);
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }

    private function buildComparison(array $results): ?array
    {
        $keys = array_keys($results);
        if (count($keys) < 2) {
            return null;
        }

        $first = $results[$keys[0]] ?? null;
        $second = $results[$keys[1]] ?? null;

        if (!$first || !$second) {
            return null;
        }

        $hashMatch = $first['sha256'] !== null
            && $second['sha256'] !== null
            && $first['sha256'] === $second['sha256'];

        $sizeMatch = $first['size_bytes'] !== null
            && $second['size_bytes'] !== null
            && $first['size_bytes'] === $second['size_bytes'];

        $durationDiff = null;
        if ($first['duration_ms'] !== null && $second['duration_ms'] !== null) {
            $durationDiff = abs($first['duration_ms'] - $second['duration_ms']);
        }

        return [
            'hash_match' => $hashMatch,
            'size_match' => $sizeMatch,
            'duration_diff_ms' => $durationDiff,
        ];
    }
}
