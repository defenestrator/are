<?php

namespace App\View\Components\Overlay;

use App\Enums\OverlayLayout;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The rotating call-to-action lower-third, fed by config('are.cta').
 *
 * Rotation is CSS only. Every item runs the same keyframes over one full
 * cycle (items × seconds) and starts one slot later than the previous item,
 * so exactly one is visible at a time. Keyframe stops depend on the item
 * count, so they are computed here rather than written in app.css.
 */
class LowerThird extends Component
{
    private const DEFAULT_ACCENT = '#7c5cff';

    /** @var list<array{key: string, eyebrow: string, headline: string, body: string, url: string, display_url: string, accent: string}> */
    public array $items;

    public int $seconds;

    public function __construct(public OverlayLayout $layout = OverlayLayout::Horizontal)
    {
        $this->seconds = max(3, (int) config('are.cta.rotate_seconds', 15));
        $this->items = self::itemsFromConfig();
    }

    /**
     * @return list<array{key: string, eyebrow: string, headline: string, body: string, url: string, display_url: string, accent: string}>
     */
    public static function itemsFromConfig(): array
    {
        $items = [];

        foreach ((array) config('are.cta.items', []) as $index => $item) {
            $url = trim((string) ($item['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $accent = (string) ($item['accent'] ?? '');

            $items[] = [
                'key' => (string) ($item['key'] ?? 'cta-'.$index),
                'eyebrow' => (string) ($item['eyebrow'] ?? ''),
                'headline' => (string) ($item['headline'] ?? ''),
                'body' => (string) ($item['body'] ?? ''),
                'url' => $url,
                'display_url' => (string) ($item['display_url'] ?? '') ?: self::displayUrl($url),
                // Interpolated into a style attribute, so only accept a hex colour.
                'accent' => preg_match('/^#[0-9a-f]{3,8}$/i', $accent) ? $accent : self::DEFAULT_ACCENT,
            ];
        }

        return $items;
    }

    public static function displayUrl(string $url): string
    {
        return (string) preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
    }

    public function cycleSeconds(): int
    {
        return $this->seconds * count($this->items);
    }

    public function rotates(): bool
    {
        return count($this->items) > 1;
    }

    /**
     * Keyframes for one item's share of the cycle: rise in, hold, fade out,
     * then stay hidden while the other items take their turn.
     */
    public function keyframes(): string
    {
        $slot = 100 / max(1, count($this->items));
        $stop = fn (float $fraction): string => sprintf('%.3F%%', $slot * $fraction);

        return implode("\n", [
            '@keyframes overlay-cta-rotate {',
            '  0% { opacity: 0; transform: translateY(1.25rem); }',
            "  {$stop(0.05)} { opacity: 1; transform: translateY(0); }",
            "  {$stop(0.95)} { opacity: 1; transform: translateY(0); }",
            "  {$stop(1)}, 100% { opacity: 0; transform: translateY(-0.5rem); }",
            '}',
            '@keyframes overlay-cta-progress {',
            '  0% { transform: scaleX(0); }',
            "  {$stop(1)}, 100% { transform: scaleX(1); }",
            '}',
        ]);
    }

    public function render(): View
    {
        return view('components.overlay.lower-third');
    }
}
