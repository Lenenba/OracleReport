<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SqlRunnerRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $limits = config('sql-runner.limits', []);
        $maxRows = (int) ($limits['max_rows'] ?? 1000);
        $maxLength = (int) ($limits['max_query_length'] ?? 6000);
        $environmentKeys = array_keys(config('sql-runner.environments', []));

        return [
            'environments' => ['required', 'array', 'min:1'],
            'environments.*' => ['string', Rule::in($environmentKeys)],
            'query' => ['required', 'string', 'max:'.$maxLength],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$maxRows],
        ];
    }
}
