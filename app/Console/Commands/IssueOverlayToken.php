<?php

namespace App\Console\Commands;

use App\Enums\Overlay;
use App\Enums\OverlayLayout;
use App\Models\OverlayToken;
use Illuminate\Console\Command;

class IssueOverlayToken extends Command
{
    protected $signature = 'overlay:token
        {overlay : One of: queue, vote, now-playing, captions, visualizer, top-vote, cta}
        {--rotate : Replace the existing token; the old URL stops working}';

    protected $description = 'Issue or rotate an OBS overlay token and print its URLs once';

    public function handle(): int
    {
        $overlay = Overlay::tryFrom((string) $this->argument('overlay'));

        if ($overlay === null) {
            $this->error('Unknown overlay. Choose one of: '.implode(', ', Overlay::values()).'.');

            return self::INVALID;
        }

        if (OverlayToken::currentHash($overlay) !== null && ! $this->option('rotate')) {
            $this->error("The {$overlay->value} overlay already has a token, and it cannot be shown again.");
            $this->line('Run with --rotate to issue a new one. The current URL will stop working.');

            return self::FAILURE;
        }

        $token = OverlayToken::issue($overlay);

        $this->info("New token for the {$overlay->value} overlay. It is shown only this once.");
        $this->newLine();

        foreach (OverlayLayout::cases() as $layout) {
            $this->line(sprintf('  %-10s (%dx%d)', $layout->value, $layout->width(), $layout->height()));
            // The token goes in the fragment, which browsers never send, so
            // it stays out of access logs (#58).
            $this->line('  '.route('overlay.show', ['overlay' => $overlay, 'layout' => $layout->value]).'#token='.$token);
            $this->newLine();
        }

        $this->comment('Paste the URL into an OBS browser source of that size. Treat it like a password.');

        return self::SUCCESS;
    }
}
