<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\Span;

/**
 * Removes `token` query parameters from everything ARE sends to Sentry.
 *
 * OBS overlay URLs carry their access token as `?token=` (browser sources
 * cannot send headers). Sentry's RequestIntegration attaches the full request
 * URL and query string to every event and transaction whatever
 * `send_default_pii` says, so without this a single exception on
 * /overlay/* would store a live token in Sentry.
 *
 * Wired up in config/sentry.php as before_send, before_send_transaction and
 * before_breadcrumb. The callables are [class, method] arrays rather than
 * closures, so `php artisan config:cache` keeps working.
 */
final class SentryScrubber
{
    public const FILTERED = '[Filtered]';

    /**
     * `token=` at the start of a query string or after `?`, `&` or `;` (which
     * covers `&amp;`), plus its URL-encoded form inside another URL.
     */
    private const PATTERNS = [
        '/(^|[?&;])(token=)[^&#\s"\'<>]*/i' => '$1$2'.self::FILTERED,
        '/(%3F|%26)(token%3D)[^&#\s"\'<>%]*/i' => '$1$2'.self::FILTERED,
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $event->setRequest(self::scrub($event->getRequest()));

        if ($event->getTransaction() !== null) {
            $event->setTransaction(self::scrubString($event->getTransaction()));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(
                self::scrubString($event->getMessage()),
                self::scrub($event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : self::scrubString($event->getMessageFormatted()),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubString($exception->getValue()));
        }

        $event->setExtra(self::scrub($event->getExtra()));
        $event->setTags(self::scrub($event->getTags()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::scrub($context));
        }

        $event->setBreadcrumb(array_map(self::beforeBreadcrumb(...), $event->getBreadcrumbs()));

        foreach ($event->getSpans() as $span) {
            self::scrubSpan($span);
        }

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrubString($breadcrumb->getMessage()));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, self::scrub($value));
        }

        return $breadcrumb;
    }

    public static function scrubString(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrubString($value);
        }

        if (is_array($value)) {
            return array_map(self::scrub(...), $value);
        }

        return $value;
    }

    private static function scrubSpan(Span $span): void
    {
        if ($span->getDescription() !== null) {
            $span->setDescription(self::scrubString($span->getDescription()));
        }

        $span->setData(self::scrub($span->getData()));
    }
}
