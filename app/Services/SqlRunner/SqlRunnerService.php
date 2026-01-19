<?php

namespace App\Services\SqlRunner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class SqlRunnerService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('sql-runner', []);
    }

    public function environments(): array
    {
        $envs = $this->config['environments'] ?? [];
        $connections = config('database.connections', []);
        $bipEnvs = config('bi-publisher.environments', []);
        $mode = $this->mode();
        $output = [];

        foreach ($envs as $key => $env) {
            $connectionName = $env['connection'] ?? null;
            $reportPath = $env['report_path'] ?? null;
            $bipEnv = $bipEnvs[$key] ?? null;
            $bipConfigured = $this->isBiPublisherConfigured($bipEnv, $reportPath);
            $output[$key] = [
                'key' => $key,
                'label' => $env['label'] ?? strtoupper((string) $key),
                'connection' => $connectionName,
                'report_path' => $reportPath,
                'mode' => $mode,
                'target' => $mode === 'bip' ? $reportPath : $connectionName,
                'configured' => $mode === 'bip'
                    ? $bipConfigured
                    : $connectionName && isset($connections[$connectionName]),
            ];
        }

        return $output;
    }

    public function limits(): array
    {
        $limits = $this->config['limits'] ?? [];

        return [
            'default_rows' => (int) ($limits['default_rows'] ?? 200),
            'max_rows' => (int) ($limits['max_rows'] ?? 1000),
            'max_query_length' => (int) ($limits['max_query_length'] ?? 6000),
        ];
    }

    public function runQuery(string $sql, array $selectedEnvironments, ?int $limit = null): array
    {
        $sql = $this->sanitizeSql($sql);
        $this->assertReadOnly($sql);

        $limits = $this->limits();
        $limit = $this->normalizeLimit($limit, $limits['default_rows'], $limits['max_rows']);

        $envs = $this->resolveEnvironments($selectedEnvironments);
        $results = [];
        $mode = $this->mode();
        $bipEnvs = config('bi-publisher.environments', []);

        foreach ($envs as $key => $env) {
            $label = $env['label'] ?? strtoupper((string) $key);
            $connectionName = $env['connection'] ?? null;
            $reportPath = $env['report_path'] ?? null;
            $bipEnv = $bipEnvs[$key] ?? null;

            if ($mode === 'bip') {
                $results[$key] = $this->runViaBiPublisher($key, $label, $bipEnv, $reportPath, $sql, $limit);
                continue;
            }

            $results[$key] = $this->runViaDatabase($key, $label, $connectionName, $sql, $limit);
        }

        return [
            'query' => $sql,
            'limit' => $limit,
            'requested_at' => now()->toIso8601String(),
            'results' => $results,
        ];
    }

    private function resolveEnvironments(array $selectedKeys): array
    {
        $envs = $this->config['environments'] ?? [];
        if ($selectedKeys === []) {
            return $envs;
        }

        $filtered = [];
        foreach ($selectedKeys as $key) {
            if (isset($envs[$key])) {
                $filtered[$key] = $envs[$key];
            }
        }

        return $filtered;
    }

    private function mode(): string
    {
        $mode = strtolower((string) ($this->config['mode'] ?? 'bip'));

        return in_array($mode, ['bip', 'db'], true) ? $mode : 'bip';
    }

    private function isConnectionConfigured(string $connectionName): bool
    {
        return config("database.connections.{$connectionName}") !== null;
    }

    private function isBiPublisherConfigured(?array $bipEnv, ?string $reportPath): bool
    {
        if (! $reportPath) {
            return false;
        }

        if (! is_array($bipEnv)) {
            return false;
        }

        return ! empty($bipEnv['base_url'])
            && ! empty($bipEnv['username'])
            && ! empty($bipEnv['password']);
    }

    private function sanitizeSql(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, ';');

        $sql = trim($sql);
        $maxLength = $this->limits()['max_query_length'];
        if ($maxLength > 0 && strlen($sql) > $maxLength) {
            throw new InvalidArgumentException('SQL query exceeds the maximum length.');
        }

        return $sql;
    }

    private function assertReadOnly(string $sql): void
    {
        if ($sql === '') {
            throw new InvalidArgumentException('SQL query is required.');
        }

        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('Only a single statement is allowed.');
        }

        if (! preg_match('/^(select|with)\b/i', ltrim($sql))) {
            throw new InvalidArgumentException('Only SELECT/CTE queries are allowed.');
        }

        if (preg_match('/\b(insert|update|delete|merge|drop|alter|create|truncate|grant|revoke|commit|rollback|call|exec|execute)\b/i', $sql)) {
            throw new InvalidArgumentException('Write operations are not allowed.');
        }
    }

    private function normalizeLimit(?int $limit, int $default, int $max): int
    {
        if (! $limit) {
            return $default;
        }

        $limit = max(1, (int) $limit);

        return min($limit, $max);
    }

    private function runViaDatabase(string $key, string $label, ?string $connectionName, string $sql, int $limit): array
    {
        if (! $connectionName || ! $this->isConnectionConfigured($connectionName)) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'target' => $connectionName,
                'duration_ms' => null,
                'row_count' => 0,
                'truncated' => false,
                'columns' => [],
                'rows' => [],
                'error' => 'Database connection not configured.',
            ];
        }

        $driver = (string) (config("database.connections.{$connectionName}.driver") ?? '');
        $limitedSql = $this->applyLimit($sql, $driver, $limit + 1);

        try {
            $start = microtime(true);
            $rows = DB::connection($connectionName)->select($limitedSql);
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $normalized = array_map(static fn ($row) => (array) $row, $rows);
            $truncated = count($normalized) > $limit;
            if ($truncated) {
                $normalized = array_slice($normalized, 0, $limit);
            }

            $columns = $this->extractColumns($normalized);

            return [
                'key' => $key,
                'label' => $label,
                'ok' => true,
                'target' => $connectionName,
                'duration_ms' => $durationMs,
                'row_count' => count($normalized),
                'truncated' => $truncated,
                'columns' => $columns,
                'rows' => $normalized,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'target' => $connectionName,
                'duration_ms' => null,
                'row_count' => 0,
                'truncated' => false,
                'columns' => [],
                'rows' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function runViaBiPublisher(
        string $key,
        string $label,
        ?array $bipEnv,
        ?string $reportPath,
        string $sql,
        int $limit
    ): array {
        if (! $this->isBiPublisherConfigured($bipEnv, $reportPath)) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'target' => $reportPath,
                'duration_ms' => null,
                'row_count' => 0,
                'truncated' => false,
                'columns' => [],
                'rows' => [],
                'error' => 'BI Publisher configuration or report path missing.',
            ];
        }

        $bipConfig = $this->config['bi_publisher'] ?? [];
        $paramSql = (string) ($bipConfig['param_sql'] ?? 'P_SQL');
        $paramLimit = (string) ($bipConfig['param_limit'] ?? 'P_LIMIT');
        $format = (string) ($bipConfig['report_format'] ?? 'csv');
        $delimiter = (string) ($bipConfig['csv_delimiter'] ?? ',');
        $enclosure = (string) ($bipConfig['csv_enclosure'] ?? '"');
        $escape = (string) ($bipConfig['csv_escape'] ?? '\\');

        $payload = [
            'attributeFormat' => $format,
            'parameterNameValues' => [
                'listOfParamNameValues' => [
                    [
                        'name' => $paramSql,
                        'values' => [$sql],
                    ],
                    [
                        'name' => $paramLimit,
                        'values' => [(string) ($limit + 1)],
                    ],
                ],
            ],
        ];

        $http = config('bi-publisher.http', []);
        $timeout = (int) ($http['timeout'] ?? 120);
        $connectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $verify = filter_var($http['verify'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $start = microtime(true);
        try {
            $url = $this->buildReportUrl((string) $bipEnv['base_url'], (string) $reportPath);
            $response = Http::accept($this->formatToMime($format))
                ->withBasicAuth($bipEnv['username'], $bipEnv['password'])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withOptions(['verify' => $verify])
                ->post($url, $payload);

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'ok' => false,
                    'target' => $reportPath,
                    'duration_ms' => $durationMs,
                    'row_count' => 0,
                    'truncated' => false,
                    'columns' => [],
                    'rows' => [],
                    'error' => $response->body(),
                ];
            }

            $body = $this->extractPayload($response->body(), $response->header('Content-Type'));
            $parsed = $this->parseCsv($body, $delimiter, $enclosure, $escape, $limit);

            return [
                'key' => $key,
                'label' => $label,
                'ok' => true,
                'target' => $reportPath,
                'duration_ms' => $durationMs,
                'row_count' => count($parsed['rows']),
                'truncated' => $parsed['truncated'],
                'columns' => $parsed['columns'],
                'rows' => $parsed['rows'],
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'target' => $reportPath,
                'duration_ms' => null,
                'row_count' => 0,
                'truncated' => false,
                'columns' => [],
                'rows' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function buildReportUrl(string $baseUrl, string $reportPath): string
    {
        $encodedPath = $this->encodeReportPath($reportPath);

        return rtrim($baseUrl, '/').'/xmlpserver/services/rest/v1/reports/'.$encodedPath;
    }

    private function encodeReportPath(string $reportPath): string
    {
        $reportPath = ltrim(trim($reportPath), '/');
        $segments = array_filter(explode('/', $reportPath), static fn ($segment) => $segment !== '');

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function formatToMime(string $format): string
    {
        return match (strtolower($format)) {
            'csv' => 'text/csv',
            'xml' => 'application/xml',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }

    private function extractPayload(string $body, ?string $contentType): string
    {
        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['reportBytes'])) {
                $decodedBytes = base64_decode((string) $decoded['reportBytes'], true);
                if ($decodedBytes !== false) {
                    return $decodedBytes;
                }
            }
        }

        return $body;
    }

    private function parseCsv(
        string $payload,
        string $delimiter,
        string $enclosure,
        string $escape,
        int $limit
    ): array {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $payload);
        rewind($handle);

        $columns = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
        if ($columns === false) {
            fclose($handle);
            return [
                'columns' => [],
                'rows' => [],
                'truncated' => false,
            ];
        }

        $rows = [];
        $truncated = false;
        while (($data = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $data[$index] ?? null;
            }
            $rows[] = $row;
            if (count($rows) > $limit) {
                $rows = array_slice($rows, 0, $limit);
                $truncated = true;
                break;
            }
        }

        fclose($handle);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    private function applyLimit(string $sql, string $driver, int $limit): string
    {
        $driver = strtolower($driver);

        if ($driver === 'sqlsrv') {
            return "select top {$limit} * from ({$sql}) as _t";
        }

        if (in_array($driver, ['oci8', 'oracle'], true)) {
            return "select * from ({$sql}) where rownum <= {$limit}";
        }

        return "select * from ({$sql}) as _t limit {$limit}";
    }

    private function extractColumns(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }
}
