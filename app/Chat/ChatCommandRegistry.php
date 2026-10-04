<?php

namespace App\Chat;

use App\Identities;
use App\IdentityProvider;
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
 * 2. The chatter is resolved to a user through App\Identities. Unknown chatters
 *    are not given a user: commands that require one answer Unlinked.
 * 3. Each person is rate-limited across all platforms and commands, keyed on
 *    the user (or, if unlinked, on the platform account).
 * 4. Banned or timed-out users can run no command.
 * 5. A command that throws is reported and answers Failed. The exception is
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

        try {
            return $command->handle(new ChatCommandInvocation(
                name: $name,
                arguments: $arguments,
                provider: $provider,
                channelId: $channelId,
                chatterId: $chatterId,
                chatterName: $chatterName,
                messageId: $messageId,
                user: $user,
            ));
        } catch (Throwable $e) {
            report($e);

            return new ChatCommandResult(ChatCommandStatus::Failed, 'Something went wrong running !'.$name.'.');
        }
    }
}
