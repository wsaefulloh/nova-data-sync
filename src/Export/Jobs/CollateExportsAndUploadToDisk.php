<?php

namespace Wsaefulloh\NovaDataSync\Export\Jobs;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Export\Models\Export;
use Wsaefulloh\NovaDataSync\Import\Events\ExportCompletedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Queue\ManuallyFailedException;
use DateTime;
use Throwable;

class CollateExportsAndUploadToDisk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 6;
    public $maxExceptions = 4;
    public $timeout = 90;
    public $failOnTimeout = true;
    public $backoff = 3;

    public function retryUntil(): \DateTime
    {
        return Carbon::now()->addHours(1);
    }

    public function __construct(
        protected string $queueName,
        protected Export $export,
        protected string $batchUuid,
        protected string $batchId,
        protected string $exportName,
        protected string $exportDisk,
        protected string $exportDirectory,
        protected int $totalJobs,
    ) {
        $this->onQueue($queueName);
    }

    public function displayName(): string
    {
        $displayName = sprintf("%s-%s", self::class, $this->batchId);
        Log::info(sprintf('[%s] [%s] displayName [%s]', self::class, $this->batchUuid, $displayName), []);
        return $displayName;
    }

    public function handle(): void
    {
        try {
            $files = $this->getFilesSortedByIndex($this->batchId);
            $this->validateTotalFileAndJob($files);

            // Log::info('HESOYAM', []);

            $collatedFileName = $this->exportName . '_' . now()->format('Y-m-d_H:i:s') . '.csv';
            $collatedFilePath = $this->storagePath($collatedFileName);
            $collatedFileWriter = SimpleExcelWriter::create($collatedFilePath);

            Log::info(sprintf('[%s] [%s] Collating info', self::class, $this->batchUuid), [
                'collatedFileName' => $collatedFileName,
                'collatedFilePath' => $collatedFilePath,
                'collatedFileWriter' => $collatedFileWriter
            ]);

            foreach ($files as $file) {
                $fileRows = SimpleExcelReader::create($this->storagePath($file))->getRows();
                $collatedFileWriter->addRows($fileRows);
            }

            $collatedFileWriter->close();

            $this->deleteAllFile($files);

            Log::info(sprintf('[%s] [%s] Deleting all file', self::class, $this->batchUuid), [
                'files count' => count($files),
            ]);

            $finalCollateFilePath = "{$this->exportDirectory}/{$collatedFileName}";

            $this->export->addMedia($collatedFilePath)
                ->toMediaCollection('file', $this->exportDisk);

            Log::info(sprintf('[%s] [%s] Uploaded collated file to disk', self::class, $this->batchUuid), [
                'disk' => $this->exportDisk,
                'directory' => $this->exportDirectory,
                'path' => $finalCollateFilePath,
            ]);

            $this->export->update([
                'filename' => $collatedFileName,
                'status' => Status::COMPLETED->value,
                'completed_at' => now(),
            ]);

            Log::info(sprintf('[%s] [%s] Update export completed', self::class, $this->batchUuid), [
                'export' => $this->export
            ]);
        } catch (Throwable $e) {
            throw $e;
        }
    }
    

    public function failed(?Throwable $e): void
    {
        Log::error(sprintf('[%s] [%s] Export Failed', self::class, $this->batchUuid), [
            'attempts' => $this->attempts(),
            'batchId' => $this->batchId,
            'exportId' => $this->export->id,
            'exception' => $e,
        ]);

        if (
            $e instanceof ManuallyFailedException
        ) {
            $this->export->update([
                'status' => Status::FAILED->value
            ]);

            $parentBatch = Bus::findBatch($this->batchId);
            $parentBatch?->cancel();

            Log::error(sprintf('[%s] [%s] Perform cleaning csv after Export Failed', self::class, $this->batchUuid), []);
            $files = $this->getFilesSortedByIndex($this->batchId);
            $this->deleteAllFile($files);
        }
    }

    public function validateTotalFileAndJob(array $files): void
    {
        Log::info(sprintf('[%s] [%s] Collating files compare to jobs', self::class, $this->batchUuid), [
            'filteredFiles' => count($files),
            'totalJobs' => $this->totalJobs,
        ]);

        if (count($files) === $this->totalJobs) return;

        $exception = new ManuallyFailedException('There are ExportToCsv job fail, difference file [' . abs($this->totalJobs - count($files)) . ']');
        $this->fail($exception);
        throw $exception;
    }

    public function deleteAllFile(array $files): void
    {
        foreach ($files as $file) {
            unlink($this->storagePath($file));
        }
    }

    public function getFilesSortedByIndex(string $batchId): array
    {
        $allFiles = scandir($this->storagePath());
        $filteredFiles = array_filter($allFiles, function ($file) use ($batchId) {
            return preg_match("/export-{$batchId}-\d+\.csv$/", $file);
        });

        usort($filteredFiles, function ($a, $b) {
            preg_match("/export-[^-]+-(\d+)\.csv$/", $a, $matchesA);
            preg_match("/export-[^-]+-(\d+)\.csv$/", $b, $matchesB);
            return ($matchesA[1] ?? 0) <=> ($matchesB[1] ?? 0);
        });

        return $filteredFiles;
    }

    public function storagePath(string $path = ''): string
    {
        $fullPath = storage_path('app/temp/' . trim($path, '/'));
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }

        return $fullPath;
    }
}