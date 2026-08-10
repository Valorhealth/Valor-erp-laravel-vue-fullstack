<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $query = CreditProfile::query()
            ->when($search !== '', fn ($builder) => $builder->where('ar_code', 'ilike', "%{$search}%")->orWhere('ar_name', 'ilike', "%{$search}%"));
        $summary = (clone $query)->selectRaw('COUNT(*) AS customers, COALESCE(SUM(credit_limit), 0) AS total_credit, COALESCE(SUM(credit_outstanding), 0) AS total_outstanding, COALESCE(AVG(credit_term), 0) AS average_term')->first();

        return response()->json(['data' => [
            'summary' => $summary,
            'profiles' => $query->orderBy('ar_code')->paginate(min(max($request->integer('per_page', 25), 5), 100)),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ar_code' => ['required', 'string', 'max:100'],
            'ar_name' => ['required', 'string', 'max:1000'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'credit_term' => ['required', 'integer', 'min:0'],
            'credit_outstanding' => ['nullable', 'numeric', 'min:0'],
        ]);
        $profile = CreditProfile::query()->find($data['ar_code']) ?? new CreditProfile(['ar_code' => $data['ar_code']]);
        $profile->fill([
            'ar_name' => $data['ar_name'],
            'credit_limit' => $data['credit_limit'],
            'credit_term' => $data['credit_term'],
            'updated_at' => now(),
        ]);
        if (array_key_exists('credit_outstanding', $data) || ! $profile->exists) {
            $profile->credit_outstanding = (float) ($data['credit_outstanding'] ?? 0);
        }
        $profile->save();

        return response()->json(['data' => $profile], $profile->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(CreditProfile $creditProfile): JsonResponse
    {
        $creditProfile->delete();

        return response()->json(status: 204);
    }
}
