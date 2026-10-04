<?php

namespace App\Http\Controllers;

use App\Enums\Overlay;
use App\Http\Requests\ExchangeOverlayTokenRequest;
use App\Support\OverlayGrant;
use Illuminate\Http\Response;

class OverlaySessionController extends Controller
{
    /**
     * Trade the overlay token (checked by the Form Request) for a short-lived
     * grant cookie scoped to this overlay. The bootstrap page then reloads.
     */
    public function __invoke(ExchangeOverlayTokenRequest $request, Overlay $overlay): Response
    {
        return response()->noContent()
            ->withCookie(OverlayGrant::issue($overlay, $request))
            ->header('Cache-Control', 'no-store, private');
    }
}
