<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClipReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'clip_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'artifact_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'policy_revision' => ['required', 'string', 'max:100'],
            'verdict' => ['required', Rule::in(['allowed', 'rejected'])],
            'checks' => ['required', 'array', 'min:1', 'max:20'],
            'checks.*' => ['required', 'array:name,passed,evidence'],
            'checks.*.name' => ['required', 'string', 'max:80', 'distinct:strict'],
            'checks.*.passed' => ['required', 'boolean'],
            'checks.*.evidence' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->input('verdict') === 'allowed' && collect($this->input('checks', []))->contains(fn ($check) => is_array($check) && ! ($check['passed'] ?? false))) {
                $validator->errors()->add('verdict', 'An allowed release requires every supplied check to pass.');
            }
        }];
    }
}
