<?php

namespace App\Http\Controllers;

use App\Http\Requests\SqlRunnerRunRequest;
use App\Services\SqlRunner\SqlRunnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SqlRunnerController extends Controller
{
    public function index(SqlRunnerService $service): Response
    {
        return Inertia::render('sql-runner', [
            'environments' => $service->environments(),
            'limits' => $service->limits(),
            'input' => session('input'),
            'run' => session('run'),
        ]);
    }

    public function run(SqlRunnerRunRequest $request, SqlRunnerService $service): RedirectResponse
    {
        $environmentKeys = array_values((array) $request->input('environments', []));
        $query = (string) $request->input('query');
        $limit = $request->input('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        try {
            $result = $service->runQuery($query, $environmentKeys, $limit);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'query' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('sql-runner.index')
            ->with([
                'input' => [
                    'environments' => $environmentKeys,
                    'query' => $query,
                    'limit' => $limit,
                ],
                'run' => $result,
            ]);
    }
}
