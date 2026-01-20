<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfigCompareTransformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxLength = (int) config('config-report.sql_transform.max_length', 12000);

        return [
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['required', 'string', Rule::in(['dev2_to_test', 'test_to_dev2'])],
            'sql' => ['required', 'string', 'max:'.$maxLength],
            'source_label' => ['nullable', 'string', 'max:60'],
            'target_label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
