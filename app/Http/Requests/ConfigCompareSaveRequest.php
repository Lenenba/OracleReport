<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfigCompareSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxLength = (int) config('config-report.sql_transform.max_length', 12000);

        return [
            'direction' => ['required', 'string', Rule::in(['dev2_to_test', 'test_to_dev2'])],
            'source_label' => ['required', 'string', 'max:60'],
            'target_label' => ['required', 'string', 'max:60'],
            'input_sql' => ['required', 'string', 'max:'.$maxLength],
            'output_sql' => ['required', 'string', 'max:'.$maxLength],
            'replacements' => ['nullable', 'array'],
            'replacements.*.from' => ['required_with:replacements', 'string', 'max:200'],
            'replacements.*.to' => ['required_with:replacements', 'string', 'max:200'],
            'replacements.*.count' => ['required_with:replacements', 'integer', 'min:1'],
        ];
    }
}
