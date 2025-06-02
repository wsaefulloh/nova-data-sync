<?php

namespace Wsaefulloh\NovaDataSync\Import\Listeners;

use Wsaefulloh\NovaDataSync\Import\Events\ImportStartedEvent;
use Laravel\Nova\Notifications\NovaNotification;

class SendImportStartedNovaNotification
{
    public function handle(ImportStartedEvent $event): void
    {
        if (!$event->import->user) {
            return;
        }

        $event->import->user->notify(
            NovaNotification::make()
                ->message('Your import has started. Processor: ' . $event->import->processor_short_name)
                ->url('/resources/imports/' . $event->import->id)
                ->icon('upload')
                ->type('info')
        );
    }
}
