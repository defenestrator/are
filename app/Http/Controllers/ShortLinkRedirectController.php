<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShortLinkRedirectController extends Controller
{
    /**
     * GET (and HEAD) /go/{code}: record the click if it is a person's, and
     * 302 to the tagged destination either way. Unknown codes 404 through
     * route model binding. Throttled by the "short-links" limiter.
     */
    public function __invoke(Request $request, ShortLink $shortLink): RedirectResponse
    {
        $shortLink->recordClick($request);

        return redirect()->away($shortLink->destinationUrl(), 302);
    }
}
