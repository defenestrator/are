<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Call-to-action lower-third (/overlay/cta)
    |--------------------------------------------------------------------------
    |
    | The overlay rotates through these items in order, showing each for
    | `rotate_seconds`. An item without a URL is skipped: a call to action
    | pointing nowhere is worse than none. When the UTM short-link builder
    | lands, a follow-up swaps these URLs for tracked links.
    |
    | `display_url` is what viewers read on stream. Leave it null to show the
    | URL without its scheme.
    |
    */

    'cta' => [
        'rotate_seconds' => (int) env('ARE_CTA_ROTATE_SECONDS', 15),

        'items' => [
            [
                'key' => 'orkestera',
                'eyebrow' => 'Orkestera',
                'headline' => 'Ship software with governed AI agents.',
                'body' => 'The agentic development suite: proactive, rigorous, and reviewed by humans.',
                'url' => env('ARE_CTA_ORKESTERA_URL'),
                'display_url' => env('ARE_CTA_ORKESTERA_DISPLAY_URL'),
                'accent' => '#7c5cff',
            ],
            [
                'key' => 'edos-professional-services',
                'eyebrow' => 'EDOS Professional Services',
                'headline' => 'Want agents shipping in your org?',
                'body' => 'We plan, build and govern agentic workflows with your team.',
                'url' => env('ARE_CTA_EDOS_URL'),
                'display_url' => env('ARE_CTA_EDOS_DISPLAY_URL'),
                'accent' => '#2dd4bf',
            ],
        ],
    ],

];
