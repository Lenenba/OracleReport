<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BiPublisherRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $environmentKeys = array_keys(config('bi-publisher.environments', []));

        return [
            'environments' => ['required', 'array', 'min:1'],
            'environments.*' => ['string', Rule::in($environmentKeys)],
            'report_path' => ['required', 'string', 'max:500'],
            'report_paths' => ['nullable', 'array'],
            'report_paths.*' => ['nullable', 'string', 'max:500'],
            'format' => [
                'nullable',
                'string',
                Rule::in(['pdf', 'csv', 'xlsx', 'xml', 'html', 'text', 'rtf', 'pptx']),
            ],
            'parameters' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
