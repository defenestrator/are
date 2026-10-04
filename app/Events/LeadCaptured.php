<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor submitted a consented Professional Services enquiry on /about.
 * NotifyEdosOfLead tells EDOS; analytics (#12) can listen here too.
 */
class LeadCaptured
{
    use Dispatchable;

    public function __construct(public Lead $lead) {}
}
