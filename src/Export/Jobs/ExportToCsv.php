<?php

namespace Wsaefulloh\NovaDataSync\Export\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wsaefulloh\NovaDataSync\Export\Jobs\ExportProcessor;
use Illuminate\Support\Facades\Log; 
use Spatie\SimpleExcel\SimpleExcelWriter;
use Carbon\Carbon;
use stdClass;

class ExportToCsv implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 6;
    public $maxExceptions = 2;
    public $timeout = 90;
    public $failOnTimeout = true;

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::now()->addHours(1);
    }

    public function __construct(
        private ExportProcessor $processor,
        private int $page,
        private int $perPage,
        private string $batchUuid,
    ) {
    }

    public function displayName(): string
    {
        $displayName = sprintf("%s-%s-%s", self::class, $this->batch()->id, $this->page);
        Log::info(sprintf('[%s] [%s] displayName [%s]', self::class, $this->batchUuid, $displayName), []);
        return $displayName;
    }

    public function handle(): void
    {
        try {
            $items = $this->processor->query()->forPage($this->page, $this->perPage)->get();

            if (empty($items)) return;

            $leadingIndex = str_pad($this->page, 5, '0', STR_PAD_LEFT);
            $fileName = "export-{$this->batch()->id}-{$leadingIndex}.csv";
            $csvPath = $this->storagePath($fileName);
            $csvWriter = SimpleExcelWriter::create($csvPath, 'csv');

            Log::info(sprintf('[%s] [%s] file info', self::class, $this->batchUuid), [
                'fileName' => $fileName,
                'csvPath' => $csvPath,
            ]);

            $items->each(function ($item) use ($csvWriter) {
                if ($item instanceof Model) {
                    $item = $item->toArray();
                }

                if ($item instanceof stdClass) {
                    $item = json_decode(json_encode($item), true);
                }

                foreach ($item as $key => $value) {
                    if (is_array($value)) {
                        $item[$key] = json_encode($value);
                    }
                }

                $csvWriter->addRow($item);
            });

            $csvWriter->close();
        } catch (\Throwable $e) {
            Log::error(sprintf('[%s] [%s] ', self::class, $this->batchUuid), [
                'batchId' => $this->batch()->id,
                'exception' => $e,
            ]);
        }
    }

    protected function storagePath($path = ''): string
    {
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'));
        }

        return storage_path('app/temp/' . trim($path, '/'));
    }
}