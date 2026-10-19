<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Budget\BudgetStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BudgetAnalysisController extends Controller
{
    public function __construct(private readonly BudgetStorage $storage) {}

    public function models(): JsonResponse
    {
        try {
            $response = Http::timeout(3)
                ->get((string) config('services.budget.ollama_tags_url', 'http://127.0.0.1:11434/api/tags'))
                ->throw();
            $models = collect($response->json('models', []))->pluck('name')->filter()->values();

            return response()->json([
                'data' => $models,
                'ollama' => [
                    'available' => true,
                    'message' => $models->isEmpty() ? 'Ollama เปิดอยู่ แต่ยังไม่มีโมเดลในเครื่อง' : 'Ollama พร้อมใช้งาน',
                ],
            ]);
        } catch (Throwable $exception) {
            Log::notice('Ollama is unavailable for budget analysis', ['message' => $exception->getMessage()]);

            return response()->json([
                'data' => [],
                'ollama' => [
                    'available' => false,
                    'message' => 'Ollama ยังไม่พร้อม ระบบจะวิเคราะห์ด้วย Vector Search โดยไม่ใช้เหตุผล AI',
                ],
            ]);
        }
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'product_desc' => ['required', 'string', 'max:10000'],
            'score_threshold' => ['required', 'numeric', 'between:0,0.9'],
            'max_ai_rows' => ['required', 'integer', 'between:1,100'],
            'use_ollama' => ['required', 'boolean'],
            'ollama_model' => ['required', 'string', 'max:255'],
            'ollama_timeout' => ['required', 'integer', 'between:30,600'],
        ], [
            'file.uploaded' => 'อัปโหลดไฟล์ Excel ไม่สำเร็จ กรุณาเลือกไฟล์อีกครั้ง',
            'file.mimes' => 'ไฟล์ที่เลือกต้องเป็น Excel นามสกุล .xlsx',
            'file.max' => 'ไฟล์ Excel ต้องมีขนาดไม่เกิน 20 MB เพื่อป้องกันหน่วยความจำเซิร์ฟเวอร์ไม่เพียงพอ',
        ]);

        $userId = $request->user()->getAuthIdentifier();
        $this->cancelActiveAnalyses($userId);
        $analysisId = (string) Str::uuid();
        $inputKey = $this->storage->key($userId, $analysisId, 'input.xlsx');
        $statusKey = $this->storage->key($userId, $analysisId, 'status.json');
        $jobKey = $this->storage->key($userId, $analysisId, 'job.json');
        $outputKey = $this->storage->key($userId, $analysisId, 'result.xlsx');
        $embeddingBackend = (string) config('services.budget.embedding_backend', 'transformer');
        $cacheKey = 'budget-cache/'.hash_file('sha256', $data['file']->getRealPath()).'.'.$embeddingBackend.'.cache';
        $queueKey = "budget-queue/{$userId}/{$analysisId}.json";

        try {
            $this->storage->putUploadedFile($inputKey, $data['file']);
            $this->storage->putJson($statusKey, [
                'status' => 'queued',
                'progress' => 1,
                'message' => 'รับไฟล์แล้ว กำลังรอ Worker เริ่มวิเคราะห์',
                'updated_at' => now()->toIso8601String(),
            ]);
            $this->storage->putJson($jobKey, [
                'input_key' => $inputKey,
                'output_key' => $outputKey,
                'status_key' => $statusKey,
                'embedding_cache_key' => $cacheKey,
                'product_desc' => trim($data['product_desc']),
                'score_threshold' => (float) $data['score_threshold'],
                'max_ai_rows' => (int) $data['max_ai_rows'],
                'ollama_model' => trim($data['ollama_model']),
                'use_ollama' => (bool) $data['use_ollama'],
                'ollama_timeout' => (int) $data['ollama_timeout'],
            ]);
            $this->storage->putJson($queueKey, [
                'job_key' => $jobKey,
                'status_key' => $statusKey,
            ]);
        } catch (Throwable $exception) {
            $this->storage->deleteDirectory($this->storage->analysisPrefix($userId, $analysisId));
            $this->storage->delete($queueKey);
            Log::error('Budget analysis could not be queued.', [
                'analysis_id' => $analysisId,
                'storage_disk' => config('services.budget.storage_disk'),
                'exception' => $exception,
            ]);
            throw ValidationException::withMessages([
                'analysis' => 'บันทึกงานวิเคราะห์ไม่สำเร็จ: '.Str::limit($exception->getMessage(), 1000),
            ]);
        }

        return response()->json(['data' => [
            'analysis_id' => $analysisId,
            'status' => 'queued',
            'progress' => 1,
            'message' => 'รับไฟล์แล้ว กำลังรอ Worker เริ่มวิเคราะห์',
        ]], 202);
    }

    public function status(Request $request, string $analysisId): JsonResponse
    {
        $key = $this->ownedKey($request, $analysisId, 'status.json');
        abort_unless($this->storage->exists($key), 404, 'ไม่พบงานวิเคราะห์นี้');

        try {
            $status = $this->storage->json($key);
        } catch (Throwable $exception) {
            Log::warning('Budget status is temporarily unreadable.', [
                'analysis_id' => $analysisId,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['data' => [
                'analysis_id' => $analysisId,
                'status' => 'processing',
                'progress' => 1,
                'message' => 'กำลังอัปเดตสถานะ กรุณารอสักครู่',
            ]]);
        }

        $status['analysis_id'] = $analysisId;

        return response()->json(['data' => $status]);
    }

    public function preview(Request $request, string $analysisId): JsonResponse
    {
        $data = $request->validate(['sheet' => ['required', 'string', 'max:255']]);
        $key = $this->ownedKey($request, $analysisId, 'result.xlsx');
        abort_unless($this->storage->exists($key), 404, 'ไม่พบไฟล์ผลลัพธ์');
        $path = $this->storage->temporaryCopy($key, '.xlsx');

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
            abort_unless(in_array($data['sheet'], $workbook->getSheetNames(), true), 404, 'ไม่พบ Sheet ที่เลือก');
            $preview = array_slice($workbook->getSheetByName($data['sheet'])->toArray(null, true, true, false), 0, 50);
            $workbook->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        return response()->json(['data' => ['sheet' => $data['sheet'], 'preview' => $preview]]);
    }

    public function download(Request $request, string $analysisId): StreamedResponse
    {
        $key = $this->ownedKey($request, $analysisId, 'result.xlsx');
        abort_unless($this->storage->exists($key), 404, 'ไม่พบไฟล์ผลลัพธ์');

        return response()->streamDownload(function () use ($key): void {
            $stream = $this->storage->disk()->readStream($key);
            abort_if($stream === false, 404, 'ไม่สามารถอ่านไฟล์ผลลัพธ์ได้');
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'สรุปงบประมาณรัฐบาลที่เข้ากับสินค้า.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->storage->deleteDirectory('budget/'.$request->user()->getAuthIdentifier());
        $this->storage->deleteDirectory('budget-queue/'.$request->user()->getAuthIdentifier());

        return response()->json(['data' => ['cleared' => true]]);
    }

    private function ownedKey(Request $request, string $analysisId, string $filename): string
    {
        abort_unless(Str::isUuid($analysisId), 404);

        return $this->storage->key($request->user()->getAuthIdentifier(), $analysisId, $filename);
    }

    private function cancelActiveAnalyses(int|string $userId): void
    {
        foreach ($this->storage->files('budget/'.$userId) as $key) {
            if (! str_ends_with($key, '/status.json')) {
                continue;
            }

            try {
                $status = $this->storage->json($key);
                if (! in_array($status['status'] ?? null, ['queued', 'processing'], true)) {
                    continue;
                }

                $this->storage->putJson($key, [
                    'status' => 'cancelled',
                    'progress' => (int) ($status['progress'] ?? 0),
                    'message' => 'ยกเลิกงานเดิมเพื่อเริ่มงานวิเคราะห์ใหม่',
                    'error' => 'งานนี้ถูกแทนที่ด้วยคำสั่งวิเคราะห์ครั้งล่าสุด',
                    'updated_at' => now()->toIso8601String(),
                ]);
                $analysisId = basename(dirname($key));
                $this->storage->delete("budget-queue/{$userId}/{$analysisId}.json");
            } catch (Throwable $exception) {
                Log::warning('Unable to cancel a previous budget job.', [
                    'key' => $key,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
