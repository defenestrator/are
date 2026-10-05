<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\ControlBus\ControlBus;
use App\ControlBus\Game;
use App\ControlBus\Submission;

/**
 * !do <action> drives the running chat game, as the linked user: for Chat
 * Plays Orkestera, "!do task Write the README". "!do #2" backs option 2 of
 * the open vote. The Chat Control Bus decides what happens (see ControlBus).
 *
 * Replies post as the broadcaster (#89), so they are fixed templates (#128):
 * no action, option, number or error text the chatter typed is ever echoed.
 * The only value filled in is the game's usage, which comes from config.
 */
class DoAction implements ChatCommand
{
    /**
     * Refusals that get a reply. Accepted actions and rate limits get none:
     * in a chat game the votes are the chat, so answering each would double
     * the flood and spend the channel's reply budget, and the registry never
     * answers "slow down" either.
     */
    private const TEMPLATES = [
        Submission::NO_GAME => 'No chat game is running right now.',
        Submission::INVALID => 'Try :usage.',
        Submission::NO_SUCH_OPTION => 'There is no option with that number in this vote.',
        Submission::ANARCHY_REFERENCE => 'In anarchy every action runs, so there are no options to back. Try :usage.',
        Submission::PAUSED => 'The chat game is paused.',
        Submission::KILLED => 'The chat game is stopped.',
        Submission::VETOED_OPTION => 'A moderator vetoed that option.',
        Submission::SAT_OUT => 'You backed an option a moderator vetoed, so you sit out this vote.',
    ];

    public function __construct(private ControlBus $bus) {}

    public function names(): array
    {
        return ['do'];
    }

    public function requiresUser(): bool
    {
        // One person, one vote: ballots belong to users, not platform accounts.
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $submission = $this->bus->submit(
            $invocation->user,
            $invocation->provider,
            $invocation->messageId,
            $invocation->arguments,
        );

        if ($submission->accepted()) {
            return ChatCommandResult::done();
        }

        return ChatCommandResult::rejected(self::reply($submission));
    }

    public static function reply(Submission $submission): string
    {
        $template = self::TEMPLATES[$submission->reason] ?? '';
        $usage = Game::find($submission->ballot->game)?->usage() ?? '!do';

        return str_replace(':usage', $usage, $template);
    }
}
