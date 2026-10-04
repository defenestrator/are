<?php

namespace App\Chat;

use App\Identities;
use App\IdentityProvider;
use App\Jobs\PostChatReply;
use App\Models\ChatCommandRun;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Throwable;

/**
 * Turns chat messages from any platform into chat command runs.
 *
 * Every platform's chat ingestion calls run(). Every command registers once
 * (see ChatCommandServiceProvider). The rules that apply to all commands live
 * here, so no command can skip them:
 *
 * 1. Only "!name args" messages whose name is registered are commands. Other
 *    messages return null at no cost.
 * 2. Each message runs its command at most once. Its platform message id is
 *    claimed in chat_command_runs before anything else runs; a claimed
 *    message returns null.
 * 3. The chatter is resolved to a user through App\Identities. Unknown chatters
 *    are not given a user: commands that require one answer Unlinked.
 * 4. Each person is rate-limited across all platforms and commands, keyed on
 *    the user (or, if unlinked, on the platform account).
 * 5. Banned or timed-out users can run no command.
 * 6. A ModeratorChatCommand runs only for the broadcaster or a moderator of
 *    the channel the message arrived on. A moderator of another served
 *    channel is refused (#96).
 * 7. A command that throws is reported and answers Failed. The exception is
 *    not rethrown, so the chat job is not retried and the command cannot run twice.
 */
class ChatCommandRegistry
{
    public const PREFIX = '!';

    /** @var array<string, ChatCommand> */
    private array $commands = [];

    /**
     * @param  iterable<ChatCommand>  $commands
     */
    public function __construct(iterable $commands = [])
    {
        foreach ($commands as $command) {
            $this->register($command);
        }
    }

    public function register(ChatCommand $command): void
    {
        foreach ($command->names() as $name) {
            $name = strtolower($name);

            if (isset($this->commands[$name])) {
                throw new LogicException('Chat command !'.$name.' is registered twice: by '.$this->commands[$name]::class.' and '.$command::class.'.');
            }

            $this->commands[$name] = $command;
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    /**
     * Split "!Name rest of message" into ['name', 'rest of message'], or null if
     * the text is not shaped like a command.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $text): ?array
    {
        $text = trim($text);

        if (! str_starts_with($text, self::PREFIX)) {
            return null;
        }

        $parts = preg_split('/\s+/u', substr($text, strlen(self::PREFIX)), 2);
        $name = strtolower($parts[0] ?? '');

        return $name === '' ? null : [$name, trim($parts[1] ?? '')];
    }

    /**
     * Run the command in a chat message. Returns null when the message is not a registered command.
     */
    public function run(
        IdentityProvider $provider,
        string $channelId,
        string $chatterId,
        string $chatterName,
        string $messageId,
        string $text,
    ): ?ChatCommandResult {
        $parsed = self::parse($text);
        if ($parsed === null || ! isset($this->commands[$parsed[0]]) || $chatterId === '') {
            return null;
        }

        [$name, $arguments] = $parsed;
        $command = $this->commands[$name];

        // At most once per message: a redelivery, or a retry of the chat job
        // after the command already ran, must not add a second question. The
        // claim comes first, so a retry also spends no rate-limit budget.
        if ($messageId !== '' && ! ChatCommandRun::claim($provider, $messageId, $name)) {
            return null;
        }

        $claimed = ChatCommandRun::where('provider', $provider)->where('message_id', $messageId);

        try {
            $result = $this->runClaimed($command, $provider, $name, $arguments, $channelId, $chatterId, $chatterName, $messageId);
        } catch (Throwable $e) {
            // Only the steps before the command can throw out of runClaimed
            // (the command's own exceptions are caught there), so the command
            // has not run. Release the claim, so the job's retry can run it.
            if ($messageId !== '') {
                (clone $claimed)->delete();
            }

            throw $e;
        }

        if ($messageId !== '') {
            (clone $claimed)->update(['status' => $result->status->value]);
        }

        // 7. A non-empty reply is posted back to chat by a queued job (#89),
        //    except "slow down": answering every message from someone who is
        //    flooding chat would double the flood. A message without an id
        //    cannot be claimed, so its reply could post twice; it is not sent.
        if ($messageId !== ''
            && trim($result->reply) !== ''
            && $result->status !== ChatCommandStatus::RateLimited
            && PostChatReply::supports($provider)) {
            PostChatReply::dispatch($provider, $channelId, $messageId, $result->reply, now());
        }

        return $result;
    }

    private function runClaimed(
        ChatCommand $command,
        IdentityProvider $provider,
        string $name,
        string $arguments,
        string $channelId,
        string $chatterId,
        string $chatterName,
        string $messageId,
    ): ChatCommandResult {
        $user = Identities::findUser($provider, $chatterId);

        $rateKey = 'chat-command:'.($user !== null ? 'user:'.$user->id : $provider->value.':'.$chatterId);
        if (RateLimiter::tooManyAttempts($rateKey, (int) config('chat.commands_per_minute'))) {
            return new ChatCommandResult(ChatCommandStatus::RateLimited, 'Slow down a little, '.$chatterName.'.');
        }
        RateLimiter::hit($rateKey, 60);

        if ($user === null && $command->requiresUser()) {
            return new ChatCommandResult(ChatCommandStatus::Unlinked, 'Sign in at '.config('app.url').' to use !'.$name.'.');
        }

        if ($user?->isBanned()) {
            return new ChatCommandResult(ChatCommandStatus::Banned, 'You are banned or timed out in this channel.');
        }

        $invocation = new ChatCommandInvocation(
            name: $name,
            arguments: $arguments,
            provider: $provider,
            channelId: $channelId,
            chatterId: $chatterId,
            chatterName: $chatterName,
            messageId: $messageId,
            user: $user,
        );

        if ($command instanceof ModeratorChatCommand && ! $invocation->canModerate()) {
            return ChatCommandResult::rejected('Only moderators of this channel can use !'.$name.'.');
        }

        try {
            return $command->handle($invocation);
        } catch (Throwable $e) {
            report($e);

            return new ChatCommandResult(ChatCommandStatus::Failed, 'Something went wrong running !'.$name.'.');
        }
    }
}
