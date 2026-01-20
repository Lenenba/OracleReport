<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfigCompareRunRequest;
use App\Http\Requests\ConfigCompareSaveRequest;
use App\Http\Requests\ConfigCompareTransformRequest;
use App\Models\ConfigCompareEntry;
use App\Models\ConfigCompareRun;
use App\Services\ConfigReport\ConfigReportService;
use App\Services\ObjectMapping\ObjectMappingMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ConfigCompareController extends Controller
{
    public function index(ConfigReportService $service): Response
    {
        $history = [];

        if (Schema::hasTable('config_compare_runs')) {
            $runs = ConfigCompareRun::query()
                ->latest()
                ->get();

            foreach ($runs as $run) {
                $history[] = [
                    'id' => 'config-'.$run->id,
                    'entry_id' => $run->id,
                    'type' => 'config',
                    'name' => $run->left_label.' vs '.$run->right_label,
                    'status' => $run->status,
                    'created_at' => optional($run->created_at)->toIso8601String(),
                    'payload' => $run->payload ?? [],
                ];
            }
        }

        if (Schema::hasTable('config_compare_entries')) {
            $entries = ConfigCompareEntry::query()
                ->latest()
                ->get();

            foreach ($entries as $entry) {
                $entryName = $entry->name ?: $entry->source_label.' -> '.$entry->target_label;
                $history[] = [
                    'id' => 'sql-'.$entry->id,
                    'entry_id' => $entry->id,
                    'type' => 'sql',
                    'name' => $entryName,
                    'status' => 'saved',
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                    'payload' => [
                        'name' => $entryName,
                        'direction' => $entry->direction,
                    'source_label' => $entry->source_label,
                    'target_label' => $entry->target_label,
                    'input_sql' => $entry->input_sql,
                    'output_sql' => $entry->output_sql,
                    'replacements' => $entry->replacements ?? [],
                    'issues' => $entry->issues ?? [],
                ],
            ];
        }
        }

        usort($history, static function (array $left, array $right): int {
            return strcmp((string) $right['created_at'], (string) $left['created_at']);
        });

        return Inertia::render('config-compare', [
            'report_sources' => $service->reportSources(),
            'history' => $history,
            'notice' => session('notice'),
        ]);
    }

    public function compare(ConfigCompareRunRequest $request, ConfigReportService $service): RedirectResponse
    {
        if (! Schema::hasTable('config_compare_runs')) {
            throw ValidationException::withMessages([
                'left_key' => 'Database table not found. Run migrations first.',
            ]);
        }

        $leftFile = $request->file('left_file');
        $rightFile = $request->file('right_file');

        $leftTempPath = null;
        $rightTempPath = null;

        try {
            $leftReport = $this->buildReportSource($request->input('left_key'), $leftFile, $leftTempPath);
            $rightReport = $this->buildReportSource($request->input('right_key'), $rightFile, $rightTempPath);

            $options = [];
            $rowLimit = $request->input('row_scan_limit');
            if (is_numeric($rowLimit)) {
                $options['row_scan_limit'] = (int) $rowLimit;
            }
            $sheetSuffix = $request->input('sheet_suffix');
            if (is_string($sheetSuffix) && trim($sheetSuffix) !== '') {
                $options['sheet_suffix'] = trim($sheetSuffix);
            }

            $result = $service->compareSources($leftReport, $rightReport, $options);
            $hasErrors = ! empty($result['errors']['left']) || ! empty($result['errors']['right']);
            $strictOk = (bool) ($result['strict_ok'] ?? false);

            ConfigCompareRun::create([
                'left_label' => $result['left']['label'] ?? $leftReport['label'],
                'right_label' => $result['right']['label'] ?? $rightReport['label'],
                'left_source' => $leftReport['source'] ?? null,
                'right_source' => $rightReport['source'] ?? null,
                'status' => $hasErrors ? 'error' : ($strictOk ? 'completed' : 'mismatch'),
                'payload' => $result,
                'user_id' => $request->user()?->id,
            ]);
        } finally {
            if ($leftTempPath) {
                Storage::delete($leftTempPath);
            }
            if ($rightTempPath) {
                Storage::delete($rightTempPath);
            }
        }

        return redirect()
            ->route('config-compare.index')
            ->with([
                'notice' => 'Comparaison enregistree.',
            ]);
    }

    public function transform(
        ConfigCompareTransformRequest $request,
        ConfigReportService $service,
        ObjectMappingMappingService $mappingService,
    ): RedirectResponse
    {
        if (! Schema::hasTable('config_compare_entries')) {
            throw ValidationException::withMessages([
                'sql' => 'Database table not found. Run migrations first.',
            ]);
        }

        $direction = (string) $request->input('direction');
        $name = trim((string) $request->input('name'));
        $sql = (string) $request->input('sql');
        $sourceLabel = trim((string) $request->input('source_label'));
        $targetLabel = trim((string) $request->input('target_label'));

        try {
            $mappingSet = null;
            if (Schema::hasTable('mapping_object_field_maps')) {
                $mappingSet = $mappingService->buildMappings();
            }

            $hasMappings = $mappingSet
                && (($mappingSet['tables']['dev2_to_test'] ?? []) !== []
                    || ($mappingSet['tables']['test_to_dev2'] ?? []) !== []
                    || ($mappingSet['columns']['dev2_to_test'] ?? []) !== []
                    || ($mappingSet['columns']['test_to_dev2'] ?? []) !== []
                    || ($mappingSet['object_fields']['dev2_to_test'] ?? []) !== []
                    || ($mappingSet['object_fields']['test_to_dev2'] ?? []) !== []
                    || ($mappingSet['objects']['dev2_to_test'] ?? []) !== []
                    || ($mappingSet['objects']['test_to_dev2'] ?? []) !== []);

            if (! $hasMappings) {
                $comparison = $service->compare();
                $mappingSet = $comparison['mapping'] ?? [];
            }

            $result = $service->transformSql($sql, $direction, $mappingSet);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'sql' => $exception->getMessage(),
            ]);
        }

        if (! empty($result['error'])) {
            throw ValidationException::withMessages([
                'sql' => (string) $result['error'],
            ]);
        }

        $defaultSource = $direction === 'test_to_dev2' ? 'TEST' : 'DEV2';
        $defaultTarget = $direction === 'test_to_dev2' ? 'DEV2' : 'TEST';

        ConfigCompareEntry::create([
            'name' => $name,
            'direction' => $direction,
            'source_label' => $sourceLabel === '' ? $defaultSource : $sourceLabel,
            'target_label' => $targetLabel === '' ? $defaultTarget : $targetLabel,
            'input_sql' => $result['input'],
            'output_sql' => $result['output'],
            'replacements' => $result['replacements'] ?? [],
            'issues' => $result['issues'] ?? [],
            'user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('config-compare.index')
            ->with([
                'notice' => 'SQL transforme et enregistre.',
            ]);
    }

    public function save(ConfigCompareSaveRequest $request): RedirectResponse
    {
        if (! Schema::hasTable('config_compare_entries')) {
            throw ValidationException::withMessages([
                'output_sql' => 'Database table not found. Run migrations first.',
            ]);
        }

        $direction = (string) $request->input('direction');
        $name = trim((string) $request->input('name'));
        $inputSql = (string) $request->input('input_sql');
        $outputSql = (string) $request->input('output_sql');
        $replacements = $request->input('replacements') ?? [];
        $sourceLabel = trim((string) $request->input('source_label'));
        $targetLabel = trim((string) $request->input('target_label'));
        $defaultSource = $direction === 'test_to_dev2' ? 'TEST' : 'DEV2';
        $defaultTarget = $direction === 'test_to_dev2' ? 'DEV2' : 'TEST';

        ConfigCompareEntry::create([
            'name' => $name,
            'direction' => $direction,
            'source_label' => $sourceLabel === '' ? $defaultSource : $sourceLabel,
            'target_label' => $targetLabel === '' ? $defaultTarget : $targetLabel,
            'input_sql' => $inputSql,
            'output_sql' => $outputSql,
            'replacements' => $replacements,
            'issues' => $request->input('issues') ?? [],
            'user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('config-compare.index')
            ->with([
                'input' => [
                    'direction' => $direction,
                    'sql' => $inputSql,
                    'name' => $name,
                ],
                'transform' => [
                    'input' => $inputSql,
                'output' => $outputSql,
                'replacements' => $replacements,
                'issues' => $request->input('issues') ?? [],
                'error' => null,
            ],
            'notice' => 'Enregistre dans la base.',
        ]);
    }

    public function destroyEntry(ConfigCompareEntry $entry): RedirectResponse
    {
        $entry->delete();

        return redirect()
            ->route('config-compare.index')
            ->with([
                'notice' => 'Entree supprimee.',
            ]);
    }

    public function destroyRun(ConfigCompareRun $run): RedirectResponse
    {
        $run->delete();

        return redirect()
            ->route('config-compare.index')
            ->with([
                'notice' => 'Comparaison supprimee.',
            ]);
    }

    private function buildReportSource(?string $key, ?UploadedFile $file, ?string &$tempPath): array
    {
        if ($file instanceof UploadedFile) {
            $tempPath = $file->store('config-compare');

            return [
                'label' => $file->getClientOriginalName(),
                'path' => storage_path('app/'.$tempPath),
                'source' => $file->getClientOriginalName(),
            ];
        }

        $reports = config('config-report.reports', []);
        $report = $reports[$key] ?? [];
        $label = $report['label'] ?? strtoupper((string) $key);
        $path = $report['path'] ?? null;

        return [
            'label' => $label,
            'path' => $path,
            'source' => $key,
        ];
    }
}
