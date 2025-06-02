<?php

namespace Wsaefulloh\NovaDataSync\Import\Events;

use Wsaefulloh\NovaDataSync\Import\Models\Import;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportCompletedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Import $import)
    {
        //
    }
}
