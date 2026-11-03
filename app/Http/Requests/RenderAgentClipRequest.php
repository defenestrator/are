<?php

namespace App\Http\Requests;

use App\Clips\ClipReview;
use Illuminate\Foundation\Http\FormRequest;

class RenderAgentClipRequest extends FormRequest
{
    // Authentication, token abilities and the agent gate are route middleware.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clip_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'title' => ['required', 'string', 'max:'.ClipReview::TITLE_MAX],
            'trim_start' => ['required', 'numeric', 'min:0'],
            'trim_end' => ['required', 'numeric', 'gt:trim_start'],
        ];
    }
}
