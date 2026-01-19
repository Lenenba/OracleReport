<?php

return [
    'reports' => [
        'dev2' => [
            'label' => env('CONFIG_REPORT_DEV2_LABEL', 'DEV2'),
            'path' => env(
                'CONFIG_REPORT_DEV2_PATH',
                public_path('Config/ConfigurationReport DEV2.xlsx')
            ),
        ],
        'test' => [
            'label' => env('CONFIG_REPORT_TEST_LABEL', 'TEST'),
            'path' => env(
                'CONFIG_REPORT_TEST_PATH',
                public_path('Config/ConfigurationReport TEST.xlsx')
            ),
        ],
    ],
    'sheet_suffix' => env('CONFIG_REPORT_SHEET_SUFFIX', '_c'),
    'row_scan_limit' => env('CONFIG_REPORT_ROW_SCAN_LIMIT', 80),
    'sql_transform' => [
        'max_length' => env('CONFIG_REPORT_SQL_MAX_LENGTH', 12000),
    ],
];
