<?php

// namespace Wsaefulloh\NovaDataSync\Import\Nova\Actions;

// use Wsaefulloh\NovaDataSync\Enum\Status;
// use Wsaefulloh\NovaDataSync\Import\Models\Import;
// use Illuminate\Support\Collection;
// use Laravel\Nova\Actions\Action;
// use Laravel\Nova\Actions\ActionResponse;
// use Laravel\Nova\Fields\ActionFields;

// class ImportStopAction extends Action
// {
//     public $onlyOnDetail = true;

//     public function name(): string
//     {
//         return 'Stop Import';
//     }

//     /**
//      * Perform the action on the given models.
//      */
//     public function handle(ActionFields $fields, Collection $models): ActionResponse
//     {
//         /** @var Import $import */
//         $import = $models->first();

//         $import->update([
//             'status' => Status::STOPPING->value,
//         ]);

//         return Action::message('Attempt to stop the import started..');
//     }
// }
namespace Wsaefulloh\NovaDataSync\Import\Nova\Actions;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Import\Models\Import;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class ImportStopAction extends Action
{
    public $onlyOnDetail = true;

    public function name(): string
    {
        return 'Stop Import';
    }

    // public function handle(ActionFields $fields, Collection $models): ActionResponse
    // {
    //     /** @var Import $import */
    //     $import = $models->first();
    //     $import->update(['status' => Status::STOPPING->value]);
    //     return Action::message('Attempt to stop the import started..');
    // }
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        /** @var Export $import */
        $import = $models->first();

        $batch = Bus::findBatch($import->batch_id);


        if ($batch) {
            $batch->cancel();
            $message = "Stopped batch {$import->batch_id}";
            $import->update([
                    'status' => Status::STOPPED->value,
            ]);
            Log::info("Stopped batch {$import->batch_id}", []);
            $shell_run = shell_exec(base_path('kill-job.sh'));
        } else {
            $success = false;
            $message = "Batch {$import->batch_id} not found";
            Log::warning("Batch not found", []);
        }

        return Action::message('Attempt to stop the export started..');
    }
}
