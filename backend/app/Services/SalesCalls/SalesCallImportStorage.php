<?php

namespace App\Services\SalesCalls;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SalesCallImportStorage
{
    public function diskName(): string
    {
        return (string) config('services.sales_calls.storage_disk', 'local');
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    public function store(UploadedFile $file, int $sellerId, string $reportMonth): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'xlsx';
        $path = sprintf(
            'sales-call-imports/%d/%s/%s.%s',
            $sellerId,
            str_replace('-', '/', substr($reportMonth, 0, 7)),
            Str::uuid(),
            $extension,
        );
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('ไม่สามารถเปิดไฟล์ Excel ต้นฉบับเพื่อจัดเก็บได้');
        }

        try {
            $stored = $this->disk()->writeStream($path, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }
        if (! $stored) {
            throw new \RuntimeException('ไม่สามารถจัดเก็บไฟล์ Excel ต้นฉบับได้');
        }

        return $path;
    }
}
