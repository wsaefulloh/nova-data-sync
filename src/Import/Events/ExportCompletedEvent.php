<?php

namespace Wsaefulloh\NovaDataSync\Import\Events;

use Wsaefulloh\NovaDataSync\Export\Models\Export;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportCompletedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Export $export)
    {
        //
    }
}
