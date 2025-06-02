<?php

namespace Wsaefulloh\NovaDataSync\Import\Jobs;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Import\Models\Import;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Str;
use Throwable;

abstract class ImportProcessor implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected SimpleExcelWriter $failedImportsReportWriter;
    protected string $className;
    protected int $processedCount = 0;
    protected int $failedCount = 0;

    public function __construct(
        protected Import $import,
        protected string $csvFilePath,
        protected int    $index
    ) {
        $this->queue = config('nova-data-sync.imports.queue', 'default');
        $this->className = static::class;
    }

    abstract public static function expectedHeaders(): array;
    abstract protected function rules(array $row, int $rowIndex): array;
    abstract protected function process(array $row, int $rowIndex): void;

    public static function chunkSize(): int
    {
        return config('nova-data-sync.imports.chunk_size', 1000);
    }

    /**
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function handle(): void
    {
        if ($this->shouldQuit()) {
            return;
        }

        Log::info("[$this->className] Import processor started", [
            'import_id' => $this->import->id,
        ]);

        try {
            $this->initializeFailedImportsReport();
        } catch (Throwable $e) {
            Log::error("[$this->className] Failed to initialize failed imports report", [
                'import_id' => $this->import->id,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        if (!file_exists($this->csvFilePath)) {
            try {
                $media = $this->import->getFirstMedia('file');
                $mediaStream = $media->stream();

                if (!is_resource($mediaStream)) {
                    Log::warning("[$this->className] Media stream is invalid", [
                        'import_id' => $this->import->id,
                    ]);
                    return;
                }

                $mediaContent = stream_get_contents($mediaStream);
                file_put_contents($this->csvFilePath, $mediaContent);
            } catch (Throwable $e) {
                Log::error("[$this->className] Failed to retrieve media content", [
                    'import_id' => $this->import->id,
                    'exception' => $e->getMessage(),
                ]);
                return;
            }
        }

        try {
            $readerRows = SimpleExcelReader::create($this->csvFilePath, 'csv')->getRows();
        } catch (Throwable $e) {
            Log::error("[$this->className] Failed to read CSV file", [
                'csv_path' => $this->csvFilePath,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $rows = $readerRows->skip($this->index)->take(static::chunkSize());

        $rows->each(function ($row, $index) {
            if ($this->shouldQuit()) {
                return false;
            }

            $rowIndex = $index + 1;

            try {
                $this->validateRow($row, $rowIndex);
                $this->process($row, $rowIndex);
                $this->incrementTotalRowsProcessed();
            } catch (Throwable $e) {
                $this->incrementTotalRowsFailed($row, $rowIndex, $e->getMessage());
            }

            return true;
        });

        $this->finish();

        try {
            $this->failedImportsReportWriter->close();

            $this->import->addMedia($this->failedImportsReportWriter->getPath())
                ->toMediaCollection('failed-chunks', config('nova-data-sync.imports.disk'));
        } catch (Throwable $e) {
            Log::error("[$this->className] Failed to attach failed import chunk to media", [
                'import_id' => $this->import->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function incrementTotalRowsProcessed(): void
    {
        $this->processedCount++;

        if ($this->processedCount >= 100) {
            Log::debug("[{$this->className}] Processed 100 rows, committing to database...");
            $this->import->increment('total_rows_processed', $this->processedCount);
            $this->processedCount = 0;
        }
    }

    protected function incrementTotalRowsFailed($row, $rowIndex, $message): void
    {
        Log::debug("[{$this->className}] Failed row {$rowIndex}", [
            'message' => $message,
            'row' => $row,
        ]);

        data_set($row, 'origin_row', $rowIndex);
        data_set($row, 'error', $message);

        try {
            $this->failedImportsReportWriter->addRow($row);
        } catch (Throwable $e) {
            Log::error("[{$this->className}] Failed to write failed row to report", [
                'row' => $row,
                'exception' => $e->getMessage(),
            ]);
        }

        $this->failedCount++;

        if ($this->failedCount >= 100) {
            Log::debug("[{$this->className}] Failed 100 rows, committing to database...");
            $this->import->increment('total_rows_failed', $this->failedCount);
            $this->failedCount = 0;
        }

        // ⚠️ Jangan tambahkan $this->incrementTotalRowsProcessed() di sini
    }

    private function initializeFailedImportsReport(): void
    {
        $fileName = "import-{$this->import->id}-failed-chunk-" . now()->format('YmdHis') . '-' . Str::random(6) . '.csv';
        $filePath = storage_path('app/' . $fileName);

        Log::debug('[ImportProcessor] Initializing failed imports report', [
            'file_path' => $filePath,
        ]);

        $this->failedImportsReportWriter = SimpleExcelWriter::create($filePath);
    }

    /**
     * @throws ValidationException
     */
    protected function validateRow(array $row, int $rowIndex): bool
    {
        if (empty($this->rules($row, $rowIndex))) {
            return true;
        }

        $validator = validator($row, $this->rules($row, $rowIndex));

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return true;
    }

    protected function shouldQuit(): bool
    {
        if ($this->processedCount % $this->rowsToProcessBeforeCheckingForQuit() !== 0) {
            return false;
        }

        $shouldQuit = Cache::remember(
            'nova-data-sync-import-' . $this->import->id . '-should-stop',
            now()->addSeconds($this->secondsBeforeCheckingForQuit()),
            function () {
                $this->import->refresh();
                return in_array($this->import->status, [Status::STOPPING->value, Status::STOPPED->value]);
            }
        );

        if ($shouldQuit) {
            Log::info('[' . static::class . '] Stopping import processor', [
                'import_id' => $this->import->id,
            ]);
        }

        return $shouldQuit;
    }

    protected function rowsToProcessBeforeCheckingForQuit(): int
    {
        return 10;
    }

    protected function secondsBeforeCheckingForQuit(): int
    {
        return 10;
    }

    protected function finish(): void
    {
        if ($this->processedCount > 0) {
            $this->import->increment('total_rows_processed', $this->processedCount);
            $this->processedCount = 0;
        }

        if ($this->failedCount > 0) {
            $this->import->increment('total_rows_failed', $this->failedCount);
            $this->failedCount = 0;
        }
    }
}
