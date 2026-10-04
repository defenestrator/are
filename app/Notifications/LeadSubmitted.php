<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells EDOS that a Professional Services enquiry arrived.
 *
 * Mail carries the enquiry, so EDOS can reply from the inbox. The optional
 * Slack or Discord webhook carries no personal data, only the attribution
 * and a link to the broadcaster-only leads list, because chat workspaces are
 * not where lead PII should live.
 *
 * It is queued and ShouldBeEncrypted, so the queue payload (and anything that
 * displays it, such as Horizon or failed_jobs) cannot be read without APP_KEY.
 */
class LeadSubmitted extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Each channel is its own queued job, so a webhook failure never resends the mail. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public Lead $lead)
    {
        $this->afterCommit();
    }

    /**
     * Send on whichever routes the operator configured.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return array_values(array_intersect(['mail', WebhookChannel::class], array_keys($notifiable->routes)));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;

        return (new MailMessage)
            ->subject('New EDOS enquiry from '.$lead->name)
            ->replyTo($lead->email, $lead->name)
            ->greeting('New Professional Services enquiry')
            ->line('From: '.$lead->name.' ('.$lead->email.')')
            ->line('Company: '.($lead->company ?? 'not given'))
            ->line('Came from: '.$this->attributionSummary())
            ->line('Consented at: '.$lead->consented_at->toIso8601String())
            ->line('Message:')
            ->line($lead->message)
            ->action('Open the leads list', route('leads.index'))
            ->line('Reply to this email to answer them directly.');
    }

    /**
     * The webhook body. It deliberately contains no name, email or message.
     *
     * @return array<string, string>
     */
    public function toWebhook(object $notifiable, string $url): array
    {
        $text = 'New EDOS Professional Services enquiry ('.$this->attributionSummary().'). Read it at '.route('leads.index');

        // Discord webhooks take "content"; Slack incoming webhooks take "text".
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isDiscord = in_array($host, ['discord.com', 'discordapp.com'], true)
            || str_ends_with($host, '.discord.com');

        return $isDiscord ? ['content' => $text] : ['text' => $text];
    }

    /** "twitch / stream / 2026-10-04-orkestera / overlay", or "no short link". */
    public function attributionSummary(): string
    {
        $parts = array_filter([
            $this->lead->utm_source,
            $this->lead->utm_medium,
            $this->lead->utm_campaign,
            $this->lead->utm_content,
        ]);

        return $parts === [] ? 'no short link' : implode(' / ', $parts);
    }
}
