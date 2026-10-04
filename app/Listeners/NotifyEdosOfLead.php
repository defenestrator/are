<?php

namespace App\Listeners;

use App\Events\LeadCaptured;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\LeadSubmitted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;

/**
 * Sends LeadSubmitted to the configured address and webhook. The
 * notification is queued and encrypted, so this listener only routes it.
 */
class NotifyEdosOfLead
{
    public function handle(LeadCaptured $event): void
    {
        $addresses = array_values(array_filter((array) config('are.leads.notify', []), 'is_string'));
        $webhook = config('are.leads.webhook_url');

        $notifiable = new AnonymousNotifiable;

        if ($addresses !== []) {
            $notifiable->route('mail', $addresses);
        }

        if (is_string($webhook) && $webhook !== '') {
            $notifiable->route(WebhookChannel::class, $webhook);
        }

        if ($notifiable->routes === []) {
            // Never log the lead's details, only that one is waiting.
            Log::warning('A lead was stored but nobody was notified: set ARE_LEADS_NOTIFY and/or ARE_LEADS_WEBHOOK_URL.', [
                'lead_id' => $event->lead->id,
            ]);

            return;
        }

        $notifiable->notify(new LeadSubmitted($event->lead));
    }
}
