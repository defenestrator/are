<?php

namespace App\Chat\Commands;

/**
 * !orkestera: reply with a tracked link to Orkestera.
 */
class OrkesteraLink extends TrackedLinkCommand
{
    protected function key(): string
    {
        return 'orkestera';
    }
}
