<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;

class ShortLinkRedirectController extends Controller
{
    /**
     * GET /go/{code}: count the click, remember its UTM params for lead
     * attribution, and 302 to the tagged destination. Unknown codes 404
     * through route model binding.
     */
    public function __invoke(ShortLink $shortLink): RedirectResponse
    {
        $shortLink->recordClick();

        return redirect()->away($shortLink->destinationUrl(), 302);
    }
}
