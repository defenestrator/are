<?php

use App\Enums\Overlay;
use App\Twitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
 * docs/runbook.md (#156) is what an operator copies from during a show. A
 * wrong command there is worse than none, so every artisan command and
 * option, environment variable, route and file it names must exist.
 */

function runbook(): string
{
    return file_get_contents(base_path('docs/runbook.md'));
}

/**
 * Every `...` span in the runbook, code blocks included.
 *
 * @return list<string>
 */
function runbookSpans(): array
{
    preg_match_all('/`([^`\n]+)`/', runbook(), $m);

    return $m[1];
}

test('every artisan command and option in the runbook exists', function () {
    preg_match_all('/(?:php(?:8\.3)?|\$FORGE_PHP) artisan ([a-z][a-z0-9:-]*)([^`\n|]*)/', runbook(), $m, PREG_SET_ORDER);
    $commands = Artisan::all();

    expect($m)->not->toBeEmpty();

    foreach ($m as [, $name, $rest]) {
        expect($commands)->toHaveKey($name, message: "php artisan {$name} is in the runbook but is not a command");

        preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $rest, $options);
        foreach ($options[1] as $option) {
            expect($commands[$name]->getDefinition()->hasOption($option))
                ->toBeTrue("php artisan {$name} has no --{$option} option, but the runbook uses it");
        }
    }
});

test('every environment variable in the runbook is read by the app', function () {
    preg_match_all('/env\(\'([A-Z0-9_]+)\'/', implode("\n", array_map('file_get_contents', glob(config_path('*.php')))), $config);
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', file_get_contents(base_path('.env.example')), $example);
    $known = array_flip([...$config[1], ...$example[1]]);

    $named = collect(runbookSpans())
        ->map(fn (string $span) => preg_match('/^\{?([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\}?(?:=.*)?$/', $span, $m) ? $m[1] : null)
        ->filter()
        ->unique();

    expect($named->count())->toBeGreaterThan(50);

    foreach ($named as $variable) {
        expect($known)->toHaveKey($variable, message: "{$variable} is in the runbook but nothing reads it");
    }
});

test('every app path in the runbook is a route', function () {
    // A route parameter matches one segment (or part of one, as in
    // {variant}.mp4); an optional parameter may be absent.
    $patterns = collect(Route::getRoutes()->getRoutes())->map(function ($route) {
        $regex = '';
        foreach (array_filter(explode('/', $route->uri()), 'strlen') as $segment) {
            $regex .= str_ends_with($segment, '?}')
                ? '(?:/[^/]+)?'
                : '/'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($segment, '#'));
        }

        return '#^'.($regex === '' ? '/' : $regex).'$#';
    });

    $paths = collect(runbookSpans())
        ->filter(fn (string $span) => preg_match('#^/[a-z]#', $span) && ! str_starts_with($span, '/home/'))
        ->map(fn (string $span) => preg_replace('/\s.*$/', '', $span))
        ->unique();

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect($patterns->contains(fn (string $pattern) => preg_match($pattern, $path) === 1))
            ->toBeTrue("{$path} is in the runbook but is not a route");
    }
});

test('every repository file the runbook links or names exists', function () {
    preg_match_all('/\]\(\.\.\/([^)#]+)\)/', runbook(), $links);
    $named = collect(runbookSpans())
        ->filter(fn (string $span) => preg_match('#^(app|config|scripts|tests|docs|resources|routes)/[\w./-]+\.\w+$#', $span) || in_array($span, ['.env.example'], true));

    foreach ([...$links[1], ...$named] as $file) {
        expect(file_exists(base_path($file)))->toBeTrue("{$file} is named in the runbook but does not exist");
    }
});

test('the runbook lists every scope a broadcaster must grant and every overlay', function () {
    foreach (Twitch::BROADCASTER_SCOPES as $scope) {
        expect(runbook())->toContain("`{$scope}`");
    }

    foreach (Overlay::values() as $overlay) {
        expect(runbook())->toContain("`{$overlay}`");
    }
});
