<?php

namespace Tests\Unit;

use App\Services\Budget\BudgetStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BudgetStorageTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = storage_path('framework/testing/budget-storage-'.uniqid());
        config()->set('filesystems.disks.budget-test', [
            'driver' => 'local',
            'root' => $this->storageRoot,
            'throw' => true,
        ]);
        config()->set('services.budget.storage_disk', 'budget-test');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    #[Test]
    public function it_persists_uploaded_files_status_and_temporary_downloads(): void
    {
        $storage = app(BudgetStorage::class);
        $prefix = $storage->analysisPrefix(7, 'analysis-id');
        $inputKey = $prefix.'/input.xlsx';
        $statusKey = $prefix.'/status.json';

        $storage->putUploadedFile($inputKey, UploadedFile::fake()->createWithContent('book.xlsx', 'xlsx-data'));
        $storage->putJson($statusKey, ['status' => 'queued', 'progress' => 1]);

        $this->assertTrue($storage->exists($inputKey));
        $this->assertSame('queued', $storage->json($statusKey)['status']);
        $this->assertContains($statusKey, $storage->files('budget/7'));

        $temporary = $storage->temporaryCopy($inputKey, '.xlsx');
        $this->assertSame('xlsx-data', file_get_contents($temporary));
        @unlink($temporary);
    }
}
