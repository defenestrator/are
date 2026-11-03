<?php

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

test('the S3 clip disk streams encrypted files through its prefix with temporary credentials', function (bool $streamUpload) {
    $handler = new MockHandler([
        new Result(['ETag' => 'test-etag']),
        new Result(['Body' => Utils::streamFor('clip contents')]),
        new Result,
    ]);
    $disk = Storage::build(array_replace(config('filesystems.disks.s3'), [
        'key' => 'test-access-key',
        'secret' => 'test-secret',
        'token' => 'test-session-token',
        'region' => 'us-west-2',
        'bucket' => 'test-marketing-assets',
        'root' => 'are',
        'handler' => $handler,
    ]));
    $contents = $streamUpload ? Utils::streamFor('clip contents')->detach() : 'clip contents';

    try {
        expect($disk->put('clips/42/short.mp4', $contents))->toBeTrue();
    } finally {
        if (is_resource($contents)) {
            fclose($contents);
        }
    }

    $upload = $handler->getLastCommand();
    expect($upload->getName())->toBe('PutObject')
        ->and($upload['Key'])->toBe('are/clips/42/short.mp4')
        ->and($upload['ServerSideEncryption'])->toBe('AES256')
        ->and($upload['ACL'])->toBe('bucket-owner-full-control')
        ->and($handler->getLastRequest()->getHeaderLine('X-Amz-Security-Token'))->toBe('test-session-token');

    $download = $disk->readStream('clips/42/short.mp4');
    expect(stream_get_contents($download))->toBe('clip contents');
    fclose($download);
    expect($handler->getLastCommand()['Key'])->toBe('are/clips/42/short.mp4');

    expect($disk->delete('clips/42/short.mp4'))->toBeTrue()
        ->and($handler->getLastCommand()->getName())->toBe('DeleteObject')
        ->and($handler->getLastCommand()['Key'])->toBe('are/clips/42/short.mp4')
        ->and($handler->count())->toBe(0);
})->with([false, true]);

test('an empty S3 prefix preserves existing relative object keys', function () {
    $handler = new MockHandler([new Result]);
    $disk = Storage::build(array_replace(config('filesystems.disks.s3'), [
        'key' => 'test-access-key',
        'secret' => 'test-secret',
        'region' => 'us-west-2',
        'bucket' => 'test-marketing-assets',
        'root' => '',
        'handler' => $handler,
    ]));

    expect($disk->put('clips/42/source.mp4', 'clip contents'))->toBeTrue()
        ->and($handler->getLastCommand()['Key'])->toBe('clips/42/source.mp4');
});

test('S3 write failures remain visible to clip jobs', function () {
    $handler = new MockHandler([
        new AwsException('Denied', new Command('PutObject'), ['code' => 'AccessDenied', 'status_code' => 403]),
    ]);
    $disk = Storage::build(array_replace(config('filesystems.disks.s3'), [
        'key' => 'test-access-key',
        'secret' => 'test-secret',
        'region' => 'us-west-2',
        'bucket' => 'test-marketing-assets',
        'handler' => $handler,
        'retries' => 0,
    ]));

    expect($disk->put('clips/42/source.mp4', 'clip contents'))->toBeFalse();
});
