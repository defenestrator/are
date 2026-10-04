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
    /** Discord's longest message; Slack allows far more. */
    public const MAX_LENGTH = 2000;

    /**
     * A plain-text message body in the shape the webhook's host expects:
     * Discord webhooks take "content", Slack incoming webhooks take "text".
     * Text is cut to MAX_LENGTH so Discord does not reject it.
     *
     * @return array<string, string>
     */
    public static function body(string $url, string $text): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isDiscord = in_array($host, ['discord.com', 'discordapp.com'], true)
            || str_ends_with($host, '.discord.com');

        $text = mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1).'…' : $text;

        return $isDiscord ? ['content' => $text] : ['text' => $text];
    }

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
