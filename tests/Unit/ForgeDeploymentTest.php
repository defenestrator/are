<?php

use Symfony\Component\Process\Process;

test('Forge deployment captures revision before restart verification and stops on failure', function (bool $failMigration, string $composerStyle) {
    $directory = sys_get_temp_dir().'/are-deploy-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $log = $directory.'/commands';
    $executable = <<<'SH'
#!/bin/sh
printf '%s %s [%s]\n' "$(basename "$0")" "$*" "$ARE_DEPLOY_REVISION" >> "$DEPLOY_TEST_LOG"
printf '%s arguments:' "$(basename "$0")" >> "$DEPLOY_TEST_LOG"
printf ' <%s>' "$@" >> "$DEPLOY_TEST_LOG"
printf '\n' >> "$DEPLOY_TEST_LOG"
if [ "$(basename "$0")" = git ]; then printf '%040d\n' 1; fi
if [ "$DEPLOY_TEST_FAIL" = 1 ] && [ "$*" = 'artisan migrate --force' ]; then exit 9; fi
SH;
    foreach (['php-test', 'php8.3', 'composer-test', 'composer with spaces', 'npm', 'sudo', 'git'] as $binary) {
        file_put_contents($directory.'/'.$binary, $executable);
        chmod($directory.'/'.$binary, 0700);
    }
    $composerCommand = match ($composerStyle) {
        'compound' => 'php8.3 '.$directory.'/composer-test',
        'spaces' => $directory.'/composer with spaces',
        default => $directory.'/composer-test',
    };
    try {
        $process = new Process(['bash', dirname(__DIR__, 2).'/scripts/forge-deploy.sh'], null, [
            'PATH' => $directory.':'.getenv('PATH'),
            'FORGE_PHP' => $directory.'/php-test',
            'FORGE_COMPOSER' => $composerCommand,
            'FORGE_PHP_FPM' => 'php fpm test',
            'DEPLOY_TEST_LOG' => $log,
            'DEPLOY_TEST_FAIL' => $failMigration ? '1' : '0',
        ]);
        $process->run();
        $commands = file_get_contents($log);
        if ($failMigration) {
            expect($process->getExitCode())->toBe(9)
                ->and($commands)->not->toContain('artisan optimize', 'queue:verify-deployment');
        } else {
            expect($process->getExitCode())->toBe(0)
                ->and($commands)->toContain('sudo arguments: <-n> <service> <php fpm test> <reload>')
                ->and($commands)->toContain('artisan optimize [0000000000000000000000000000000000000001]')
                ->and(strpos($commands, 'artisan optimize'))->toBeLessThan(strpos($commands, 'queue:verify-deployment --restart --timeout=90'))
                ->and(strpos($commands, 'reload'))->toBeLessThan(strpos($commands, 'queue:verify-deployment'));
            if ($composerStyle === 'compound') {
                expect($commands)->toContain('php8.3 arguments: <'.$directory.'/composer-test> <install> <--no-dev> <--no-interaction> <--prefer-dist> <--optimize-autoloader>');
            }
        }
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'single executable' => [false, 'single'],
    'failed migration' => [true, 'single'],
    'Forge PHP and Composer command' => [false, 'compound'],
    'executable path with spaces' => [false, 'spaces'],
]);
