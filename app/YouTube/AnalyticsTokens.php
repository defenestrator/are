<?php

namespace App\YouTube;

/**
 * Channel owners' Google tokens for YouTube Analytics (#12).
 *
 * reports.query reads one channel per token (ids=channel==MINE), so each
 * served channel's owner must connect it with the yt-analytics.readonly and
 * youtube.readonly scopes. The app binds the adapter over the stored channel
 * tokens; tests bind a fake.
 */
interface AnalyticsTokens
{
    /**
     * Connected channels whose token carries the analytics scopes, as
     * channel id => channel title.
     *
     * @return array<string, string>
     */
    public function channels(): array;

    /** A valid access token for the channel, refreshed if needed. Throws if it cannot get one. */
    public function accessTokenFor(string $channelId): string;
}
