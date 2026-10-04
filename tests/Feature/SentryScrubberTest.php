<?php

use App\Support\SentryScrubber;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventId;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

const LIVE_TOKEN = '6f1c0e2a9b8d7c6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e';

/**
 * A Sentry hub built from this app's config/sentry.php callbacks, with the
 * SDK's real RequestIntegration reading the given request, and a transport
 * that keeps what would have been sent.
 *
 * @return array{HubInterface, ArrayObject<int, Event>}
 */
function sentryHubFor(ServerRequestInterface $request): array
{
    $sent = new ArrayObject;

    $transport = new class($sent) implements TransportInterface
    {
        public function __construct(private ArrayObject $sent) {}

        public function send(Event $event): Result
        {
            $this->sent->append($event);

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    $fetcher = new class($request) implements RequestFetcherInterface
    {
        public function __construct(private ServerRequestInterface $request) {}

        public function fetchRequest(): ?ServerRequestInterface
        {
            return $this->request;
        }
    };

    $client = ClientBuilder::create([
        'dsn' => 'https://public@sentry.example/1',
        'default_integrations' => false,
        'integrations' => [new RequestIntegration($fetcher)],
        'traces_sample_rate' => 1.0,
        'before_send' => config('sentry.before_send'),
        'before_send_transaction' => config('sentry.before_send_transaction'),
        'before_breadcrumb' => config('sentry.before_breadcrumb'),
    ])->setTransport($transport)->getClient();

    // The SDK installs its request processor once per process, so whichever
    // RequestIntegration came first does the fetching. In this app that is
    // sentry-laravel's, which reads the PSR-7 request its middleware binds here.
    app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

    $hub = new Hub($client);
    SentrySdk::setCurrentHub($hub);

    return [$hub, $sent];
}

afterEach(fn () => SentrySdk::setCurrentHub(new Hub));

function overlayRequest(): ServerRequestInterface
{
    return new ServerRequest(
        'GET',
        'https://are.example/overlay/queue?layout=vertical&token='.LIVE_TOKEN,
        ['Referer' => 'https://are.example/overlay/vote?token='.LIVE_TOKEN],
    );
}

test('config/sentry.php wires the scrubber with cacheable callables', function () {
    foreach (['before_send', 'before_send_transaction', 'before_breadcrumb'] as $option) {
        expect(config("sentry.{$option}"))->toBeArray()->toBeCallable();
    }

    // config:cache writes the config out with var_export; closures would not survive.
    expect(fn () => var_export(config('sentry'), true))->not->toThrow(Throwable::class);
});

test('an error on an overlay page reaches Sentry without its token', function () {
    [$hub, $sent] = sentryHubFor(overlayRequest());

    $hub->addBreadcrumb(new Breadcrumb('info', 'http', 'navigation', 'GET /overlay/queue?token='.LIVE_TOKEN, [
        'url' => 'https://are.example/overlay/queue?layout=vertical&token='.LIVE_TOKEN,
    ]));
    $hub->captureException(new RuntimeException('Failed rendering /overlay/queue?token='.LIVE_TOKEN));

    expect($sent)->toHaveCount(1);
    $event = $sent[0];
    $request = $event->getRequest();

    // The SDK attached the URL and query string: this is the leak being fixed.
    expect($request['url'])->toBe('https://are.example/overlay/queue?layout=vertical&token=[Filtered]')
        ->and($request['query_string'])->toBe('layout=vertical&token=[Filtered]')
        ->and($event->getExceptions()[0]->getValue())->toBe('Failed rendering /overlay/queue?token=[Filtered]')
        ->and($event->getBreadcrumbs()[0]->getMessage())->toBe('GET /overlay/queue?token=[Filtered]')
        ->and($event->getBreadcrumbs()[0]->getMetadata()['url'])->toEndWith('layout=vertical&token=[Filtered]')
        ->and(serialize($event))->not->toContain(LIVE_TOKEN);
});

test('a performance transaction for an overlay page reaches Sentry without its token', function () {
    [$hub, $sent] = sentryHubFor(overlayRequest());

    $span = new Span;
    $span->setDescription('GET https://are.example/overlay/queue?token='.LIVE_TOKEN);
    $span->setData(['url' => '/overlay/queue?token='.LIVE_TOKEN]);

    $transaction = Event::createTransaction(EventId::generate())
        ->setTransaction('GET /overlay/queue?token='.LIVE_TOKEN)
        ->setSpans([$span]);

    $hub->captureEvent($transaction);

    expect($sent)->toHaveCount(1);
    $event = $sent[0];

    expect($event->getRequest()['query_string'])->toBe('layout=vertical&token=[Filtered]')
        ->and($event->getTransaction())->toBe('GET /overlay/queue?token=[Filtered]')
        ->and($event->getSpans()[0]->getDescription())->toBe('GET https://are.example/overlay/queue?token=[Filtered]')
        ->and($event->getSpans()[0]->getData('url'))->toBe('/overlay/queue?token=[Filtered]')
        ->and(serialize($event))->not->toContain(LIVE_TOKEN);
});

test('only the token parameter is filtered', function (string $input, string $expected) {
    expect(SentryScrubber::scrubString($input))->toBe($expected);
})->with([
    'first parameter' => ['/overlay/cta?token=abc&layout=vertical', '/overlay/cta?token=[Filtered]&layout=vertical'],
    'bare query string' => ['token=abc', 'token=[Filtered]'],
    'html-escaped ampersand' => ['?layout=vertical&amp;token=abc', '?layout=vertical&amp;token=[Filtered]'],
    'case-insensitive' => ['?TOKEN=abc', '?TOKEN=[Filtered]'],
    'url-encoded inside another url' => ['/login?next=%2Foverlay%2Fvote%3Ftoken%3Dabc', '/login?next=%2Foverlay%2Fvote%3Ftoken%3D[Filtered]'],
    'other parameters untouched' => ['/vote?page=2&csrf_token=keep&tokens=keep', '/vote?page=2&csrf_token=keep&tokens=keep'],
    'no query' => ['/overlay/queue', '/overlay/queue'],
]);
