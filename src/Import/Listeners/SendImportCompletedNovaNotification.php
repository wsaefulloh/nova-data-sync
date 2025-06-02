<?php

namespace Wsaefulloh\NovaDataSync\Import\Listeners;

use Wsaefulloh\NovaDataSync\Import\Events\ImportCompletedEvent;
use Laravel\Nova\Notifications\NovaNotification;

class SendImportCompletedNovaNotification
{
    public function handle(ImportCompletedEvent $event): void
    {
        if (!$event->import->user) {
            return;
        }

        $event->import->user->notify(
            NovaNotification::make()
                ->message('Your import has completed. Processor: ' . $event->import->processor_short_name)
                ->url('/resources/imports/' . $event->import->id)
                ->icon('check-circle')
                ->type('success')
        );
    }
}