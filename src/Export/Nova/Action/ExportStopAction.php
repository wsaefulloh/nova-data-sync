<?php

namespace Wsaefulloh\NovaDataSync\Export\Nova\Action;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Export\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Support\Facades\DB;

class ExportStopAction extends Action
{
    public $onlyOnDetail = true;

    public function name(): string
    {
        return 'Stop Export';
    }

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        /** @var Export $export */
        $export = $models->first();

        $batch = Bus::findBatch($export->batch_id);


        if ($batch) {
            $batch->cancel();
            $message = "Stopped batch {$export->batch_id}";
            $export->update([
                    'status' => Status::STOPPED->value,
            ]);
            Log::info("Stopped batch {$export->batch_id}", []);
            $shell_run = shell_exec(base_path('kill-job.sh'));
        } else {
            $success = false;
            $message = "Batch {$export->batch_id} not found";
            Log::warning("Batch not found", []);
        }

        return Action::message('Attempt to stop the export started..');
    }
}
