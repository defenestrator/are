<?php

namespace App\Http\Requests;

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /overlay/{overlay}/session with {"token": "…"}, the token the bootstrap
 * page read from the URL fragment. It travels in the body, which access logs
 * don't record.
 *
 * This route is exempt from CSRF (bootstrap/app.php). OBS browser sources
 * share one cookie jar, so overlays bootstrapping together would overwrite
 * each other's session and its CSRF token, and all but one would get a 419.
 * A session-bound token adds nothing here anyway: the body token is the
 * credential, and a forged cross-site POST could at most give a victim's
 * browser a grant for the attacker's own overlay. The cross-site guard is
 * the same-origin check below.
 */
class ExchangeOverlayTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $overlay = $this->route('overlay');
        $overlay = $overlay instanceof Overlay ? $overlay : Overlay::tryFrom((string) $overlay);

        return $overlay !== null
            && $this->isSameOrigin()
            && OverlayToken::verify($overlay, $this->input('token'));
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

    /**
     * Only the bootstrap page, on this origin, may exchange. Prefer
     * Sec-Fetch-Site, which the browser computes itself, so a proxy that
     * rewrites the scheme or host can't confuse it. Otherwise require Origin
     * to match this request's origin, or APP_URL's for when TLS ends at the
     * proxy. Reject a request that sends neither header: every browser that
     * runs the bootstrap script (OBS's Chromium included) sends both.
     */
    private function isSameOrigin(): bool
    {
        $site = $this->headers->get('Sec-Fetch-Site');

        if ($site !== null) {
            return $site === 'same-origin';
        }

        $origin = $this->headers->get('Origin');

        if ($origin === null || $origin === 'null') {
            return false;
        }

        return in_array(rtrim($origin, '/'), array_filter([
            $this->getSchemeAndHttpHost(),
            self::originOf((string) config('app.url')),
        ]), true);
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
