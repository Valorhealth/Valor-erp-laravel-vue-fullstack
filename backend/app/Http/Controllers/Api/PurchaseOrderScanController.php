<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\PurchaseOrderMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class PurchaseOrderScanController extends Controller
{
    public function __invoke(Request $request, PurchaseOrderMatcher $matcher): JsonResponse
    {
        $file = $request->validate([
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,xlsx'],
        ])['document'];
        $directory = storage_path('app/private/po-scan');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.($file->guessExtension() ?: $file->getClientOriginalExtension());
        $file->move($directory, basename($path));

        try {
            $process = new Process([
                (string) config('services.budget.python', 'python'),
                base_path('scripts/scan_purchase_order.py'),
                $path,
            ], base_path('..'), ['PYTHONUTF8' => '1']);
            $process->setTimeout(180);
            $process->mustRun();
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($result), 422, 'ผลลัพธ์จาก AI ไม่ถูกต้อง');

            return response()->json(['data' => $matcher->enrich($result)]);
        } catch (Throwable $exception) {
            report($exception);
            abort(422, 'AI อ่านใบ PO ไม่สำเร็จ: '.Str::limit($exception->getMessage(), 500));
        } finally {
            File::delete($path);
        }
    }
}
