<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class BudgetAnalysisController extends Controller
{
    public function models(): JsonResponse
    {
        try {
            $response = Http::timeout(3)->get((string) config('services.budget.ollama_tags_url', 'http://127.0.0.1:11434/api/tags'))->throw();
            $models = collect($response->json('models', []))->pluck('name')->filter()->values();

            return response()->json([
                'data' => $models,
                'ollama' => [
                    'available' => true,
                    'message' => $models->isEmpty()
                        ? 'Ollama เปิดอยู่ แต่ยังไม่มีโมเดลในเครื่อง'
                        : 'Ollama พร้อมใช้งาน',
                ],
            ]);
        } catch (Throwable $exception) {
            Log::notice('Ollama is unavailable for budget analysis', ['message' => $exception->getMessage()]);

            return response()->json([
                'data' => [],
                'ollama' => [
                    'available' => false,
                    'message' => 'Ollama ยังไม่เปิดใช้งาน ระบบจะวิเคราะห์ด้วย Vector Search โดยไม่ใช้เหตุผล AI',
                ],
            ]);
        }
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:102400'],
            'product_desc' => ['required', 'string', 'max:10000'],
            'score_threshold' => ['required', 'numeric', 'between:0,0.9'],
            'max_ai_rows' => ['required', 'integer', 'between:1,100'],
            'use_ollama' => ['required', 'boolean'],
            'ollama_model' => ['required', 'string', 'max:255'],
            'ollama_timeout' => ['required', 'integer', 'between:30,600'],
        ], [
            'file.uploaded' => 'อัปโหลดไฟล์ Excel ไม่สำเร็จ กรุณาเปิดระบบใหม่ด้วย scripts/start-local.ps1 แล้วเลือกไฟล์อีกครั้ง',
            'file.mimes' => 'ไฟล์ที่เลือกต้องเป็น Excel นามสกุล .xlsx',
            'file.max' => 'ไฟล์ Excel ต้องมีขนาดไม่เกิน 100 MB',
        ]);

        $this->cancelActiveAnalyses($request);
        $analysisId = (string) Str::uuid();
        $directory = $this->analysisDirectory($request, $analysisId);
        File::ensureDirectoryExists($directory);
        $inputPath = $directory.DIRECTORY_SEPARATOR.'input.xlsx';
        $outputPath = $directory.DIRECTORY_SEPARATOR.'result.xlsx';
        $statusPath = $directory.DIRECTORY_SEPARATOR.'status.json';
        $configPath = $directory.DIRECTORY_SEPARATOR.'job.json';

        try {
            $data['file']->move($directory, 'input.xlsx');

            File::put($statusPath, json_encode([
                'status' => 'queued',
                'progress' => 1,
                'message' => 'รับไฟล์แล้ว กำลังเริ่มระบบวิเคราะห์',
                'updated_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            File::put($configPath, json_encode([
                'input_path' => $inputPath,
                'output_path' => $outputPath,
                'status_path' => $statusPath,
                'product_desc' => trim($data['product_desc']),
                'score_threshold' => (float) $data['score_threshold'],
                'max_ai_rows' => (int) $data['max_ai_rows'],
                'ollama_model' => trim($data['ollama_model']),
                'use_ollama' => (bool) $data['use_ollama'],
                'ollama_timeout' => (int) $data['ollama_timeout'],
                'embedding_cache_path' => storage_path(
                    'app/private/budget-cache/'.hash_file('sha256', $inputPath).'.pt'
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            Log::error('Budget analysis failed unexpectedly', [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'python' => config('services.budget.python'),
            ]);
            throw ValidationException::withMessages([
                'analysis' => 'วิเคราะห์ไม่สำเร็จ: '.Str::limit($exception->getMessage(), 1000),
            ]);
        }

        return response()->json(['data' => [
            'analysis_id' => $analysisId,
            'status' => 'queued',
            'progress' => 1,
            'message' => 'รับไฟล์แล้ว กำลังเริ่มระบบวิเคราะห์',
        ]], 202);
    }

    public function status(Request $request, string $analysisId): JsonResponse
    {
        abort_unless(Str::isUuid($analysisId), 404);
        $path = $this->analysisDirectory($request, $analysisId).DIRECTORY_SEPARATOR.'status.json';
        abort_unless(File::exists($path), 404, 'ไม่พบงานวิเคราะห์นี้');

        try {
            $status = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            Log::warning('Budget status file is temporarily unreadable', [
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
        $path = $this->resultPath($request, $analysisId);
        abort_unless(File::exists($path), 404, 'ไม่พบไฟล์ผลลัพธ์');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        abort_unless(in_array($data['sheet'], $workbook->getSheetNames(), true), 404, 'ไม่พบ Sheet ที่เลือก');
        $preview = array_slice($workbook->getSheetByName($data['sheet'])->toArray(null, true, true, false), 0, 50);
        $workbook->disconnectWorksheets();

        return response()->json(['data' => ['sheet' => $data['sheet'], 'preview' => $preview]]);
    }

    public function download(Request $request, string $analysisId): mixed
    {
        $path = $this->resultPath($request, $analysisId);
        abort_unless(File::exists($path), 404, 'ไม่พบไฟล์ผลลัพธ์');

        return response()->download($path, 'สรุปงบประมาณรัฐบาลที่เข้ากับสินค้า.xlsx')->deleteFileAfterSend(false);
    }

    public function clear(Request $request): JsonResponse
    {
        $directory = storage_path('app/private/budget/'.$request->user()->getAuthIdentifier());
        if (File::exists($directory)) {
            File::deleteDirectory($directory);
        }

        return response()->json(['data' => ['cleared' => true]]);
    }

    private function resultPath(Request $request, string $analysisId): string
    {
        abort_unless(Str::isUuid($analysisId), 404);

        return $this->analysisDirectory($request, $analysisId).DIRECTORY_SEPARATOR.'result.xlsx';
    }

    private function analysisDirectory(Request $request, string $analysisId): string
    {
        return storage_path('app/private/budget/'.$request->user()->getAuthIdentifier().DIRECTORY_SEPARATOR.$analysisId);
    }

    private function cancelActiveAnalyses(Request $request): void
    {
        $userDirectory = storage_path('app/private/budget/'.$request->user()->getAuthIdentifier());
        if (! File::isDirectory($userDirectory)) {
            return;
        }

        foreach (File::directories($userDirectory) as $directory) {
            $statusPath = $directory.DIRECTORY_SEPARATOR.'status.json';
            if (! File::exists($statusPath)) {
                continue;
            }

            try {
                $status = json_decode(File::get($statusPath), true, flags: JSON_THROW_ON_ERROR);
                if (! in_array($status['status'] ?? null, ['queued', 'processing'], true)) {
                    continue;
                }
                File::put($statusPath, json_encode([
                    'status' => 'cancelled',
                    'progress' => (int) ($status['progress'] ?? 0),
                    'message' => 'ยกเลิกงานเดิมเพื่อเริ่มงานใหม่',
                    'error' => 'งานนี้ถูกแทนที่ด้วยคำสั่งวิเคราะห์ครั้งล่าสุด',
                    'updated_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                Log::warning('Unable to cancel previous budget job', [
                    'path' => $statusPath,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

}
