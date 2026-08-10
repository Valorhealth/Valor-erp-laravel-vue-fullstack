<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class ReceiptOcrController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'image', 'max:10240']]);
        $directory = storage_path('app/private/ocr');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$data['image']->guessExtension();
        $data['image']->move($directory, basename($path));

        try {
            $process = new Process([
                (string) config('services.budget.python', 'python'),
                base_path('scripts/scan_receipt.py'),
                $path,
            ], base_path('..'), ['PYTHONUTF8' => '1']);
            $process->setTimeout(180);
            $process->mustRun();
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

            return response()->json(['data' => $result]);
        } catch (Throwable $exception) {
            abort(422, 'AI สแกนเอกสารไม่สำเร็จ: '.Str::limit($exception->getMessage(), 500));
        } finally {
            File::delete($path);
        }
    }
}
