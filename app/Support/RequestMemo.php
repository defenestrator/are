<?php

namespace App\Support;

use Closure;

/**
 * Values memoised for the length of one web request or one queued job
 * (#173): ban standing per user, the current topic. A /vote render asked the
 * same questions once per card, gate and component, and a chat job once per
 * step of !q or !vote.
 *
 * It is on only inside a scope: EnableRequestMemo opens one per web request,
 * and the queue's before/after events one per job (AppServiceProvider).
 * Scopes nest (a sync job inside a request), and the values are dropped when
 * the outermost one closes, so nothing outlives its request or job, even on a
 * long-running Horizon worker. Console commands and code that calls the
 * models directly outside a scope always read fresh. Anything that changes a
 * memoised fact calls forget() or flush(); see the callers.
 */
class RequestMemo
{
    /** How many scopes are open. */
    private int $depth = 0;

    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * Open a scope: a web request or a queued job starts.
     */
    public function enable(): void
    {
        $this->depth++;
    }

    /**
     * Close a scope. Closing the outermost one drops every value.
     */
    public function release(): void
    {
        $this->depth = max(0, $this->depth - 1);

        if ($this->depth === 0) {
            $this->values = [];
        }
    }

    /**
     * Close every scope and drop everything.
     */
    public function reset(): void
    {
        $this->depth = 0;
        $this->values = [];
    }

    public function enabled(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if ($this->depth === 0) {
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
