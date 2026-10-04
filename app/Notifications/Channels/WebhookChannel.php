<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Posts a notification's toWebhook() body as JSON to a Slack or Discord
 * incoming-webhook URL. Route it with
 * Notification::route(WebhookChannel::class, $url).
 */
class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor(self::class, $notification);

        if (! is_string($url) || $url === '' || ! method_exists($notification, 'toWebhook')) {
            return;
        }

        // A failure throws, so the queued job retries with the notification's backoff.
        Http::timeout(5)
            ->retry(2, 250, throw: false)
            ->asJson()
            ->post($url, $notification->toWebhook($notifiable, $url))
            ->throw();
    }
}
