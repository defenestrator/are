<?php

use App\Events\LeadCaptured;
use App\Models\Lead;
use App\Models\ShortLink;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\LeadSubmitted;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

beforeEach(function () {
    RateLimiter::clear('lead-form:'.sha1('127.0.0.1'));
    config(['are.leads.notify' => ['leads@edos.example'], 'are.leads.webhook_url' => null]);
});

function submitLead(): void
{
    Volt::test('lead-form')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('message', 'We want help running agentic workflows in production.')
        ->set('consent', true)
        ->call('submit')
        ->assertHasNoErrors();
}

test('submitting the form notifies the configured address with the lead', function () {
    Notification::fake();

    $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', '2026-10-04-orkestera-live', 'overlay');
    $this->keepCookies($this->get($link->url()));

    submitLead();

    $lead = Lead::sole();

    Notification::assertSentOnDemand(LeadSubmitted::class, function (LeadSubmitted $notification, array $channels, AnonymousNotifiable $notifiable) use ($lead) {
        $mail = $notification->toMail($notifiable);

        return $notification->lead->is($lead)
            && $channels === ['mail']
            && $notifiable->routes['mail'] === ['leads@edos.example']
            && $mail->replyTo === [['ada@example.com', 'Ada Lovelace']]
            && in_array('Came from: twitch / stream / 2026-10-04-orkestera-live / overlay', $mail->introLines, true)
            && in_array('We want help running agentic workflows in production.', $mail->introLines, true);
    });
});

test('the notification is queued and encrypted, so lead PII is unreadable in the queue payload', function () {
    config(['queue.default' => 'database']);

    $lead = Lead::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    (new AnonymousNotifiable)->route('mail', 'leads@edos.example')->notify(new LeadSubmitted($lead));

    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    $command = $payload['data']['command'];

    expect(new LeadSubmitted($lead))->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($payload['displayName'])->toBe(LeadSubmitted::class)
        ->and($command)->not->toStartWith('O:')
        ->and($command)->not->toContain('SendQueuedNotifications')
        ->and(unserialize(decrypt($command)))->toBeInstanceOf(SendQueuedNotifications::class);
});

test('the optional webhook gets a message with attribution but no personal data', function () {
    config(['are.leads.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX']);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

    $lead = Lead::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Secret project details',
        'utm_source' => 'twitch',
        'utm_campaign' => 'ep-1',
    ]);

    LeadCaptured::dispatch($lead);

    Http::assertSent(function ($request) {
        $body = $request->body();

        return $request->url() === 'https://hooks.slack.com/services/T000/B000/XXXX'
            && str_contains($request['text'], 'twitch / ep-1')
            && str_contains($request['text'], route('leads.index'))
            && ! str_contains($body, 'Ada')
            && ! str_contains($body, 'ada@example.com')
            && ! str_contains($body, 'Secret');
    });
});

test('Discord webhooks get a "content" body', function () {
    $lead = Lead::factory()->create();

    expect((new LeadSubmitted($lead))->toWebhook(new AnonymousNotifiable, 'https://discord.com/api/webhooks/1/abc'))
        ->toHaveKey('content')
        ->not->toHaveKey('text');
});

test('mail and webhook are routed independently', function () {
    Notification::fake();
    config(['are.leads.notify' => [], 'are.leads.webhook_url' => 'https://hooks.slack.com/services/T/B/X']);

    LeadCaptured::dispatch(Lead::factory()->create());

    Notification::assertSentOnDemand(LeadSubmitted::class, fn ($n, array $channels) => $channels === [WebhookChannel::class]);
});

test('with nothing configured, no notification is sent and a warning without PII is logged', function () {
    Notification::fake();
    Log::spy();
    config(['are.leads.notify' => [], 'are.leads.webhook_url' => null]);

    $lead = Lead::factory()->create(['email' => 'ada@example.com']);
    LeadCaptured::dispatch($lead);

    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context === ['lead_id' => $lead->id]
        && ! str_contains($message, 'ada@example.com'));
});

test('ARE_LEADS_NOTIFY accepts a comma-separated list', function () {
    // env() reads $_ENV/$_SERVER (and getenv only when the putenv adapter is on), so set all three.
    $value = ' a@edos.example , b@edos.example,';
    putenv("ARE_LEADS_NOTIFY={$value}");
    $_ENV['ARE_LEADS_NOTIFY'] = $_SERVER['ARE_LEADS_NOTIFY'] = $value;

    try {
        $config = require config_path('are.php');
    } finally {
        putenv('ARE_LEADS_NOTIFY');
        unset($_ENV['ARE_LEADS_NOTIFY'], $_SERVER['ARE_LEADS_NOTIFY']);
    }

    expect($config['leads']['notify'])->toBe(['a@edos.example', 'b@edos.example']);
});

test('honeypot and rejected submissions notify nobody', function () {
    Notification::fake();

    Volt::test('lead-form')->set('website', 'http://spam.example')->call('submit');
    Volt::test('lead-form')->set('name', 'x')->call('submit')->assertHasErrors();

    Notification::assertNothingSent();
});
