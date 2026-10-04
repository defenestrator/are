<?php

namespace App\Http\Requests;

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /overlay/{overlay}/session with {"token": "…"}, the token the bootstrap
 * page read from the URL fragment. It travels in the body, which access
 * logs do not record.
 */
class ExchangeOverlayTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $overlay = $this->route('overlay');
        $overlay = $overlay instanceof Overlay ? $overlay : Overlay::tryFrom((string) $overlay);

        return $overlay !== null && OverlayToken::verify($overlay, $this->input('token'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
        ];
    }
}
