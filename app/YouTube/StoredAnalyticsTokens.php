<?php

namespace App\YouTube;

use App\Models\YouTubeChannelToken;

/**
 * AnalyticsTokens over the channel owners' tokens stored by the broadcaster
 * Google connection (/youtube/broadcaster/connect, #126). Only channels
 * whose stored token carries both analytics scopes are listed: reports.query
 * needs yt-analytics.readonly and youtube.readonly.
 */
final class StoredAnalyticsTokens implements AnalyticsTokens
{
    public function channels(): array
    {
        // Sorted here, not in SQL: SQLite and Postgres order a null title differently.
        return YouTubeChannelToken::query()
            ->get()
            ->filter(fn (YouTubeChannelToken $token) => array_diff(YouTubeApi::ANALYTICS_SCOPES, $token->scopes) === [])
            ->mapWithKeys(fn (YouTubeChannelToken $token) => [$token->channel_id => $token->channel_title ?: $token->channel_id])
            ->sort()
            ->all();
    }

    public function accessTokenFor(string $channelId): string
    {
        return YouTubeApi::accessTokenFor($channelId);
    }
}
