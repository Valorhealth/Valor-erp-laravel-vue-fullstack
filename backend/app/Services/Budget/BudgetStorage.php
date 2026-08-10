<?php

namespace App\Services\Budget;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class BudgetStorage
{
    public function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('services.budget.storage_disk', 'local'));
    }

    public function analysisPrefix(int|string $userId, string $analysisId): string
    {
        return "budget/{$userId}/{$analysisId}";
    }

    public function key(int|string $userId, string $analysisId, string $filename): string
    {
        return $this->analysisPrefix($userId, $analysisId).'/'.$filename;
    }

    public function putUploadedFile(string $key, UploadedFile $file): void
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the uploaded budget file.');
        }

        try {
            if (! $this->disk()->writeStream($key, $stream)) {
                throw new RuntimeException('Unable to store the uploaded budget file.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $payload */
    public function putJson(string $key, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! $this->disk()->put($key, $json)) {
            throw new RuntimeException("Unable to store {$key}.");
        }
    }

    /** @return array<string, mixed> */
    public function json(string $key): array
    {
        return json_decode($this->disk()->get($key), true, flags: JSON_THROW_ON_ERROR);
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    /** @return array<int, string> */
    public function files(string $prefix): array
    {
        return $this->disk()->allFiles($prefix);
    }

    public function deleteDirectory(string $prefix): void
    {
        $this->disk()->deleteDirectory($prefix);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function temporaryCopy(string $key, string $extension = ''): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'valor-budget-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        $path = $extension === '' ? $temporary : $temporary.$extension;
        if ($path !== $temporary) {
            @unlink($temporary);
        }

        $source = $this->disk()->readStream($key);
        $target = fopen($path, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($path);
            throw new RuntimeException('Unable to prepare the budget file.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $path;
    }
}
