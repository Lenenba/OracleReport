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

        $strictMatch = $onlyInLeft === [] && $onlyInRight === [] && $changes === [];
        $strictOk = $strictMatch && $left['errors'] === [] && $right['errors'] === [];

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
            'strict_ok' => $strictOk,
            'mapping' => $mapping,
            'conflicts' => $conflicts,
            'errors' => [
                'left' => $left['errors'],
                'right' => $right['errors'],
            ],
        ];
    }

    public function loadReportSummary(?array $report, ?array $options = null): array
    {
        return $this->loadReport($report, $options);
    }

    public function loadReportWithFields(?array $report, ?array $options = null): array
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

        $metadataRowLimit = (int) ($options['row_scan_limit'] ?? $this->config['row_scan_limit'] ?? 80);
        $metadataRowLimit = $metadataRowLimit > 0 ? $metadataRowLimit : 80;
        $fieldScanLimit = (int) ($options['field_scan_limit'] ?? $this->config['field_scan_limit'] ?? 0);
        $fieldScanLimit = $fieldScanLimit > 0 ? $fieldScanLimit : 0;
        $chunkSize = (int) ($options['field_chunk_size'] ?? $this->config['field_chunk_size'] ?? 500);
        $chunkSize = $chunkSize > 0 ? $chunkSize : 500;
        $suffix = (string) ($options['sheet_suffix'] ?? $this->config['sheet_suffix'] ?? '_c');

        $reader = IOFactory::createReaderForFile($path);
        $sheetInfos = $reader->listWorksheetInfo($path);
        $objectInfos = array_values(array_filter(
            $sheetInfos,
            static fn (array $info): bool => str_ends_with((string) ($info['worksheetName'] ?? ''), $suffix)
        ));

        if ($objectInfos === []) {
            $errors[] = 'No object sheets matched the suffix filter.';
            return [
                'label' => $label,
                'path' => $path,
                'objects' => [],
                'objects_by_name' => [],
                'errors' => $errors,
            ];
        }

        $objects = [];
        $objectsByName = [];

        foreach ($objectInfos as $info) {
            $objectName = (string) ($info['worksheetName'] ?? '');
            if ($objectName === '') {
                continue;
            }

            $totalRows = (int) ($info['totalRows'] ?? 0);
            $columnCount = $this->resolveColumnCount($info);

            $metadata = $this->loadSheetMetadata($path, $objectName, $metadataRowLimit);
            $fields = $this->extractFieldsChunked(
                $path,
                $objectName,
                $fieldScanLimit,
                $totalRows,
                $columnCount,
                $chunkSize,
            );

            $entry = [
                'object' => $objectName,
                'table_name' => $metadata['table_name'],
                'custom_fields' => $metadata['custom_fields'],
                'fields' => $fields,
            ];

            $objects[] = $entry;
            $objectsByName[$objectName] = $entry;
        }
        usort($objects, static fn (array $left, array $right): int => strcmp($left['object'], $right['object']));

        return [
            'label' => $label,
            'path' => $path,
            'objects' => $objects,
            'objects_by_name' => $objectsByName,
            'errors' => $errors,
        ];
    }

    public function loadObjectFields(?array $report, string $objectName, ?array $options = null): array
    {
        $label = $report['label'] ?? 'Unknown';
        $path = $report['path'] ?? null;
        $errors = [];

        if (! is_string($path) || $path === '') {
            $errors[] = 'Config report path is missing.';
            return [
                'label' => $label,
                'path' => $path,
                'table_name' => null,
                'fields' => [],
                'errors' => $errors,
            ];
        }

        if (! file_exists($path)) {
            $errors[] = 'Config report file not found.';
            return [
                'label' => $label,
                'path' => $path,
                'table_name' => null,
                'fields' => [],
                'errors' => $errors,
            ];
        }

        $fieldScanLimit = (int) ($options['field_scan_limit'] ?? $this->config['field_scan_limit'] ?? 0);
        $fieldScanLimit = $fieldScanLimit > 0 ? $fieldScanLimit : 0;
        $chunkSize = (int) ($options['field_chunk_size'] ?? $this->config['field_chunk_size'] ?? 500);
        $chunkSize = $chunkSize > 0 ? $chunkSize : 500;
        $metadataRowLimit = (int) ($options['row_scan_limit'] ?? $this->config['row_scan_limit'] ?? 80);
        $metadataRowLimit = $metadataRowLimit > 0 ? $metadataRowLimit : 80;

        $reader = IOFactory::createReaderForFile($path);
        $sheetInfos = $reader->listWorksheetInfo($path);
        $sheetInfo = null;
        foreach ($sheetInfos as $info) {
            if (($info['worksheetName'] ?? null) === $objectName) {
                $sheetInfo = $info;
                break;
            }
        }

        if ($sheetInfo === null) {
            $errors[] = 'Object sheet not found.';
            return [
                'label' => $label,
                'path' => $path,
                'table_name' => null,
                'fields' => [],
                'errors' => $errors,
            ];
        }

        $totalRows = (int) ($sheetInfo['totalRows'] ?? 0);
        $columnCount = $this->resolveColumnCount($sheetInfo);

        $metadata = $this->loadSheetMetadata($path, $objectName, $metadataRowLimit);
        $fields = $this->extractFieldsChunked(
            $path,
            $objectName,
            $fieldScanLimit,
            $totalRows,
            $columnCount,
            $chunkSize,
        );

        return [
            'label' => $label,
            'path' => $path,
            'table_name' => $metadata['table_name'],
            'fields' => $fields,
            'errors' => $errors,
        ];
    }

    public function transformSql(string $sql, string $direction, array $mappingSet): array
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['dev2_to_test', 'test_to_dev2'], true)) {
            throw new InvalidArgumentException('Invalid transform direction.');
        }

        $tableMappingSet = $mappingSet['tables'] ?? $mappingSet;
        $columnMappingSet = $mappingSet['columns'] ?? [];
        $objectMappingSet = $mappingSet['objects'] ?? [];
        $objectFieldMappingSet = $mappingSet['object_fields'] ?? [];

        $tableMapping = is_array($tableMappingSet) ? ($tableMappingSet[$direction] ?? []) : [];
        $columnMapping = $columnMappingSet[$direction] ?? [];
        $objectMapping = $objectMappingSet[$direction] ?? [];
        $objectFieldMapping = $objectFieldMappingSet[$direction] ?? [];

        if ($tableMapping === [] && $columnMapping === [] && $objectMapping === [] && $objectFieldMapping === []) {
            return [
                'input' => $sql,
                'output' => $sql,
                'replacements' => [],
                'error' => 'No mapping available for this direction.',
            ];
        }

        $original = $sql;
        $replacements = [];
        $issues = [];
        $aliasMap = $this->extractTableAliases($sql);
        $objectUsage = $this->extractObjectUsage($sql);
        if (isset($objectUsage['*'])) {
            if (count($aliasMap) === 1) {
                $alias = array_key_first($aliasMap);
                $objectUsage[$alias] = array_values(array_unique(array_merge(
                    $objectUsage[$alias] ?? [],
                    $objectUsage['*'],
                )));
            } else {
                $issues[] = [
                    'type' => 'object_alias_missing',
                    'objects' => $objectUsage['*'],
                ];
            }
            unset($objectUsage['*']);
        }

        foreach ($objectUsage as $alias => $objectNames) {
            [$mapping, $conflict] = $this->resolveObjectTableMapping($objectMapping, $objectNames);
            if ($conflict) {
                $issues[] = [
                    'type' => 'object_table_conflict',
                    'alias' => $alias,
                    'objects' => $objectNames,
                ];
                continue;
            }
            if (! $mapping) {
                $issues[] = [
                    'type' => 'object_table_missing',
                    'alias' => $alias,
                    'objects' => $objectNames,
                ];
            }
        }

        $columnUsage = $this->extractAliasColumnUsage($sql, array_keys($aliasMap));
        foreach ($columnUsage as $alias => $columns) {
            if (! isset($objectUsage[$alias])) {
                foreach ($columns as $column) {
                    if ($this->isAttributeColumn($column)) {
                        $issues[] = [
                            'type' => 'object_context_missing',
                            'alias' => $alias,
                            'column' => $column,
                        ];
                    }
                }
                continue;
            }

            $objectNames = $objectUsage[$alias];
            [$mapping, $conflicts] = $this->resolveObjectColumnMapping($objectFieldMapping, $objectNames);
            $mappingIndex = [];
            foreach ($mapping as $from => $to) {
                $mappingIndex[strtolower($from)] = true;
            }
            foreach ($columns as $column) {
                if (! $this->isAttributeColumn($column)) {
                    continue;
                }
                if (! isset($mappingIndex[strtolower($column)])) {
                    $issues[] = [
                        'type' => 'object_column_missing',
                        'alias' => $alias,
                        'column' => $column,
                        'objects' => $objectNames,
                    ];
                }
            }
            foreach ($conflicts as $conflict) {
                $issues[] = [
                    'type' => 'object_column_conflict',
                    'alias' => $alias,
                    'column' => $conflict['column'],
                    'targets' => $conflict['targets'],
                    'objects' => $objectNames,
                ];
            }
        }

        if ($objectMapping !== [] && $objectUsage !== []) {
            $objectFromTables = [];
            foreach ($objectUsage as $objectNames) {
                foreach ($objectNames as $objectName) {
                    $mapping = $this->findObjectMapping($objectMapping, $objectName);
                    if (is_array($mapping) && isset($mapping['from_table'])) {
                        $objectFromTables[] = $mapping['from_table'];
                    }
                }
            }
            $objectFromTables = array_values(array_unique(array_filter($objectFromTables)));
            foreach ($objectFromTables as $fromTable) {
                unset($tableMapping[$fromTable]);
            }
        }

        if ($objectUsage !== [] && $objectFieldMapping !== []) {
            foreach ($objectUsage as $alias => $objectNames) {
                [$mapping, $conflicts] = $this->resolveObjectColumnMapping($objectFieldMapping, $objectNames);
                if ($mapping === []) {
                    continue;
                }
                [$sql, $objectColumnReplacements] = $this->replaceAliasQualifiedColumns($sql, $alias, $mapping);
                $replacements = array_merge($replacements, $objectColumnReplacements);
            }
        }

        if ($objectMapping !== [] && $objectUsage !== []) {
            [$sql, $objectReplacements] = $this->replaceObjectTableMappings($sql, $objectMapping, $aliasMap, $objectUsage);
            $replacements = array_merge($replacements, $objectReplacements);
        }

        if ($columnMapping !== []) {
            $aliasMapForTable = $this->filterAliasMap($aliasMap, array_keys($objectUsage));
            [$sql, $columnReplacements] = $this->replaceQualifiedColumns($sql, $columnMapping, $aliasMapForTable);
            $replacements = array_merge($replacements, $columnReplacements);
        }

        if ($tableMapping !== []) {
            $ordered = $this->orderMappingByLength($tableMapping);
            foreach ($ordered as $from => $to) {
                $pattern = $this->buildTablePattern($from);
                $count = 0;
                $sql = preg_replace($pattern, $to, $sql, -1, $count);
                if ($count > 0) {
                    $replacements[] = [
                        'type' => 'table',
                        'from' => $from,
                        'to' => $to,
                        'count' => $count,
                    ];
                }
            }
        }

        return [
            'input' => $original,
            'output' => $sql,
            'replacements' => $replacements,
            'issues' => $issues,
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

    private function extractFields(Worksheet $sheet, int $rowLimit): array
    {
        $fields = [];
        $fieldNameColumnIndex = null;
        $machineNameColumnIndex = null;
        $highestRow = min($sheet->getHighestRow(), $rowLimit);
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $sectionLabels = [
            'custom fields',
            'standard fields',
            'validations',
            'triggers',
            'object triggers',
            'object functions',
            'object workflows',
            'dynamic layouts',
            'event triggers',
            'list of values',
            'data sets',
            'parameters',
            'bursting',
        ];
        $headerHints = [
            'display name',
            'column name',
            'type',
            'data type',
            'field type',
        ];
        $machineNameLabels = [
            'column name',
            'db column',
            'database column',
            'physical column',
            'physical column name',
        ];

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowValues = $this->readRowValues($sheet, $row, $highestColumnIndex);
            if ($rowValues === []) {
                continue;
            }

            if ($fieldNameColumnIndex === null) {
                $nameColumn = null;
                $machineColumn = null;
                $hasHint = false;

                foreach ($rowValues as $column => $value) {
                    $normalized = $this->normalizeLabel($value);
                    if ($normalized === 'name' || $normalized === 'field name') {
                        $nameColumn = $column;
                    }
                    if (in_array($normalized, $machineNameLabels, true)) {
                        $machineColumn = $column;
                    }
                    if (in_array($normalized, $headerHints, true)) {
                        $hasHint = true;
                    }
                }

                if ($nameColumn !== null && $hasHint) {
                    $fieldNameColumnIndex = $nameColumn;
                    $machineNameColumnIndex = $machineColumn;
                }
                continue;
            }

            if (count($rowValues) === 1) {
                    $single = $this->normalizeLabel((string) reset($rowValues));
                    if (in_array($single, $sectionLabels, true)) {
                        $fieldNameColumnIndex = null;
                        $machineNameColumnIndex = null;
                        continue;
                    }
                }

            $fieldValue = $rowValues[$fieldNameColumnIndex] ?? null;
            $fieldValue = is_string($fieldValue) ? trim($fieldValue) : null;

            if ($fieldValue === null || $fieldValue === '') {
                $fieldNameColumnIndex = null;
                $machineNameColumnIndex = null;
                continue;
            }

            $normalizedField = $this->normalizeLabel($fieldValue);
            if ($normalizedField !== 'name' && $normalizedField !== 'field name') {
                $machineValue = null;
                if ($machineNameColumnIndex !== null) {
                    $machineValue = $rowValues[$machineNameColumnIndex] ?? null;
                    $machineValue = is_string($machineValue) ? trim($machineValue) : null;
                }
                $key = strtolower($fieldValue);
                if (! isset($fields[$key])) {
                    $fields[$key] = [
                        'name' => $fieldValue,
                        'machine_name' => $machineValue !== '' ? $machineValue : null,
                    ];
                } elseif ($fields[$key]['machine_name'] === null && $machineValue) {
                    $fields[$key]['machine_name'] = $machineValue;
                }
            }
        }

        return array_values($fields);
    }

    private function extractFieldsChunked(
        string $path,
        string $sheetName,
        int $rowLimit,
        int $totalRows,
        int $columnCount,
        int $chunkSize,
    ): array {
        $fields = [];
        $fieldNameColumnIndex = null;
        $machineNameColumnIndex = null;
        $sectionLabels = [
            'custom fields',
            'standard fields',
            'validations',
            'triggers',
            'object triggers',
            'object functions',
            'object workflows',
            'dynamic layouts',
            'event triggers',
            'list of values',
            'data sets',
            'parameters',
            'bursting',
        ];
        $headerHints = [
            'display name',
            'column name',
            'type',
            'data type',
            'field type',
        ];
        $machineNameLabels = [
            'column name',
            'db column',
            'database column',
            'physical column',
            'physical column name',
        ];

        $maxRow = $rowLimit > 0 ? $rowLimit : $totalRows;
        if ($maxRow <= 0) {
            return [];
        }
        if ($totalRows > 0) {
            $maxRow = min($maxRow, $totalRows);
        }
        $chunkSize = $chunkSize > 0 ? $chunkSize : 500;

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);

        for ($startRow = 1; $startRow <= $maxRow; $startRow += $chunkSize) {
            $endRow = min($startRow + $chunkSize - 1, $maxRow);
            $reader->setReadFilter($this->buildChunkReadFilter($startRow, $endRow));
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                $spreadsheet->disconnectWorksheets();
                break;
            }

            for ($row = $startRow; $row <= $endRow; $row++) {
                $rowValues = $this->readRowValues($sheet, $row, $columnCount);
                if ($rowValues === []) {
                    continue;
                }

                if ($fieldNameColumnIndex === null) {
                    $nameColumn = null;
                    $machineColumn = null;
                    $hasHint = false;

                    foreach ($rowValues as $column => $value) {
                        $normalized = $this->normalizeLabel($value);
                        if ($normalized === 'name' || $normalized === 'field name') {
                            $nameColumn = $column;
                        }
                        if (in_array($normalized, $machineNameLabels, true)) {
                            $machineColumn = $column;
                        }
                        if (in_array($normalized, $headerHints, true)) {
                            $hasHint = true;
                        }
                    }

                    if ($nameColumn !== null && $hasHint) {
                        $fieldNameColumnIndex = $nameColumn;
                        $machineNameColumnIndex = $machineColumn;
                    }
                    continue;
                }

                if (count($rowValues) === 1) {
                    $single = $this->normalizeLabel((string) reset($rowValues));
                    if (in_array($single, $sectionLabels, true)) {
                        $fieldNameColumnIndex = null;
                        $machineNameColumnIndex = null;
                        continue;
                    }
                }

                $fieldValue = $rowValues[$fieldNameColumnIndex] ?? null;
                $fieldValue = is_string($fieldValue) ? trim($fieldValue) : null;

                if ($fieldValue === null || $fieldValue === '') {
                    $fieldNameColumnIndex = null;
                    $machineNameColumnIndex = null;
                    continue;
                }

                $normalizedField = $this->normalizeLabel($fieldValue);
                if ($normalizedField !== 'name' && $normalizedField !== 'field name') {
                    $machineValue = null;
                    if ($machineNameColumnIndex !== null) {
                        $machineValue = $rowValues[$machineNameColumnIndex] ?? null;
                        $machineValue = is_string($machineValue) ? trim($machineValue) : null;
                    }
                    $key = strtolower($fieldValue);
                    if (! isset($fields[$key])) {
                        $fields[$key] = [
                            'name' => $fieldValue,
                            'machine_name' => $machineValue !== '' ? $machineValue : null,
                        ];
                    } elseif ($fields[$key]['machine_name'] === null && $machineValue) {
                        $fields[$key]['machine_name'] = $machineValue;
                    }
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return array_values($fields);
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

    private function loadSheetMetadata(string $path, string $sheetName, int $rowLimit): array
    {
        $rowLimit = $rowLimit > 0 ? $rowLimit : 80;
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter($this->buildReadFilter($rowLimit));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            $spreadsheet->disconnectWorksheets();
            return [
                'table_name' => null,
                'custom_fields' => null,
            ];
        }

        $metadata = $this->extractMetadata($sheet, $rowLimit);
        $spreadsheet->disconnectWorksheets();

        return $metadata;
    }

    private function resolveColumnCount(array $info): int
    {
        $columnCount = (int) ($info['lastColumnIndex'] ?? $info['totalColumns'] ?? 0);
        if ($columnCount > 0) {
            return $columnCount;
        }

        $letter = $info['lastColumnLetter'] ?? null;
        if (is_string($letter) && $letter !== '') {
            return Coordinate::columnIndexFromString($letter);
        }

        return 1;
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

    private function buildChunkReadFilter(int $startRow, int $endRow): IReadFilter
    {
        return new class($startRow, $endRow) implements IReadFilter {
            public function __construct(
                private readonly int $startRow,
                private readonly int $endRow,
            ) {
            }

            public function readCell($column, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
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

    private function extractTableAliases(string $sql): array
    {
        $aliases = [];
        $pattern = '/\b(?:from|join)\s+([A-Z0-9_.$"\\[\\]]+|\([^\\)]*\\))\s*(?:as\s+)?([A-Z0-9_]+)?/i';

        if (! preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            return $aliases;
        }

        foreach ($matches as $match) {
            $tableRef = trim((string) ($match[1] ?? ''));
            if ($tableRef === '' || str_starts_with($tableRef, '(')) {
                continue;
            }

            $alias = trim((string) ($match[2] ?? ''));
            $normalizedTable = $this->normalizeIdentifier($tableRef);
            if ($alias === '') {
                $alias = $normalizedTable;
            }
            if ($alias === '') {
                continue;
            }

            $aliases[$alias] = $normalizedTable;
        }

        return $aliases;
    }

    private function replaceObjectTableMappings(
        string $sql,
        array $objectMapping,
        array $aliasMap,
        array $objectUsage,
    ): array {
        $replacements = [];
        if ($objectUsage === []) {
            return [$sql, $replacements];
        }

        foreach ($objectUsage as $alias => $objectNames) {
            [$mapping, $conflict] = $this->resolveObjectTableMapping($objectMapping, $objectNames);
            if (! $mapping) {
                if ($conflict) {
                    $replacements[] = [
                        'type' => 'object_table_conflict',
                        'alias' => $alias,
                        'objects' => $objectNames,
                    ];
                }
                continue;
            }

            $fromTable = $mapping['from_table'];
            $toTable = $mapping['to_table'];
            $aliasTable = $this->findAliasTable($aliasMap, $alias);
            if ($aliasTable !== null && strcasecmp($aliasTable, $fromTable) !== 0) {
                continue;
            }

            [$sql, $count] = $this->replaceTableForAlias($sql, $alias, $fromTable, $toTable);
            if ($count > 0) {
                $replacements[] = [
                    'type' => 'object_table',
                    'object' => implode(', ', $objectNames),
                    'alias' => $alias,
                    'from' => $fromTable,
                    'to' => $toTable,
                    'count' => $count,
                ];
            }
        }

        return [$sql, $replacements];
    }

    private function findAliasTable(array $aliasMap, string $alias): ?string
    {
        foreach ($aliasMap as $key => $table) {
            if (strcasecmp($key, $alias) === 0) {
                return $table;
            }
        }

        return null;
    }

    private function extractObjectUsage(string $sql): array
    {
        $usage = [];
        $pattern = '/(?:\\b([A-Z0-9_]+)\\s*\\.\\s*)?ATTRIBUTE_CATEGORY\\s*(=|IN)\\s*(\\([^\\)]*\\)|N?\'[^\']+\')/i';

        if (! preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $usage;
        }

        $tableMatches = $this->collectTableMatches($sql);

        foreach ($matches as $match) {
            $alias = trim((string) ($match[1][0] ?? ''));
            $operator = strtoupper((string) ($match[2][0] ?? '='));
            $value = (string) ($match[3][0] ?? '');
            $offset = (int) ($match[0][1] ?? 0);

            if ($alias === '') {
                $alias = $this->inferAliasForObject($tableMatches, $offset);
            }
            if ($alias === '') {
                $alias = '*';
            }

            $objects = $this->parseObjectList($operator, $value);
            if ($objects === []) {
                continue;
            }

            $usage[$alias] = array_values(array_unique(array_merge($usage[$alias] ?? [], $objects)));
        }

        return $usage;
    }

    private function parseObjectList(string $operator, string $value): array
    {
        if ($operator === '=') {
            if (preg_match('/N?\'([^\']+)\'/i', $value, $single)) {
                return [$single[1]];
            }
            return [];
        }

        $objects = [];
        if (preg_match_all('/N?\'([^\']+)\'/i', $value, $matches)) {
            foreach ($matches[1] as $objectName) {
                $objectName = trim($objectName);
                if ($objectName !== '') {
                    $objects[] = $objectName;
                }
            }
        }

        return $objects;
    }

    private function collectTableMatches(string $sql): array
    {
        $pattern = '/\\b(from|join)\\s+([A-Z0-9_.$"\\[\\]]+)(?:\\s+(?:as\\s+)?([A-Z0-9_]+))?/i';
        if (! preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $tableMatches = [];
        foreach ($matches as $match) {
            $tableMatches[] = [
                'offset' => (int) ($match[0][1] ?? 0),
                'table' => (string) ($match[2][0] ?? ''),
                'alias' => (string) ($match[3][0] ?? ''),
            ];
        }

        return $tableMatches;
    }

    private function inferAliasForObject(array $tableMatches, int $offset): string
    {
        $best = '';
        $bestOffset = -1;

        foreach ($tableMatches as $match) {
            $matchOffset = $match['offset'] ?? -1;
            if ($matchOffset >= 0 && $matchOffset < $offset && $matchOffset >= $bestOffset) {
                $bestOffset = $matchOffset;
                $alias = trim((string) ($match['alias'] ?? ''));
                $table = trim((string) ($match['table'] ?? ''));
                $best = $alias !== '' ? $alias : $this->normalizeIdentifier($table);
            }
        }

        return $best;
    }

    private function resolveObjectTableMapping(array $objectMapping, array $objectNames): array
    {
        $from = null;
        $to = null;

        foreach ($objectNames as $objectName) {
            $mapping = $this->findObjectMapping($objectMapping, $objectName);
            if (! is_array($mapping)) {
                continue;
            }

            $fromTable = $mapping['from_table'] ?? null;
            $toTable = $mapping['to_table'] ?? null;
            if (! is_string($fromTable) || ! is_string($toTable)) {
                continue;
            }

            if ($from === null && $to === null) {
                $from = $fromTable;
                $to = $toTable;
                continue;
            }

            if (strcasecmp($from, $fromTable) !== 0 || strcasecmp($to, $toTable) !== 0) {
                return [null, true];
            }
        }

        if ($from === null || $to === null) {
            return [null, false];
        }

        return [[
            'from_table' => $from,
            'to_table' => $to,
        ], false];
    }

    private function resolveObjectColumnMapping(array $objectFieldMapping, array $objectNames): array
    {
        $mapping = [];
        $conflicts = [];

        foreach ($objectNames as $objectName) {
            $objectMap = $this->findObjectMapping($objectFieldMapping, $objectName);
            if (! is_array($objectMap)) {
                continue;
            }

            foreach ($objectMap as $from => $to) {
                if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                    continue;
                }

                if (isset($mapping[$from]) && strcasecmp($mapping[$from], $to) !== 0) {
                    $conflicts[] = [
                        'column' => $from,
                        'targets' => [$mapping[$from], $to],
                    ];
                    continue;
                }

                $mapping[$from] = $to;
            }
        }

        return [$mapping, $conflicts];
    }

    private function extractAliasColumnUsage(string $sql, array $allowedAliases): array
    {
        $usage = [];
        if ($allowedAliases === []) {
            return $usage;
        }

        $allowed = [];
        foreach ($allowedAliases as $alias) {
            $allowed[strtolower($alias)] = $alias;
        }

        $pattern = '/\b([A-Z0-9_]+)\s*\.\s*([A-Z0-9_]+)\b/i';
        if (! preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            return $usage;
        }

        foreach ($matches as $match) {
            $alias = (string) ($match[1] ?? '');
            $column = (string) ($match[2] ?? '');
            if ($alias === '' || $column === '') {
                continue;
            }
            $aliasKey = strtolower($alias);
            if (! isset($allowed[$aliasKey])) {
                continue;
            }

            $canonicalAlias = $allowed[$aliasKey];
            $usage[$canonicalAlias][$column] = true;
        }

        $normalized = [];
        foreach ($usage as $alias => $columns) {
            $normalized[$alias] = array_values(array_keys($columns));
        }

        return $normalized;
    }

    private function isAttributeColumn(string $column): bool
    {
        $normalized = strtoupper($column);
        if ($normalized === 'ATTRIBUTE_CATEGORY') {
            return false;
        }

        return str_starts_with($normalized, 'ATTRIBUTE_') || str_starts_with($normalized, 'EXTN_ATTRIBUTE_');
    }

    private function replaceAliasQualifiedColumns(string $sql, string $alias, array $columnMapping): array
    {
        $replacements = [];
        $orderedColumns = $this->orderMappingByLength($columnMapping);

        foreach ($orderedColumns as $from => $to) {
            if (strcasecmp((string) $from, (string) $to) === 0) {
                continue;
            }
            $pattern = '/(?<![A-Z0-9_])'
                .preg_quote($alias, '/')
                .'\\s*\\.\\s*'
                .preg_quote($from, '/')
                .'(?![A-Z0-9_])/i';
            $count = 0;
            $sql = preg_replace($pattern, $alias.'.'.$to, $sql, -1, $count);
            if ($count > 0) {
                $replacements[] = [
                    'type' => 'object_column',
                    'alias' => $alias,
                    'from' => $from,
                    'to' => $to,
                    'count' => $count,
                ];
            }
        }

        return [$sql, $replacements];
    }

    private function findObjectMapping(array $objectMapping, string $objectName): ?array
    {
        if (isset($objectMapping[$objectName])) {
            return $objectMapping[$objectName];
        }

        foreach ($objectMapping as $key => $mapping) {
            if (strcasecmp($key, $objectName) === 0) {
                return is_array($mapping) ? $mapping : null;
            }
        }

        return null;
    }

    private function filterAliasMap(array $aliasMap, array $aliases): array
    {
        if ($aliasMap === [] || $aliases === []) {
            return $aliasMap;
        }

        $filtered = [];
        foreach ($aliasMap as $alias => $table) {
            $skip = false;
            foreach ($aliases as $blocked) {
                if (strcasecmp($alias, $blocked) === 0) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip) {
                $filtered[$alias] = $table;
            }
        }

        return $filtered;
    }

    private function replaceTableForAlias(string $sql, string $alias, string $from, string $to): array
    {
        $pattern = '/(?<![A-Z0-9_])(?:(?P<schema>[A-Z0-9_]+)\.)?'
            .preg_quote($from, '/')
            .'(?![A-Z0-9_])(?=\\s+(?:as\\s+)?'
            .preg_quote($alias, '/')
            .'(?![A-Z0-9_]))/i';

        $count = 0;
        $sql = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($to): string {
                $schema = $matches['schema'] ?? '';
                if ($schema !== '') {
                    return $schema.'.'.$to;
                }
                return $to;
            },
            $sql,
            -1,
            $count,
        );

        return [$sql, $count];
    }

    private function replaceQualifiedColumns(string $sql, array $columnMapping, array $aliasMap): array
    {
        $replacements = [];

        foreach ($columnMapping as $table => $columns) {
            if (! is_array($columns) || $columns === []) {
                continue;
            }

            $qualifiers = [$table];
            foreach ($aliasMap as $alias => $aliasTable) {
                if (strcasecmp($aliasTable, $table) === 0) {
                    $qualifiers[] = $alias;
                }
            }

            $orderedColumns = $this->orderMappingByLength($columns);
            foreach ($orderedColumns as $from => $to) {
                foreach ($qualifiers as $qualifier) {
                    $pattern = '/(?<![A-Z0-9_])'
                        .preg_quote($qualifier, '/')
                        .'\\s*\\.\\s*'
                        .preg_quote($from, '/')
                        .'(?![A-Z0-9_])/i';
                    $count = 0;
                    $sql = preg_replace($pattern, $qualifier.'.'.$to, $sql, -1, $count);
                    if ($count > 0) {
                        $replacements[] = [
                            'type' => 'column',
                            'table' => $table,
                            'qualifier' => $qualifier,
                            'from' => $from,
                            'to' => $to,
                            'count' => $count,
                        ];
                    }
                }
            }
        }

        return [$sql, $replacements];
    }

    private function normalizeIdentifier(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "\"`[]");
        $parts = explode('.', $value);
        $last = trim((string) end($parts));
        return trim($last, "\"`[]");
    }
}
