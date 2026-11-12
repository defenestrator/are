<?php

use App\YouTube\Quota;

// Google's quota table, https://developers.google.com/youtube/v3/determine_quota_cost
// (#211). ARE's readiness page, youtube:quota, the 80% alert and the reply
// reservation all trust these numbers, so a wrong one hides real spend.
test('every YouTube method is charged what Google charges', function (string $method, string $bucket, int $cost) {
    expect(Quota::COSTS[$method])->toBe([$bucket, $cost]);
})->with([
    'liveChatMessages.list' => ['liveChatMessages.list', Quota::UNITS, 5],
    'liveChatMessages.insert' => ['liveChatMessages.insert', Quota::UNITS, 20],
    'videos.list' => ['videos.list', Quota::UNITS, 1],
    'liveBroadcasts.list' => ['liveBroadcasts.list', Quota::UNITS, 1],
    'channels.list' => ['channels.list', Quota::UNITS, 1],
    'search.list' => ['search.list', Quota::SEARCH, 1],
]);

test('no YouTube method is charged without a pinned cost', function () {
    expect(array_keys(Quota::COSTS))->toEqualCanonicalizing([
        'liveChatMessages.list',
        'liveChatMessages.insert',
        'videos.list',
        'liveBroadcasts.list',
        'channels.list',
        'search.list',
    ]);
});
