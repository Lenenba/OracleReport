<?php

namespace App\Services\ConfigReport;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ConfigReportService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('config-report', []);
    }

    public function reportSources(): array
    {
        $reports = $this->config['reports'] ?? [];
        $output = [];

        foreach ($reports as $key => $report) {
            $path = $report['path'] ?? null;
            $exists = is_string($path) && $path !== '' && file_exists($path);
            $output[$key] = [
                'key' => $key,
                'label' => $report['label'] ?? strtoupper((string) $key),
                'path' => $path,
                'exists' => $exists,
                'mtime' => $exists ? date('c', (int) filemtime($path)) : null,
            ];
        }

        return $output;
    }

    public function compare(): array
    {
        $reports = $this->config['reports'] ?? [];
        $dev = $this->loadReport($reports['dev2'] ?? null);
        $test = $this->loadReport($reports['test'] ?? null);

        $devObjects = $dev['objects_by_name'];
        $testObjects = $test['objects_by_name'];
        $devNames = array_keys($devObjects);
        $testNames = array_keys($testObjects);

        $onlyInDev = array_values(array_diff($devNames, $testNames));
        $onlyInTest = array_values(array_diff($testNames, $devNames));
        sort($onlyInDev);
        sort($onlyInTest);

        $changes = [];
        $devToTestSources = [];
        $testToDevSources = [];

        $shared = array_values(array_intersect($devNames, $testNames));
        sort($shared);

        foreach ($shared as $name) {
            $devItem = $devObjects[$name];
            $testItem = $testObjects[$name];

            $devTable = $devItem['table_name'];
            $testTable = $testItem['table_name'];
            $devFields = $devItem['custom_fields'];
            $testFields = $testItem['custom_fields'];

            $tableChanged = $devTable !== null && $testTable !== null && $devTable !== $testTable;
            $fieldsDiff = null;
            if ($devFields !== null && $testFields !== null) {
                $fieldsDiff = $testFields - $devFields;
            }

            $hasTableMissing = $devTable === null || $testTable === null;
            $hasFieldsMissing = $devFields === null || $testFields === null;
            $hasFieldsChange = $fieldsDiff !== null && $fieldsDiff !== 0;

            if ($tableChanged || $hasTableMissing || $hasFieldsMissing || $hasFieldsChange) {
                $changes[] = [
                    'object' => $name,
                    'dev_table' => $devTable,
                    'test_table' => $testTable,
                    'dev_custom_fields' => $devFields,
                    'test_custom_fields' => $testFields,
                    'table_changed' => $tableChanged,
                    'custom_fields_diff' => $fieldsDiff,
                ];
            }

            if ($tableChanged) {
                $devToTestSources[$devTable][$testTable][] = $name;
                $testToDevSources[$testTable][$devTable][] = $name;
            }
        }

        $mapping = [
            'dev2_to_test' => [],
            'test_to_dev2' => [],
        ];
        $conflicts = [
            'dev2_to_test' => [],
            'test_to_dev2' => [],
        ];

        foreach ($devToTestSources as $from => $targets) {
            if (count($targets) === 1) {
                $mapping['dev2_to_test'][$from] = array_key_first($targets);
                continue;
            }

            $conflicts['dev2_to_test'][] = [
                'from' => $from,
                'targets' => array_keys($targets),
                'objects' => $this->collectObjectNames($targets),
            ];
        }

        foreach ($testToDevSources as $from => $targets) {
            if (count($targets) === 1) {
                $mapping['test_to_dev2'][$from] = array_key_first($targets);
                continue;
            }

            $conflicts['test_to_dev2'][] = [
                'from' => $from,
                'targets' => array_keys($targets),
                'objects' => $this->collectObjectNames($targets),
            ];
        }

        return [
            'objects' => [
                'dev2' => array_values($dev['objects']),
                'test' => array_values($test['objects']),
            ],
            'only_in_dev2' => $onlyInDev,
            'only_in_test' => $onlyInTest,
            'changes' => $changes,
            'mapping' => $mapping,
            'conflicts' => $conflicts,
            'errors' => [
                'dev2' => $dev['errors'],
                'test' => $test['errors'],
            ],
        ];
    }

    public function compareSources(array $leftReport, array $rightReport, array $options = []): array
    {
        $left = $this->loadReport($leftReport, $options);
        $right = $this->loadReport($rightReport, $options);

        $leftObjects = $left['objects_by_name'];
        $rightObjects = $right['objects_by_name'];
        $leftNames = array_keys($leftObjects);
        $rightNames = array_keys($rightObjects);

        $onlyInLeft = array_values(array_diff($leftNames, $rightNames));
        $onlyInRight = array_values(array_diff($rightNames, $leftNames));
        sort($onlyInLeft);
        sort($onlyInRight);

        $changes = [];
        $leftToRightSources = [];
        $rightToLeftSources = [];

        $shared = array_values(array_intersect($leftNames, $rightNames));
        sort($shared);

        foreach ($shared as $name) {
            $leftItem = $leftObjects[$name];
            $rightItem = $rightObjects[$name];

            $leftTable = $leftItem['table_name'];
            $rightTable = $rightItem['table_name'];
            $leftFields = $leftItem['custom_fields'];
            $rightFields = $rightItem['custom_fields'];

            $tableChanged = $leftTable !== null && $rightTable !== null && $leftTable !== $rightTable;
            $fieldsDiff = null;
            if ($leftFields !== null && $rightFields !== null) {
                $fieldsDiff = $rightFields - $leftFields;
            }

            $hasTableMissing = $leftTable === null || $rightTable === null;
            $hasFieldsMissing = $leftFields === null || $rightFields === null;
            $hasFieldsChange = $fieldsDiff !== null && $fieldsDiff !== 0;

            if ($tableChanged || $hasTableMissing || $hasFieldsMissing || $hasFieldsChange) {
                $changes[] = [
                    'object' => $name,
                    'left_table' => $leftTable,
                    'right_table' => $rightTable,
                    'left_custom_fields' => $leftFields,
                    'right_custom_fields' => $rightFields,
                    'table_changed' => $tableChanged,
                    'custom_fields_diff' => $fieldsDiff,
                ];
            }

            if ($tableChanged) {
                $leftToRightSources[$leftTable][$rightTable][] = $name;
                $rightToLeftSources[$rightTable][$leftTable][] = $name;
            }
        }

        $mapping = [
            'left_to_right' => [],
            'right_to_left' => [],
        ];
        $conflicts = [
            'left_to_right' => [],
            'right_to_left' => [],
        ];

        foreach ($leftToRightSources as $from => $targets) {
            if (count($targets) === 1) {
                $mapping['left_to_right'][$from] = array_key_first($targets);
                continue;
            }

            $conflicts['left_to_right'][] = [
                'from' => $from,
                'targets' => array_keys($targets),
                'objects' => $this->collectObjectNames($targets),
            ];
        }

        foreach ($rightToLeftSources as $from => $targets) {
            if (count($targets) === 1) {
                $mapping['right_to_left'][$from] = array_key_first($targets);
                continue;
            }

            $conflicts['right_to_left'][] = [
                'from' => $from,
                'targets' => array_keys($targets),
                'objects' => $this->collectObjectNames($targets),
            ];
        }

        return [
            'left' => [
                'label' => $left['label'],
                'path' => $left['path'],
                'count' => count($left['objects']),
            ],
            'right' => [
                'label' => $right['label'],
                'path' => $right['path'],
                'count' => count($right['objects']),
            ],
            'only_in_left' => $onlyInLeft,
            'only_in_right' => $onlyInRight,
            'changes' => $changes,
            'mapping' => $mapping,
            'conflicts' => $conflicts,
            'errors' => [
                'left' => $left['errors'],
                'right' => $right['errors'],
            ],
        ];
    }

    public function transformSql(string $sql, string $direction, array $mappingSet): array
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['dev2_to_test', 'test_to_dev2'], true)) {
            throw new InvalidArgumentException('Invalid transform direction.');
        }

        $mapping = $mappingSet[$direction] ?? [];
        if ($mapping === []) {
            return [
                'input' => $sql,
                'output' => $sql,
                'replacements' => [],
                'error' => 'No table mapping available for this direction.',
            ];
        }

        $ordered = $this->orderMappingByLength($mapping);
        $original = $sql;
        $replacements = [];

        foreach ($ordered as $from => $to) {
            $pattern = $this->buildTablePattern($from);
            $count = 0;
            $sql = preg_replace($pattern, $to, $sql, -1, $count);
            if ($count > 0) {
                $replacements[] = [
                    'from' => $from,
                    'to' => $to,
                    'count' => $count,
                ];
            }
        }

        return [
            'input' => $original,
            'output' => $sql,
            'replacements' => $replacements,
            'error' => null,
        ];
    }

    private function loadReport(?array $report, ?array $options = null): array
    {
        $label = $report['label'] ?? 'Unknown';
        $path = $report['path'] ?? null;
        $errors = [];

        if (! is_string($path) || $path === '') {
            $errors[] = 'Config report path is missing.';
            return [
                'label' => $label,
                'path' => $path,
                'objects' => [],
                'objects_by_name' => [],
                'errors' => $errors,
            ];
        }

        if (! file_exists($path)) {
            $errors[] = 'Config report file not found.';
            return [
                'label' => $label,
                'path' => $path,
                'objects' => [],
                'objects_by_name' => [],
                'errors' => $errors,
            ];
        }

        $rowLimit = (int) ($options['row_scan_limit'] ?? $this->config['row_scan_limit'] ?? 80);
        $suffix = (string) ($options['sheet_suffix'] ?? $this->config['sheet_suffix'] ?? '_c');
        $rowLimit = $rowLimit > 0 ? $rowLimit : 80;

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $sheetNames = $reader->listWorksheetNames($path);
        $objectSheets = array_values(array_filter(
            $sheetNames,
            static fn (string $name): bool => str_ends_with($name, $suffix)
        ));

        if ($objectSheets === []) {
            $errors[] = 'No object sheets matched the suffix filter.';
            return [
                'label' => $label,
                'path' => $path,
                'objects' => [],
                'objects_by_name' => [],
                'errors' => $errors,
            ];
        }

        $reader->setLoadSheetsOnly($objectSheets);
        $reader->setReadFilter($this->buildReadFilter($rowLimit));
        $spreadsheet = $reader->load($path);

        $objects = [];
        $objectsByName = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $objectName = $sheet->getTitle();
            $metadata = $this->extractMetadata($sheet, $rowLimit);

            $entry = [
                'object' => $objectName,
                'table_name' => $metadata['table_name'],
                'custom_fields' => $metadata['custom_fields'],
            ];

            $objects[] = $entry;
            $objectsByName[$objectName] = $entry;
        }

        $spreadsheet->disconnectWorksheets();
        usort($objects, static fn (array $left, array $right): int => strcmp($left['object'], $right['object']));

        return [
            'label' => $label,
            'path' => $path,
            'objects' => $objects,
            'objects_by_name' => $objectsByName,
            'errors' => $errors,
        ];
    }

    private function extractMetadata(Worksheet $sheet, int $rowLimit): array
    {
        $tableName = null;
        $customFields = null;
        $highestRow = min($sheet->getHighestRow(), $rowLimit);
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowValues = $this->readRowValues($sheet, $row, $highestColumnIndex);
            if ($rowValues === []) {
                continue;
            }

            foreach ($rowValues as $value) {
                $normalized = $this->normalizeLabel($value);
                if ($normalized === 'table name') {
                    $tableName = $this->extractRowValue($rowValues, $normalized);
                }
                if ($normalized === 'total number of custom fields') {
                    $raw = $this->extractRowValue($rowValues, $normalized);
                    $customFields = $this->parseInteger($raw);
                }
            }

            if ($tableName !== null && $customFields !== null) {
                break;
            }
        }

        return [
            'table_name' => $tableName,
            'custom_fields' => $customFields,
        ];
    }

    private function readRowValues(Worksheet $sheet, int $row, int $columnCount): array
    {
        $values = [];

        for ($col = 1; $col <= $columnCount; $col++) {
            $cell = Coordinate::stringFromColumnIndex($col).$row;
            $value = $sheet->getCell($cell)->getFormattedValue();
            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $values[$col] = $value;
        }

        return $values;
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim($value);
        $value = trim($value, ':');
        $value = trim($value);

        return strtolower($value);
    }

    private function extractRowValue(array $rowValues, string $normalizedLabel): ?string
    {
        if ($rowValues === []) {
            return null;
        }

        $last = end($rowValues);
        if (! is_string($last)) {
            return null;
        }

        $lastNormalized = $this->normalizeLabel($last);
        if ($lastNormalized === $normalizedLabel) {
            return null;
        }

        return $last;
    }

    private function parseInteger(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^0-9\\-]/', '', $value);
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function buildReadFilter(int $rowLimit): IReadFilter
    {
        return new class($rowLimit) implements IReadFilter {
            public function __construct(private readonly int $rowLimit)
            {
            }

            public function readCell($column, $row, $worksheetName = ''): bool
            {
                return $row <= $this->rowLimit;
            }
        };
    }

    private function collectObjectNames(array $targets): array
    {
        $names = [];
        foreach ($targets as $objects) {
            foreach ($objects as $objectName) {
                $names[$objectName] = true;
            }
        }

        return array_values(array_keys($names));
    }

    private function orderMappingByLength(array $mapping): array
    {
        uksort($mapping, static function (string $left, string $right): int {
            $diff = strlen($right) <=> strlen($left);
            if ($diff !== 0) {
                return $diff;
            }

            return strcmp($left, $right);
        });

        return $mapping;
    }

    private function buildTablePattern(string $table): string
    {
        return '/(?<![A-Z0-9_])'.preg_quote($table, '/').'(?![A-Z0-9_])/i';
    }
}
