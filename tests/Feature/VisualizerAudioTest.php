<?php

use App\Enums\Overlay;
use App\Models\OverlayToken;
use App\Support\VisualizerAudio;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $query
 */
function visualizerOverlay(array $query = []): TestResponse
{
    $token = OverlayToken::issue(Overlay::Visualizer);

    return test()->get(route('overlay.show', ['overlay' => 'visualizer', 'token' => $token, ...$query]));
}

function audioConfig(TestResponse $response): array
{
    $response->assertOk();
    preg_match('/<div id="visualizer-config"(.*?)><\/div>/s', $response->getContent(), $match);
    expect($match)->not->toBeEmpty();
    preg_match_all('/data-([a-z-]+)="([^"]*)"/', $match[1], $pairs, PREG_SET_ORDER);

    return collect($pairs)->mapWithKeys(fn ($pair) => [$pair[1] => html_entity_decode($pair[2], ENT_QUOTES)])->all();
}

test('the overlay listens to nothing by default and orbits instead of following the mouse', function () {
    expect(audioConfig(visualizerOverlay()))->toBe([
        'audio-mode' => 'none',
        'audio-device' => '',
        'audio-gain' => '1',
        'audio-audible' => 'false',
        'motion' => 'orbit',
    ]);
});

test('the overlay listens to the default capture device', function () {
    expect(audioConfig(visualizerOverlay(['audio' => 'default'])))
        ->toMatchArray(['audio-mode' => 'device', 'audio-device' => '']);
});

test('the overlay listens to a capture device chosen by label', function () {
    expect(audioConfig(visualizerOverlay(['audio' => '  BlackHole 2ch  '])))
        ->toMatchArray(['audio-mode' => 'device', 'audio-device' => 'BlackHole 2ch']);
});

test('the bundled track is never audible on the overlay', function () {
    expect(audioConfig(visualizerOverlay(['audio' => 'file'])))
        ->toMatchArray(['audio-mode' => 'file', 'audio-audible' => 'false']);
});

test('/visualizer keeps the audible click-to-play demo track by default', function () {
    expect(audioConfig($this->get('/visualizer')))->toBe([
        'audio-mode' => 'file',
        'audio-device' => '',
        'audio-gain' => '1',
        'audio-audible' => 'true',
        'motion' => 'mouse',
    ]);
});

test('/visualizer can listen to a capture device too', function () {
    expect(audioConfig($this->get('/visualizer?audio=default')))
        ->toMatchArray(['audio-mode' => 'device', 'audio-audible' => 'false']);
});

test('audio keywords are case-insensitive', function (string $value, string $mode) {
    expect(audioConfig(visualizerOverlay(['audio' => $value]))['audio-mode'])->toBe($mode);
})->with([
    ['DEFAULT', 'device'],
    ['Off', 'none'],
    ['NONE', 'none'],
    ['File', 'file'],
]);

test('a device label from the URL is escaped and length-limited', function () {
    $response = visualizerOverlay(['audio' => '"><script>alert(1)</script>']);

    $response->assertDontSee('<script>alert(1)</script>', false);
    expect(audioConfig($response)['audio-device'])->toBe('"><script>alert(1)</script>');

    $long = str_repeat('x', 500);
    expect(mb_strlen(audioConfig(visualizerOverlay(['audio' => $long]))['audio-device']))
        ->toBe(VisualizerAudio::MAX_DEVICE_LENGTH);
});

test('a non-string audio parameter falls back to the default for the page', function () {
    expect(audioConfig(visualizerOverlay(['audio' => ['x']]))['audio-mode'])->toBe('none');
});

test('gain is clamped to 0.1 through 10', function (string $gain, string $expected) {
    expect(audioConfig(visualizerOverlay(['audio' => 'default', 'gain' => $gain]))['audio-gain'])->toBe($expected);
})->with([
    ['2.5', '2.5'],
    ['0', '0.1'],
    ['-4', '0.1'],
    ['50', '10'],
    ['loud', '1'],
]);
