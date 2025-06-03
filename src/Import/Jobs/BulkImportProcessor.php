<?php

namespace Wsaefulloh\NovaDataSync\Import\Jobs;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Import\Events\ImportCompletedEvent;
use Wsaefulloh\NovaDataSync\Import\Events\ImportStartedEvent;
use Wsaefulloh\NovaDataSync\Import\Models\Import;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class BulkImportProcessor implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected Import $import)
    {
        //
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        try {
            $this->import->refresh();

            if ($this->import->status === Status::STOPPED->value) {
                Log::info('[BulkImportProcessor] Import was stopped. Marking import as completed.', [
                    'import_id' => $this->import->id,
                    'import_processor' => $this->import->processor,
                ]);
                $this->import->update([
                    'completed_at' => now(),
                ]);

                return;
            }

            Log::info('[BulkImportProcessor] Starting bulk import...', [
                'import_id' => $this->import->id,
                'import_processor' => $this->import->processor,
            ]);

            $this->import->update([
                'status' => Status::IN_PROGRESS,
                'started_at' => now(),
            ]);

            event(new ImportStartedEvent($this->import));

            $media = $this->import->getFirstMedia('file');

            if (!$media) {
                Log::error('[BulkImportProcessor] No media file found for import.', [
                    'import_id' => $this->import->id,
                ]);
                $this->import->update(['status' => Status::FAILED]);
                return;
            }

            // Save file to local storage
            $filepath = storage_path('app/' . $media->uuid);
            try {
                $stream = $media->stream();
                if (!is_resource($stream)) {
                    throw new \Exception('Invalid stream from media');
                }
                $content = stream_get_contents($stream);
                file_put_contents($filepath, $content);
            } catch (Throwable $e) {
                Log::error('[BulkImportProcessor] Failed to store media file locally.', [
                    'exception' => $e->getMessage(),
                    'import_id' => $this->import->id,
                ]);
                $this->import->update(['status' => Status::FAILED]);
                return;
            }

            $rowsCount = $this->import->file_total_rows;
            $chunkSize = $this->import->processor::chunkSize();

            $jobs = [];

            for ($rowIndex = 0; $rowIndex < $rowsCount; $rowIndex += $chunkSize) {
                try {
                    $jobs[] = new $this->import->processor($this->import, $filepath, $rowIndex);
                } catch (Throwable $e) {
                    Log::error('[BulkImportProcessor] Failed to instantiate processor job.', [
                        'row_index' => $rowIndex,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            if (empty($jobs)) {
                Log::debug('[BulkImportProcessor] No jobs to dispatch. Marking import as completed.', [
                    'import_id' => $this->import->id,
                ]);

                $this->import->update([
                    'status' => Status::COMPLETED,
                    'completed_at' => now(),
                ]);

                if (file_exists($filepath)) {
                    unlink($filepath);
                }

                event(new ImportCompletedEvent($this->import));
                return;
            }

            Log::debug('[BulkImportProcessor] Dispatching jobs...', [
                'import_id' => $this->import->id,
                'job_count' => count($jobs),
            ]);

            $import = $this->import;

            Bus::batch($jobs)
                ->then(function (Batch $batch) {
                    Log::debug('[BulkImportProcessor] Batch finished', [
                        'batch_id' => $batch->id,
                    ]);
                })
                ->finally(function (Batch $batch) use ($import, $filepath) {
                    $import->refresh();

                    if (in_array($import->status, [Status::STOPPED->value, Status::STOPPING->value])) {
                        Log::info('[BulkImportProcessor] Import was stopped.', [
                            'import_id' => $import->id,
                            'batch_id' => $batch->id,
                        ]);
                        $import->update([
                            'status' => Status::STOPPED,
                            'completed_at' => now(),
                        ]);
                    } else {
                        $import->update([
                            'status' => Status::COMPLETED,
                            'completed_at' => now(),
                        ]);
                    }

                    Log::info('[BulkImportProcessor] Import finished.', [
                        'import_id' => $import->id,
                        'status' => $import->status,
                    ]);

                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }

                    event(new ImportCompletedEvent($import));

                    dispatch(new CollateFailedChunks($import))
                        ->onQueue(config('nova-data-sync.imports.queue', 'default'));
                })
                ->allowFailures()
                ->name($this->import->id . '-import')
                ->onQueue(config('nova-data-sync.imports.queue', 'default'))
                ->dispatch();
        } catch (Throwable $e) {
            Log::error('[BulkImportProcessor] Fatal error in handle()', [
                'exception' => $e->getMessage(),
                'import_id' => $this->import->id,
            ]);
            $this->import->update(['status' => Status::FAILED]);
        }
    }
}
