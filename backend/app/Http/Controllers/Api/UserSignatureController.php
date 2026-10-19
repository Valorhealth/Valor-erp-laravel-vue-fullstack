<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSignatureAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserSignatureController extends Controller
{
    public function store(Request $request, User $user): JsonResponse
    {
        return $this->saveSignature($request, $user);
    }

    public function storeMine(Request $request): JsonResponse
    {
        return $this->saveSignature($request, $request->user(), true);
    }

    private function saveSignature(Request $request, User $user, bool $verifyPassword = false): JsonResponse
    {
        if ($verifyPassword) {
            $data = $request->validate(['current_password' => ['required', 'string', 'max:255']]);
            if (! Hash::check($data['current_password'], (string) $user->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
            }
        }
        $file = $request->validate([
            'file' => ['required', 'file', 'max:1024', 'mimes:png', 'dimensions:max_width=2000,max_height=1000'],
        ])['file'];
        $bytes = file_get_contents($file->getRealPath());
        abort_if($bytes === false, 422, 'อ่านไฟล์ลายเซ็นไม่สำเร็จ');

        $assetId = DB::transaction(function () use ($request, $user, $file, $bytes): int {
            $latest = DB::table('user_signature_assets')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->orderByDesc('version_no')
                ->first();
            DB::table('user_signature_assets')
                ->where('user_id', $user->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'superseded_at' => now(),
                    'superseded_by' => $request->user()->username,
                ]);
            $inserted = DB::selectOne(
                'INSERT INTO user_signature_assets
                    (user_id, version_no, file_name, mime_type, image_base64, image_sha256, is_current, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, ?, ?, TRUE, ?, CURRENT_TIMESTAMP)
                 RETURNING signature_asset_id',
                [
                    $user->id,
                    ((int) ($latest->version_no ?? 0)) + 1,
                    $file->getClientOriginalName(),
                    'image/png',
                    base64_encode($bytes),
                    hash('sha256', $bytes),
                    $request->user()->username,
                ],
            );

            return (int) $inserted->signature_asset_id;
        });

        return response()->json(['data' => UserSignatureAsset::query()
            ->select(['signature_asset_id', 'user_id', 'version_no', 'file_name', 'mime_type', 'image_sha256', 'uploaded_by', 'uploaded_at'])
            ->findOrFail($assetId)], 201);
    }

    public function show(User $user): mixed
    {
        $asset = UserSignatureAsset::query()
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->firstOrFail();

        return response(base64_decode((string) $asset->image_base64, true), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename*=UTF-8\'\''.rawurlencode($asset->file_name),
        ]);
    }

    public function showMine(Request $request): mixed
    {
        return $this->show($request->user());
    }
}
