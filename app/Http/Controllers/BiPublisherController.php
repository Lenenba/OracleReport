<?php

namespace App\Http\Controllers;

use App\Http\Requests\BiPublisherRunRequest;
use App\Services\BiPublisher\BiPublisherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BiPublisherController extends Controller
{
    public function index(BiPublisherService $service): Response
    {
        $input = session('input');
        $run = session('run');
        $defaultFormat = is_array($input) && isset($input['format']) ? $input['format'] : 'pdf';

        return Inertia::render('bi-publisher', [
            'environments' => $service->environments(),
            'defaults' => [
                'format' => $defaultFormat,
            ],
            'input' => $input,
            'run' => $run,
        ]);
    }

    public function run(BiPublisherRunRequest $request, BiPublisherService $service): RedirectResponse
    {
        $environmentKeys = array_values((array) $request->input('environments', []));
        $reportPath = (string) $request->input('report_path');
        $reportPaths = (array) $request->input('report_paths', []);
        $reportPaths = array_filter($reportPaths, static fn ($value) => is_string($value) && trim($value) !== '');
        $format = (string) $request->input('format', 'pdf');
        $parameters = (string) $request->input('parameters', '');

        try {
            $result = $service->runReport($reportPath, $format, $parameters, $environmentKeys, $reportPaths);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'parameters' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('bi-publisher.index')
            ->with([
                'input' => [
                    'environments' => $environmentKeys,
                    'report_path' => $reportPath,
                    'report_paths' => $reportPaths,
                    'format' => $format,
                    'parameters' => $parameters,
                ],
                'run' => $result,
            ]);
    }
}
