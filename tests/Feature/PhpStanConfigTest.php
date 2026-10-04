<?php

use Illuminate\Support\Facades\Process;

// #85: every worktree of this repo shared PHPStan's result cache in the
// system temp directory and saw each other's stale errors.
test('PHPStan keeps its result cache inside this checkout, gitignored', function () {
    $result = Process::path(base_path())
        ->timeout(120)
        ->run([PHP_BINARY, 'vendor/bin/phpstan', 'dump-parameters', '--json', '--memory-limit=1G'])
        ->throw();

    $tmpDir = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR)['tmpDir'];

    expect($tmpDir)->toBe(base_path('storage/framework/cache/phpstan'))
        ->and(Process::path(base_path())->run(['git', 'check-ignore', '-q', $tmpDir.'/resultCache.php'])->successful())->toBeTrue();
});
