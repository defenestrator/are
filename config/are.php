<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OBS overlay access (/overlay/*)
    |--------------------------------------------------------------------------
    |
    | Overlay URLs carry their token in the fragment (#token=…), which browsers
    | never send to the server. The page trades it for a grant cookie that is
    | valid for `grant_seconds` and used once (App\Support\OverlayGrant).
    |
    | `allow_query_token` keeps the old ?token= URLs working for one release.
    | Each use logs a deprecation warning. Set it to false once every OBS source
    | has a #token= URL; it is due to be removed in the release after #58.
    |
    */

    'overlays' => [
        'grant_seconds' => (int) env('ARE_OVERLAY_GRANT_SECONDS', 120),
        'allow_query_token' => (bool) env('ARE_OVERLAY_ALLOW_QUERY_TOKEN', true),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Lead notifications (#31)
    |--------------------------------------------------------------------------
    |
    | Who hears about a Professional Services enquiry from /about. `notify`
    | is one or more email addresses, comma-separated in ARE_LEADS_NOTIFY;
    | the mail carries the enquiry. `webhook_url` is an optional Slack or
    | Discord incoming webhook; its message names the source and links to
    | /leads, but carries no name, email or message. Both send from the queue.
    |
    */

    'leads' => [
        'notify' => array_values(array_filter(array_map('trim', explode(',', (string) env('ARE_LEADS_NOTIFY', ''))))),
        'webhook_url' => env('ARE_LEADS_WEBHOOK_URL'),
    ],

];
