<?php

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /_e2e/login/{user}?to=/vote: sign in as a seeded user, for the browser
 * end-to-end suite only (#155). Sign-in is otherwise Twitch OAuth,
 * which a headless browser in CI cannot do.
 *
 * Registered only when APP_ENV=testing (bootstrap/app.php), and refusing with
 * a 404 outside testing too, so it can never sign anyone in in production.
 */
class LoginAsController extends Controller
{
    public static function enabled(): bool
    {
        return app()->environment('testing');
    }

    public function __invoke(Request $request, int $user): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        Auth::login(User::findOrFail($user));
        $request->session()->regenerate();

        // Only same-site paths, so this cannot become an open redirect.
        $to = (string) $request->query('to', '/vote');

        return redirect(str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/vote');
    }
}
