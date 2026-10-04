<?php

namespace App\Chat\Commands;

/**
 * !edos: reply with a tracked link to the EDOS Professional Services enquiry form.
 */
class EdosLink extends TrackedLinkCommand
{
    protected function key(): string
    {
        return 'edos';
    }
}
