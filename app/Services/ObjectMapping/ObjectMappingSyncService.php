<?php

namespace App\Services\ObjectMapping;

use App\Models\MappingEnvironment;
use App\Models\MappingFieldEnv;
use App\Models\MappingObject;
use App\Models\MappingObjectEnv;
use App\Models\MappingObjectField;
use App\Models\MappingObjectFieldMap;
use App\Services\ConfigReport\ConfigReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ObjectMappingSyncService
{
    public function __construct(private readonly ConfigReportService $configService)
    {
    }

    public function sync(bool $force = false): array
    {
        if (
            ! Schema::hasTable('mapping_environments')
            || ! Schema::hasTable('mapping_objects')
            || ! Schema::hasTable('mapping_object_envs')
            || ! Schema::hasTable('mapping_object_fields')
            || ! Schema::hasTable('mapping_field_envs')
            || ! Schema::hasTable('mapping_object_field_maps')
        ) {
            return [
                'status' => 'missing_tables',
            ];
        }

        $reports = config('config-report.reports', []);
        $results = [];
        $synced = [];

        foreach ($reports as $environment => $report) {
            if (! is_array($report)) {
                $results[$environment] = ['status' => 'missing'];
                continue;
            }

            $path = $report['path'] ?? null;
            if (! is_string($path) || $path === '' || ! file_exists($path)) {
                $results[$environment] = ['status' => 'missing'];
                continue;
            }

            $environmentCode = strtolower((string) $environment);
            $label = $report['label'] ?? strtoupper($environmentCode);
            $mtime = (int) filemtime($path);
            if (! $force && ! $this->needsSync($environmentCode, $mtime, $path)) {
                $results[$environment] = ['status' => 'skipped', 'mtime' => $mtime];
                continue;
            }

            $env = MappingEnvironment::updateOrCreate(
                ['code' => $environmentCode],
                [
                    'label' => $label,
                    'source_path' => $path,
                    'source_mtime' => $mtime,
                ],
            );

            $data = $this->configService->loadReportWithFields($report, [
                'field_scan_limit' => config('config-report.field_scan_limit', 500),
            ]);
            $objects = $data['objects_by_name'] ?? [];
            $objectNames = [];
            $objectCache = [];
            $fieldCache = [];

            DB::transaction(function () use (
                $env,
                $objects,
                &$objectNames,
                &$objectCache,
                &$fieldCache,
            ): void {
                foreach ($objects as $objectName => $payload) {
                    $objectName = trim((string) $objectName);
                    if ($objectName === '') {
                        continue;
                    }

                    if (isset($objectCache[$objectName])) {
                        $object = $objectCache[$objectName];
                    } else {
                        $object = MappingObject::firstOrCreate(['name' => $objectName]);
                        $objectCache[$objectName] = $object;
                    }

                    $fields = $this->normalizeFieldEntries($payload['fields'] ?? []);
                    $fieldCount = count($fields);

                    MappingObjectEnv::updateOrCreate(
                        [
                            'object_id' => $object->id,
                            'environment_id' => $env->id,
                        ],
                        [
                            'table_name' => $payload['table_name'] ?? null,
                            'field_count' => $fieldCount,
                        ],
                    );

                    foreach ($fields as $field) {
                        $name = trim((string) ($field['name'] ?? ''));
                        $machine = trim((string) ($field['machine_name'] ?? ''));
                        $machine = $machine !== '' ? $machine : null;

                        if ($name === '' && $machine === null) {
                            continue;
                        }

                        if ($name === '') {
                            $name = (string) $machine;
                        }
                        $nameKey = $this->normalizeKey($name);

                        $cacheKey = $object->id.'|'.$nameKey;
                        if (isset($fieldCache[$cacheKey])) {
                            $objectField = $fieldCache[$cacheKey];
                        } else {
                            $objectField = MappingObjectField::updateOrCreate(
                                [
                                    'object_id' => $object->id,
                                    'name_key' => $nameKey,
                                ],
                                [
                                    'name' => $name,
                                ],
                            );
                            $fieldCache[$cacheKey] = $objectField;
                        }

                        MappingFieldEnv::updateOrCreate(
                            [
                                'object_field_id' => $objectField->id,
                                'environment_id' => $env->id,
                            ],
                            [
                                'machine_name' => $machine,
                            ],
                        );
                    }

                    $objectNames[] = $objectName;
                }

                $objectIds = array_values(array_map(
                    static fn (MappingObject $object): int => $object->id,
                    $objectCache,
                ));

                $objectEnvQuery = MappingObjectEnv::query()
                    ->where('environment_id', $env->id);
                if ($objectIds !== []) {
                    $objectEnvQuery->whereNotIn('object_id', $objectIds);
                }
                $objectEnvQuery->delete();

                $fieldEnvQuery = DB::table('mapping_field_envs as fe')
                    ->join('mapping_object_fields as f', 'fe.object_field_id', '=', 'f.id')
                    ->where('fe.environment_id', $env->id);
                if ($objectIds !== []) {
                    $fieldEnvQuery->whereNotIn('f.object_id', $objectIds);
                }
                $fieldEnvQuery->delete();

                DB::table('mapping_object_fields as f')
                    ->leftJoin('mapping_field_envs as fe', 'f.id', '=', 'fe.object_field_id')
                    ->whereNull('fe.id')
                    ->delete();

                DB::table('mapping_objects as o')
                    ->leftJoin('mapping_object_envs as oe', 'o.id', '=', 'oe.object_id')
                    ->whereNull('oe.id')
                    ->delete();
            });

            $results[$environment] = [
                'status' => 'synced',
                'mtime' => $mtime,
                'count' => count($objectNames),
            ];
            $synced[] = $environmentCode;
        }

        if (in_array('dev2', $synced, true) || in_array('test', $synced, true)) {
            $this->rebuildMaterializedMaps('dev2', 'test');
            $this->rebuildMaterializedMaps('test', 'dev2');
        }

        return $results;
    }

    private function normalizeFieldEntries(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $name = trim($field);
                $machine = null;
            } elseif (is_array($field)) {
                $name = trim((string) ($field['name'] ?? ''));
                $machine = trim((string) ($field['machine_name'] ?? ''));
                $machine = $machine !== '' ? $machine : null;
            } else {
                continue;
            }

            if ($name === '' && $machine === null) {
                continue;
            }

            $keySource = $name !== '' ? $name : $machine;
            $key = strtolower((string) $keySource);

            if (isset($normalized[$key])) {
                if ($normalized[$key]['machine_name'] === null && $machine !== null) {
                    $normalized[$key]['machine_name'] = $machine;
                }
                if ($normalized[$key]['name'] === '' && $name !== '') {
                    $normalized[$key]['name'] = $name;
                }
                continue;
            }

            $normalized[$key] = [
                'name' => $name !== '' ? $name : ($machine ?? ''),
                'machine_name' => $machine,
            ];
        }

        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($normalized);
    }

    private function needsSync(string $environment, int $mtime, string $path): bool
    {
        $current = MappingEnvironment::query()
            ->where('code', $environment)
            ->first();

        if (! $current) {
            return true;
        }

        if ($current->source_path !== null && $current->source_path !== $path) {
            return true;
        }

        return $mtime > (int) ($current->source_mtime ?? 0);
    }

    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        return strtolower($value);
    }

    private function rebuildMaterializedMaps(string $fromCode, string $toCode): void
    {
        if (
            ! Schema::hasTable('mapping_object_field_maps')
            || ! Schema::hasTable('mapping_object_fields')
            || ! Schema::hasTable('mapping_field_envs')
            || ! Schema::hasTable('mapping_object_envs')
        ) {
            return;
        }

        $fromEnv = MappingEnvironment::query()->where('code', $fromCode)->first();
        $toEnv = MappingEnvironment::query()->where('code', $toCode)->first();

        if (! $fromEnv || ! $toEnv) {
            return;
        }

        MappingObjectFieldMap::query()
            ->where('from_environment_id', $fromEnv->id)
            ->where('to_environment_id', $toEnv->id)
            ->delete();

        $now = now()->toDateTimeString();

        $query = DB::table('mapping_object_fields as f')
            ->join('mapping_field_envs as fe_from', function ($join) use ($fromEnv) {
                $join->on('fe_from.object_field_id', '=', 'f.id')
                    ->where('fe_from.environment_id', '=', $fromEnv->id);
            })
            ->join('mapping_field_envs as fe_to', function ($join) use ($toEnv) {
                $join->on('fe_to.object_field_id', '=', 'f.id')
                    ->where('fe_to.environment_id', '=', $toEnv->id);
            })
            ->join('mapping_object_envs as oe_from', function ($join) use ($fromEnv) {
                $join->on('oe_from.object_id', '=', 'f.object_id')
                    ->where('oe_from.environment_id', '=', $fromEnv->id);
            })
            ->join('mapping_object_envs as oe_to', function ($join) use ($toEnv) {
                $join->on('oe_to.object_id', '=', 'f.object_id')
                    ->where('oe_to.environment_id', '=', $toEnv->id);
            })
            ->whereNotNull('fe_from.machine_name')
            ->whereNotNull('fe_to.machine_name')
            ->select(
                'f.id',
                'f.object_id',
                'f.name',
                'fe_from.machine_name as from_column',
                'fe_to.machine_name as to_column',
                'oe_from.table_name as from_table',
                'oe_to.table_name as to_table',
            )
            ->orderBy('f.id');

        $query->chunk(500, function ($rows) use ($fromEnv, $toEnv, $now): void {
            $payload = [];
            foreach ($rows as $row) {
                $payload[] = [
                    'object_id' => $row->object_id,
                    'from_environment_id' => $fromEnv->id,
                    'to_environment_id' => $toEnv->id,
                    'from_table' => $row->from_table,
                    'to_table' => $row->to_table,
                    'field_name' => $row->name,
                    'from_column' => $row->from_column,
                    'to_column' => $row->to_column,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($payload !== []) {
                DB::table('mapping_object_field_maps')->insert($payload);
            }
        });
    }
}
