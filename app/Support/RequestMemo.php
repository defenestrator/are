<?php

namespace App\Support;

use Closure;

/**
 * Values memoised for the length of one web request (#173): ban standing per
 * user, the current topic. A /vote render asked the same questions once per
 * card, gate and component.
 *
 * It is on only inside a web request (EnableRequestMemo turns it on and
 * clears it when the response is ready), so queue jobs, console commands
 * and code that calls the models directly always read fresh. Anything that
 * changes a memoised fact calls forget() or flush(); see the callers.
 */
class RequestMemo
{
    private bool $enabled = false;

    /** @var array<string, mixed> */
    private array $values = [];

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Turn the memo off and drop everything in it.
     */
    public function reset(): void
    {
        $this->enabled = false;
        $this->values = [];
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (! $this->enabled) {
            return $resolve();
        }

        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $resolve();
        }

        return $this->values[$key];
    }

    /**
     * Forget every key starting with $prefix.
     */
    public function forget(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }

    public function flush(): void
    {
        $this->values = [];
    }

    /**
     * Ban standing changed for someone: forget it for everyone. Bans reach a
     * user through any of their identities, so one change can affect several.
     */
    public static function forgetBans(): void
    {
        app(self::class)->forget('bans.');
    }

    public static function forgetTopic(): void
    {
        app(self::class)->forget('topic.');
    }
}
