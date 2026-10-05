<?php

use App\Models\BroadcasterToken;
use App\Models\User;
use App\Models\YouTubeChannelToken;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Settings → Channel connection (#194): where a broadcaster connects their
 * Twitch channel, and the show's YouTube channels, so ARE can moderate, read
 * chat and post replies. The scope check is the one /admin/readiness uses
 * (BroadcasterToken::missingScopes, YouTubeChannelToken::missingScopes).
 */
new class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()?->isBroadcaster(), 403);
    }

    public function with(): array
    {
        $user = Auth::user();

        $channels = array_values(array_filter(User::getBroadcasterIDs(), fn (string $id) => $user->isBroadcasterOf($id)));
        $tokens = BroadcasterToken::whereIn('broadcaster_id', $channels)->get()->keyBy('broadcaster_id');

        $youtubeIds = (array) config('services.youtube.channel_ids');
        $youtubeTokens = $youtubeIds === []
            ? collect()
            : YouTubeChannelToken::whereIn('channel_id', $youtubeIds)->get()->keyBy('channel_id');

        return [
            'twitch' => array_map(fn (string $id) => [
                'id' => $id,
                'token' => $tokens->get($id),
                'missing' => $tokens->get($id)?->missingScopes() ?? [],
            ], $channels),
            'youtube' => array_map(fn (string $id) => [
                'id' => $id,
                'token' => $youtubeTokens->get($id),
                'missing' => array_map(
                    fn (string $scope) => str_replace('https://www.googleapis.com/auth/', '', $scope),
                    $youtubeTokens->get($id)?->missingScopes() ?? [],
                ),
            ], $youtubeIds),
        ];
    }
}; ?>

<section class="mt-10 space-y-4" data-channel-connection>
    <div>
        <flux:heading>{{ __('Channel connection') }}</flux:heading>
        <flux:subheading>{{ __('Let ARE moderate, read chat, post replies and set the title on your channels.') }}</flux:subheading>
    </div>

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($twitch as $channel)
            <li class="py-3 flex flex-wrap items-center gap-3" wire:key="twitch-{{ $channel['id'] }}" data-twitch-channel="{{ $channel['id'] }}"
                data-state="{{ $channel['token'] === null ? 'not-connected' : ($channel['missing'] ? 'missing-scopes' : 'connected') }}">
                <div class="flex-1 min-w-0 space-y-1">
                    <div class="font-medium">Twitch channel {{ $channel['id'] }}</div>
                    @if ($channel['token'] === null)
                        <flux:badge color="red" size="sm">{{ __('Not connected') }}</flux:badge>
                    @elseif ($channel['missing'])
                        <flux:badge color="amber" size="sm">{{ __('Reconnect needed') }}</flux:badge>
                        <flux:text class="text-sm">{{ __('Newer features need permissions this connection was not given:') }}</flux:text>
                        <ul class="text-xs font-mono text-zinc-500 list-disc pl-5">
                            @foreach ($channel['missing'] as $scope)
                                <li>{{ $scope }}</li>
                            @endforeach
                        </ul>
                    @else
                        <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
                    @endif
                </div>
                <flux:button size="sm" :variant="$channel['token'] === null || $channel['missing'] ? 'primary' : 'ghost'" href="{{ route('twitch.broadcaster.connect') }}">
                    {{ $channel['token'] === null ? __('Connect') : __('Reconnect') }}
                </flux:button>
            </li>
        @endforeach

        @foreach ($youtube as $channel)
            <li class="py-3 flex flex-wrap items-center gap-3" wire:key="youtube-{{ $channel['id'] }}" data-youtube-channel="{{ $channel['id'] }}"
                data-state="{{ $channel['token'] === null ? 'not-connected' : ($channel['missing'] ? 'missing-scopes' : 'connected') }}">
                <div class="flex-1 min-w-0 space-y-1">
                    <div class="font-medium">YouTube channel {{ $channel['token']?->channel_title ? $channel['token']->channel_title.' ('.$channel['id'].')' : $channel['id'] }}</div>
                    @if ($channel['token'] === null)
                        <flux:badge color="red" size="sm">{{ __('Not connected') }}</flux:badge>
                    @elseif ($channel['missing'])
                        <flux:badge color="amber" size="sm">{{ __('Reconnect needed') }}</flux:badge>
                        <ul class="text-xs font-mono text-zinc-500 list-disc pl-5">
                            @foreach ($channel['missing'] as $scope)
                                <li>{{ $scope }}</li>
                            @endforeach
                        </ul>
                    @else
                        <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
                    @endif
                </div>
                <flux:button size="sm" :variant="$channel['token'] === null || $channel['missing'] ? 'primary' : 'ghost'" href="{{ route('youtube.broadcaster.connect') }}">
                    {{ $channel['token'] === null ? __('Connect') : __('Reconnect') }}
                </flux:button>
            </li>
        @endforeach
    </ul>

    @if ($youtube !== [])
        <flux:text class="text-sm text-zinc-500">{{ __('For YouTube, sign in to Google with the account that owns the channel.') }}</flux:text>
    @endif
</section>
