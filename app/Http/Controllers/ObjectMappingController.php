<?php

namespace App\Http\Controllers;

use App\Models\MappingEnvironment;
use App\Models\MappingObject;
use App\Models\MappingObjectEnv;
use App\Services\ObjectMapping\ObjectMappingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ObjectMappingController extends Controller
{
    public function index(): Response
    {
        $reports = config('config-report.reports', []);
        $dev2Report = $reports['dev2'] ?? [];
        $testReport = $reports['test'] ?? [];

        if (
            ! Schema::hasTable('mapping_objects')
            || ! Schema::hasTable('mapping_object_envs')
            || ! Schema::hasTable('mapping_environments')
        ) {
            return Inertia::render('object-mapping', [
                'entries' => [],
                'labels' => [
                    'dev2' => $dev2Report['label'] ?? 'DEV2',
                    'test' => $testReport['label'] ?? 'TEST',
                ],
                'last_refresh' => [
                    'dev2' => null,
                    'test' => null,
                ],
                'errors' => [
                    'dev2' => ['Mapping tables missing. Run migrations.'],
                    'test' => ['Mapping tables missing. Run migrations.'],
                ],
                'notice' => session('notice'),
            ]);
        }

        $envs = MappingEnvironment::query()
            ->whereIn('code', ['dev2', 'test'])
            ->get()
            ->keyBy('code');
        $dev2EnvId = $envs->get('dev2')?->id;
        $testEnvId = $envs->get('test')?->id;

        $entries = [];
        if ($dev2EnvId || $testEnvId) {
            $rows = DB::table('mapping_objects as o')
                ->leftJoin('mapping_object_envs as dev2', function ($join) use ($dev2EnvId) {
                    $join->on('dev2.object_id', '=', 'o.id');
                    if ($dev2EnvId) {
                        $join->where('dev2.environment_id', '=', $dev2EnvId);
                    } else {
                        $join->whereRaw('1 = 0');
                    }
                })
                ->leftJoin('mapping_object_envs as test', function ($join) use ($testEnvId) {
                    $join->on('test.object_id', '=', 'o.id');
                    if ($testEnvId) {
                        $join->where('test.environment_id', '=', $testEnvId);
                    } else {
                        $join->whereRaw('1 = 0');
                    }
                })
                ->select(
                    'o.name',
                    'dev2.table_name as dev2_table',
                    'dev2.field_count as dev2_field_count',
                    'test.table_name as test_table',
                    'test.field_count as test_field_count',
                )
                ->orderBy('o.name')
                ->get();

            foreach ($rows as $row) {
                $dev2Table = $row->dev2_table;
                $testTable = $row->test_table;
                $status = $dev2Table && $testTable && $dev2Table === $testTable ? 'Identique' : 'Different';

                $entries[] = [
                    'name' => $row->name,
                    'dev2' => [
                        'table' => $dev2Table,
                        'fields' => [],
                        'field_count' => (int) ($row->dev2_field_count ?? 0),
                    ],
                    'test' => [
                        'table' => $testTable,
                        'fields' => [],
                        'field_count' => (int) ($row->test_field_count ?? 0),
                    ],
                    'status' => $status,
                ];
            }
        }

        $lastRefresh = [
            'dev2' => $envs->get('dev2')?->updated_at,
            'test' => $envs->get('test')?->updated_at,
        ];

        $errors = [
            'dev2' => [],
            'test' => [],
        ];
        $dev2Path = $dev2Report['path'] ?? null;
        if (! is_string($dev2Path) || $dev2Path === '' || ! file_exists($dev2Path)) {
            $errors['dev2'][] = 'Config report file not found.';
        }
        $testPath = $testReport['path'] ?? null;
        if (! is_string($testPath) || $testPath === '' || ! file_exists($testPath)) {
            $errors['test'][] = 'Config report file not found.';
        }

        return Inertia::render('object-mapping', [
            'entries' => $entries,
            'labels' => [
                'dev2' => $dev2Report['label'] ?? 'DEV2',
                'test' => $testReport['label'] ?? 'TEST',
            ],
            'last_refresh' => $lastRefresh,
            'errors' => $errors,
            'notice' => session('notice'),
        ]);
    }

    public function details(Request $request): JsonResponse
    {
        $name = trim((string) $request->query('name'));
        if ($name === '') {
            return response()->json(['error' => 'Missing object name.'], 422);
        }

        if (
            ! Schema::hasTable('mapping_objects')
            || ! Schema::hasTable('mapping_object_envs')
            || ! Schema::hasTable('mapping_object_fields')
            || ! Schema::hasTable('mapping_field_envs')
            || ! Schema::hasTable('mapping_environments')
        ) {
            return response()->json(['error' => 'Mapping tables missing.'], 422);
        }

        $object = MappingObject::query()
            ->where('name', $name)
            ->first();

        if (! $object) {
            return response()->json(['error' => 'Object not found.'], 404);
        }

        $envs = MappingEnvironment::query()
            ->whereIn('code', ['dev2', 'test'])
            ->get()
            ->keyBy('code');
        $dev2EnvId = $envs->get('dev2')?->id;
        $testEnvId = $envs->get('test')?->id;

        $dev2Row = $dev2EnvId
            ? MappingObjectEnv::query()
                ->where('environment_id', $dev2EnvId)
                ->where('object_id', $object->id)
                ->first()
            : null;
        $testRow = $testEnvId
            ? MappingObjectEnv::query()
                ->where('environment_id', $testEnvId)
                ->where('object_id', $object->id)
                ->first()
            : null;

        $dev2Fields = $dev2EnvId
            ? $this->loadFields($object->id, $dev2EnvId)
            : [];
        $testFields = $testEnvId
            ? $this->loadFields($object->id, $testEnvId)
            : [];

        $dev2Table = $dev2Row?->table_name;
        $testTable = $testRow?->table_name;
        $status = $dev2Table && $testTable && $dev2Table === $testTable ? 'Identique' : 'Different';

        return response()->json([
            'name' => $name,
            'status' => $status,
            'dev2' => [
                'table' => $dev2Table,
                'fields' => $dev2Fields,
                'field_count' => $dev2Row?->field_count ?? 0,
                'errors' => [],
            ],
            'test' => [
                'table' => $testTable,
                'fields' => $testFields,
                'field_count' => $testRow?->field_count ?? 0,
                'errors' => [],
            ],
        ]);
    }

    public function refresh(ObjectMappingSyncService $syncService): RedirectResponse
    {
        $result = $syncService->sync(true);
        $notice = 'Cartographie rafraichie.';
        if (is_array($result) && ($result['status'] ?? null) === 'missing_tables') {
            $notice = 'Tables de cartographie manquantes. Lancez les migrations.';
        }

        return redirect()
            ->route('object-mapping.index')
            ->with([
                'notice' => $notice,
            ]);
    }

    private function loadFields(int $objectId, int $environmentId): array
    {
        return DB::table('mapping_object_fields as f')
            ->join('mapping_field_envs as fe', 'fe.object_field_id', '=', 'f.id')
            ->where('f.object_id', $objectId)
            ->where('fe.environment_id', $environmentId)
            ->select('f.name', 'fe.machine_name')
            ->orderBy('f.name')
            ->get()
            ->map(static fn ($row) => [
                'name' => $row->name,
                'machine_name' => $row->machine_name,
            ])
            ->all();
    }
}
