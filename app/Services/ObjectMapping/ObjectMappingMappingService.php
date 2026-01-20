<?php

namespace App\Services\ObjectMapping;

use App\Models\MappingEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ObjectMappingMappingService
{
    public function buildMappings(): array
    {
        $empty = [
            'tables' => ['dev2_to_test' => [], 'test_to_dev2' => []],
            'objects' => ['dev2_to_test' => [], 'test_to_dev2' => []],
            'columns' => ['dev2_to_test' => [], 'test_to_dev2' => []],
            'object_fields' => ['dev2_to_test' => [], 'test_to_dev2' => []],
        ];

        if (
            ! Schema::hasTable('mapping_environments')
            || ! Schema::hasTable('mapping_objects')
            || ! Schema::hasTable('mapping_object_envs')
            || ! Schema::hasTable('mapping_object_field_maps')
        ) {
            return $empty;
        }

        $envs = MappingEnvironment::query()
            ->whereIn('code', ['dev2', 'test'])
            ->get()
            ->keyBy('code');

        if (! $envs->has('dev2') || ! $envs->has('test')) {
            return $empty;
        }

        $dev2Id = (int) $envs['dev2']->id;
        $testId = (int) $envs['test']->id;

        $dev2Maps = $this->buildObjectMaps($dev2Id, $testId);
        $testMaps = $this->buildObjectMaps($testId, $dev2Id);

        $dev2Fields = $this->buildFieldMaps($dev2Id, $testId);
        $testFields = $this->buildFieldMaps($testId, $dev2Id);

        return [
            'tables' => [
                'dev2_to_test' => $dev2Maps['tables'],
                'test_to_dev2' => $testMaps['tables'],
            ],
            'objects' => [
                'dev2_to_test' => $dev2Maps['objects'],
                'test_to_dev2' => $testMaps['objects'],
            ],
            'columns' => [
                'dev2_to_test' => $dev2Fields['columns'],
                'test_to_dev2' => $testFields['columns'],
            ],
            'object_fields' => [
                'dev2_to_test' => $dev2Fields['object_fields'],
                'test_to_dev2' => $testFields['object_fields'],
            ],
        ];
    }

    private function buildObjectMaps(int $fromEnvId, int $toEnvId): array
    {
        $rows = DB::table('mapping_objects as o')
            ->leftJoin('mapping_object_envs as from_env', function ($join) use ($fromEnvId) {
                $join->on('from_env.object_id', '=', 'o.id')
                    ->where('from_env.environment_id', '=', $fromEnvId);
            })
            ->leftJoin('mapping_object_envs as to_env', function ($join) use ($toEnvId) {
                $join->on('to_env.object_id', '=', 'o.id')
                    ->where('to_env.environment_id', '=', $toEnvId);
            })
            ->select(
                'o.name',
                'from_env.table_name as from_table',
                'to_env.table_name as to_table',
            )
            ->orderBy('o.name')
            ->get();

        $objectMapping = [];
        $tableMapping = [];

        foreach ($rows as $row) {
            $fromTable = $row->from_table;
            $toTable = $row->to_table;
            if (! $fromTable || ! $toTable) {
                continue;
            }

            $objectMapping[$row->name] = [
                'from_table' => $fromTable,
                'to_table' => $toTable,
            ];

            if (strcasecmp($fromTable, $toTable) !== 0) {
                $tableMapping[$fromTable] = $toTable;
            }
        }

        return [
            'objects' => $objectMapping,
            'tables' => $tableMapping,
        ];
    }

    private function buildFieldMaps(int $fromEnvId, int $toEnvId): array
    {
        $rows = DB::table('mapping_object_field_maps as m')
            ->join('mapping_objects as o', 'm.object_id', '=', 'o.id')
            ->where('m.from_environment_id', $fromEnvId)
            ->where('m.to_environment_id', $toEnvId)
            ->select(
                'o.name',
                'm.from_table',
                'm.to_table',
                'm.from_column',
                'm.to_column',
            )
            ->orderBy('o.name')
            ->get();

        $objectFieldMapping = [];
        $columnMapping = [];

        foreach ($rows as $row) {
            $fromColumn = $row->from_column;
            $toColumn = $row->to_column;
            if (! $fromColumn || ! $toColumn) {
                continue;
            }

            if (! isset($objectFieldMapping[$row->name][$fromColumn])) {
                $objectFieldMapping[$row->name][$fromColumn] = $toColumn;
            }

            if (
                $row->from_table
                && $row->to_table
                && strcasecmp($row->from_table, $row->to_table) !== 0
                && strcasecmp($fromColumn, $toColumn) !== 0
            ) {
                if (! isset($columnMapping[$row->from_table][$fromColumn])) {
                    $columnMapping[$row->from_table][$fromColumn] = $toColumn;
                }
            }
        }

        return [
            'object_fields' => $objectFieldMapping,
            'columns' => $columnMapping,
        ];
    }
}
