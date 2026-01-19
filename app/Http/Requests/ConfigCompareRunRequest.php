<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfigCompareRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reportKeys = array_keys(config('config-report.reports', []));

        return [
            'left_key' => ['nullable', 'string', Rule::in($reportKeys), 'required_without:left_file'],
            'right_key' => ['nullable', 'string', Rule::in($reportKeys), 'required_without:right_file', 'different:left_key'],
            'left_file' => ['nullable', 'file', 'mimes:xlsx', 'required_without:left_key'],
            'right_file' => ['nullable', 'file', 'mimes:xlsx', 'required_without:right_key'],
            'row_scan_limit' => ['nullable', 'integer', 'min:10', 'max:200'],
            'sheet_suffix' => ['nullable', 'string', 'max:10'],
        ];
    }
}
