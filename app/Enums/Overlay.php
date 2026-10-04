<?php

namespace App\Enums;

/**
 * An OBS browser source served under /overlay/{value}. Each one has its own
 * access token, so rotating one overlay's URL leaves the others working.
 */
enum Overlay: string
{
    case Queue = 'queue';
    case Vote = 'vote';
    case NowPlaying = 'now-playing';
    case Captions = 'captions';
    case Visualizer = 'visualizer';
    case TopVote = 'top-vote';
    case Cta = 'cta';

    public function view(): string
    {
        return 'overlays.'.$this->value;
    }

    public function title(): string
    {
        return match ($this) {
            self::Queue => 'Question queue',
            self::Vote => 'Vote leaderboard',
            self::NowPlaying => 'Now playing',
            self::Captions => 'Captions',
            self::Visualizer => 'Visualizer',
            self::TopVote => 'Top vote',
            self::Cta => 'Call to action',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
