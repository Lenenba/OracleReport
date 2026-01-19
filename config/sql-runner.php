<?php

return [
    'mode' => env('SQL_RUNNER_MODE', 'bip'),
    'environments' => [
        'dev2' => [
            'label' => env('SQL_DEV2_LABEL', 'DEV2'),
            'connection' => env('SQL_DEV2_CONNECTION', 'oraclecloudReport'),
            'report_path' => env('SQL_DEV2_REPORT_PATH'),
        ],
        'test' => [
            'label' => env('SQL_TEST_LABEL', 'TEST'),
            'connection' => env('SQL_TEST_CONNECTION', 'oraclecloudReport'),
            'report_path' => env('SQL_TEST_REPORT_PATH'),
        ],
    ],
    'bi_publisher' => [
        'report_format' => env('SQL_RUNNER_REPORT_FORMAT', 'csv'),
        'param_sql' => env('SQL_RUNNER_PARAM_SQL', 'P_SQL'),
        'param_limit' => env('SQL_RUNNER_PARAM_LIMIT', 'P_LIMIT'),
        'csv_delimiter' => env('SQL_RUNNER_CSV_DELIMITER', ','),
        'csv_enclosure' => env('SQL_RUNNER_CSV_ENCLOSURE', '"'),
        'csv_escape' => env('SQL_RUNNER_CSV_ESCAPE', '\\'),
    ],
    'limits' => [
        'default_rows' => env('SQL_RUNNER_DEFAULT_ROWS', 200),
        'max_rows' => env('SQL_RUNNER_MAX_ROWS', 1000),
        'max_query_length' => env('SQL_RUNNER_MAX_QUERY_LENGTH', 6000),
    ],
];
