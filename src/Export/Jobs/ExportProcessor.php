<?php

namespace Wsaefulloh\NovaDataSync\Export\Jobs;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Export\Models\Export;
use Wsaefulloh\NovaDataSync\Import\Events\ExportStartedEvent;
use Wsaefulloh\NovaDataSync\Export\Jobs\CollateExportsAndUploadToDisk;
use Wsaefulloh\NovaDataSync\Export\Jobs\ExportToCsv;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use stdClass;
use Throwable;
use Illuminate\Bus\Batchable;

abstract class ExportProcessor implements ShouldQueue
{
    use Batchable;
    protected ?Authenticatable $user = null;
    protected int $perPage;
    protected string $name;
    protected string $disk;
    protected string $directory;

    protected string $queueName = 'exports';

    public function __construct(array $options = [])
    {
        if (isset($options['user'])) {
            $this->user = $options['user'];
            $this->userId = $options['user']->id ?? null;
            $this->userType = get_class($options['user']);
        }

        if (isset($options['perPage'])) {
            $this->perPage = $options['perPage'];
        }

        if (isset($options['name'])) {
            $this->name = $options['name'];
        }

        if (isset($options['disk'])) {
            $this->disk = $options['disk'];
        }

        if (isset($options['directory'])) {
            $this->directory = $options['directory'];
        }
    }

    public function handle(): void
    {
        $this->initialize();
        $exportName = $this->name;
        $exportDisk = $this->disk;
        $exportDirectory = $this->directory;

        $query = $this->query();
        $totalRow = $query->count();
        $totalPage = ceil($totalRow / $this->perPage);
        $batchUuid = Str::uuid();

        Log::info(sprintf('[%s] [%s] Start exporting', self::class, $batchUuid), [
            'totalRow' => $totalRow,
            'totalPage' => $totalPage,
            'perPage' => $this->perPage,
            'exportName' => $exportName,
            'exportDisk' => $exportDisk,
            'exportDirectory' => $exportDirectory,
            'queueName' => $this->queueName,
            'batch_uuid'=> $batchUuid
        ]);

        $export = $this->initializeExport($totalRow, $batchUuid);

        if ($totalRow <= 0) {
            $export->update([
                'status' => Status::COMPLETED->value,
                'completed_at' => now(),
            ]);
            Log::info(sprintf('[%s] [%s] No records to export', self::class, $batchUuid), $export->toArray());
            return;
        }

        $jobs = [];
        for ($page = 1; $page <= $totalPage; $page++) {
            $jobs[] = (new ExportToCsv($this, $page, $this->perPage, $batchUuid))
                ->onQueue($this->queueName);
        }

        $totalJobs = count($jobs);

        Log::info(sprintf('[%s] [%s] Jobs setup', self::class, $batchUuid), [
            'Total Jobs' => $totalJobs
        ]);

        Bus::batch($jobs)
            ->progress(function (Batch $batch) use ($export) {
                $export->update([
                    'status' => Status::IN_PROGRESS->value,
                    'batch_id' => $batch->id,
                ]);
            })
            ->then(function (Batch $batch) use ($export, $batchUuid, $exportName, $exportDisk, $exportDirectory, $totalJobs) {
                Log::info(sprintf('[%s] [%s] Batch then', self::class, $batchUuid), [
                    'export' => $export,
                    'batchId' => $batch->id,
                    'exportName' => $exportName,
                    'exportDisk' => $exportDisk,
                    'exportDirectory' => $exportDirectory
                ]);

                dispatch((new CollateExportsAndUploadToDisk(
                    $this->queueName,
                    $export,
                    $batchUuid,
                    $batch->id,
                    $exportName,
                    $exportDisk,
                    $exportDirectory,
                    $totalJobs
                ))->onQueue('export-collate'));
            })
            ->catch(function (Batch $batch, Throwable $e) use ($export, $batchUuid) {
                Log::error(sprintf('[%s] [%s] Batch catch', self::class, $batchUuid), [
                    'batchId' => $batch->id,
                    'exception' => $e,
                ]);

                $export->update([
                    'status' => Status::FAILED
                ]);
            })
            ->allowFailures()
            ->name($exportName)
            ->onQueue($this->queueName)
            ->dispatch();
    }

    private function initialize(): void
    {
        if (empty($this->name)) $this->name = class_basename($this);
        if (empty($this->disk)) $this->disk = 'public';
        if (empty($this->perPage)) $this->perPage = 2000;
        if (empty($this->directory)) $this->directory = '';
    }

    private function initializeExport(int $totalRow, string $batchUuid): Export
    {
        Log::debug('ExportProcessor user info', [
            'user_id' => $this->userId,
        ]);
        return Export::query()->create([
            'user_id' => $this->userId,
            'user_type' => $this->userType,
            'status' => Status::PENDING->value,
            'processor' => self::class,
            'file_total_rows' => $totalRow,
            'started_at' => now(),
            'batch_uuid' => $batchUuid,
        ]);
    }

    public function setUser(Authenticatable $user): self
    {
        $this->user = $user;
        $this->userId = $user->getAuthIdentifier();
        $this->userType = get_class($user);

        return $this;
    }

    public function setPerPage(int $perPage): self
    {
        $this->perPage = $perPage;
        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    public function setDirectory(string $directory): self
    {
        $this->directory = $directory;
        return $this;
    }

}