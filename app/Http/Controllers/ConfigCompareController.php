<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfigCompareSaveRequest;
use App\Http\Requests\ConfigCompareTransformRequest;
use App\Models\ConfigCompareEntry;
use App\Services\ConfigReport\ConfigReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ConfigCompareController extends Controller
{
    public function index(ConfigReportService $service): Response
    {
        $savedEntries = [];

        if (Schema::hasTable('config_compare_entries')) {
            $savedEntries = ConfigCompareEntry::query()
                ->latest()
                ->get()
                ->map(fn (ConfigCompareEntry $entry) => [
                    'id' => $entry->id,
                    'direction' => $entry->direction,
                    'source_label' => $entry->source_label,
                    'target_label' => $entry->target_label,
                    'input_sql' => $entry->input_sql,
                    'output_sql' => $entry->output_sql,
                    'replacements' => $entry->replacements ?? [],
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                ])
                ->values();
        }

        return Inertia::render('config-compare', [
            'reports' => $service->reportSources(),
            'comparison' => $service->compare(),
            'saved_entries' => $savedEntries,
            'input' => session('input'),
            'transform' => session('transform'),
            'notice' => session('notice'),
        ]);
    }

    public function transform(ConfigCompareTransformRequest $request, ConfigReportService $service): RedirectResponse
    {
        $direction = (string) $request->input('direction');
        $sql = (string) $request->input('sql');

        try {
            $comparison = $service->compare();
            $result = $service->transformSql($sql, $direction, $comparison['mapping'] ?? []);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'sql' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('config-compare.index')
            ->with([
                'input' => [
                    'direction' => $direction,
                    'sql' => $sql,
                ],
                'transform' => $result,
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
        $inputSql = (string) $request->input('input_sql');
        $outputSql = (string) $request->input('output_sql');
        $replacements = $request->input('replacements') ?? [];
        $sourceLabel = trim((string) $request->input('source_label'));
        $targetLabel = trim((string) $request->input('target_label'));
        $defaultSource = $direction === 'test_to_dev2' ? 'TEST' : 'DEV2';
        $defaultTarget = $direction === 'test_to_dev2' ? 'DEV2' : 'TEST';

        ConfigCompareEntry::create([
            'direction' => $direction,
            'source_label' => $sourceLabel === '' ? $defaultSource : $sourceLabel,
            'target_label' => $targetLabel === '' ? $defaultTarget : $targetLabel,
            'input_sql' => $inputSql,
            'output_sql' => $outputSql,
            'replacements' => $replacements,
            'user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('config-compare.index')
            ->with([
                'input' => [
                    'direction' => $direction,
                    'sql' => $inputSql,
                ],
                'transform' => [
                    'input' => $inputSql,
                    'output' => $outputSql,
                    'replacements' => $replacements,
                    'error' => null,
                ],
                'notice' => 'Saved to database.',
            ]);
    }
}
