<?php

namespace App\Providers;

use App\Chat\ChatCommandRegistry;
use App\Chat\Commands\ClipMarker;
use App\Chat\Commands\DoAction;
use App\Chat\Commands\EdosLink;
use App\Chat\Commands\LinkAccount;
use App\Chat\Commands\NowPlaying;
use App\Chat\Commands\OrkesteraLink;
use App\Chat\Commands\RequestSong;
use App\Chat\Commands\SubmitQuestion;
use App\Chat\Commands\VoteOnQuestion;
use Illuminate\Support\ServiceProvider;

/**
 * Registers chat commands. To add one, implement App\Chat\ChatCommand and add
 * the class to the 'chat.commands' tag below (or tag it from your own provider).
 */
class ChatCommandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            SubmitQuestion::class,
            VoteOnQuestion::class,
            OrkesteraLink::class,
            EdosLink::class,
            LinkAccount::class,
            RequestSong::class,
            ClipMarker::class,
            DoAction::class,
            NowPlaying::class,
        ], 'chat.commands');

        $this->app->singleton(ChatCommandRegistry::class, fn ($app) => new ChatCommandRegistry($app->tagged('chat.commands')));
    }
}
