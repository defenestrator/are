<?php

namespace App\Http\Controllers;

use App\Enums\Overlay;
use App\Enums\OverlayLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OverlayController extends Controller
{
    /**
     * Render an OBS overlay. EnsureOverlayToken has already checked the token.
     */
    public function __invoke(Request $request, Overlay $overlay): View
    {
        return view($overlay->view(), [
            'overlay' => $overlay,
            'layout' => OverlayLayout::fromQuery($request->query('layout')),
        ]);
    }
}
