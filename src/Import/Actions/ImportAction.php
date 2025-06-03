<?php

namespace Wsaefulloh\NovaDataSync\Import\Actions;

use Wsaefulloh\NovaDataSync\Enum\Status;
use Wsaefulloh\NovaDataSync\Import\Jobs\BulkImportProcessor;
use Wsaefulloh\NovaDataSync\Import\Jobs\ImportProcessor;
use Wsaefulloh\NovaDataSync\Import\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Illuminate\Support\Facades\Log;

class ImportAction
{
    /**
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     * @throws InvalidArgumentException
     */
    public static function make(string $processor, string $filepath, ?Authenticatable $user = null): Import
    {
        // Pastikan file ada dulu
        if (!file_exists($filepath)) {
            throw new InvalidArgumentException("File does not exist at path: {$filepath}");
        }

        $excelReader = SimpleExcelReader::create($filepath, 'csv');

        // Validasi processor subclass ImportProcessor
        if (!is_subclass_of($processor, ImportProcessor::class) && $processor !== ImportProcessor::class) {
            throw new InvalidArgumentException('Class name must be a subclass of ' . ImportProcessor::class);
        }

        // Cek headers wajib
        if (static::checkHeaders($processor::expectedHeaders(), $excelReader->getHeaders()) === false) {
            throw new InvalidArgumentException('File headers do not match the expected headers.');
        }

        // Buat model import
        $import = Import::query()->create([
            'user_id' => $user?->id ?? null,
            'user_type' => !empty($user) ? get_class($user) : null,
            'filename' => basename($filepath),
            'status' => Status::PENDING,
            'processor' => $processor,
            'file_total_rows' => $excelReader->getRows()->count(),
        ]);

        // Attach file sebagai media
        $import->addMedia($filepath)->toMediaCollection('file');

        // Dispatch bulk job, tangani exception dispatch agar tidak silent gagal
        try {
            dispatch(new BulkImportProcessor($import));
        } catch (\Throwable $e) {
            Log::error("Failed to dispatch BulkImportProcessor job for Import ID {$import->id}: {$e->getMessage()}");
            // Opsional: bisa set status failed di import
            $import->update(['status' => Status::FAILED]);
            throw $e;
        }

        return $import;
    }

    /**
     * Optional setter, jika kamu memang pakai instance ImportAction di beberapa konteks
     */
    public function setUser(Authenticatable $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Validasi kecocokan header file dengan yang diharapkan processor
     */
    public static function checkHeaders(array $expectedHeaders, array $headers): bool
    {
        // Pastikan semua expected ada di header file
        foreach ($expectedHeaders as $expectedHeader) {
            if (!in_array($expectedHeader, $headers)) {
                return false;
            }
        }

        // Pastikan tidak ada expected header yang hilang
        return count(array_diff($expectedHeaders, $headers)) === 0;
    }
}