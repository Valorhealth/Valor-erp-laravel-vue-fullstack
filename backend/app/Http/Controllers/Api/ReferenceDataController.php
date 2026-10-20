<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditProfile;
use App\Models\Customer;
use App\Models\MasterProduct;
use App\Models\Salesman;
use App\Models\ProductUnit;
use App\Services\Reference\ThaiLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReferenceDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $load = fn (): array => [
            'customers' => Customer::query()
                ->select(['id', 'ar_code', 'ar_name', 'tax_id', 'billing_address', 'delivery_address', 'province', 'district', 'sub_district', 'zipcode'])
                ->with(['addresses' => fn ($addresses) => $addresses->where('is_active', true)])
                ->when($search !== '', fn ($query) => $query->where('ar_code', 'ilike', "%{$search}%")->orWhere('ar_name', 'ilike', "%{$search}%"))
                ->orderBy('ar_code')->limit(100)->get()->toArray(),
            'products' => MasterProduct::query()->select(['sku_code', 'sku_name', 'sku_e_name'])->with('units:unit_id,unit_name')->orderBy('sku_code')->get()->toArray(),
            'salesmen' => Salesman::query()->select(['salesman_code', 'salesman_name'])->orderBy('salesman_code')->get()->toArray(),
            'credit_profiles' => CreditProfile::query()->select(['ar_code', 'ar_name', 'credit_limit', 'credit_outstanding', 'credit_term'])->orderBy('ar_code')->get()->toArray(),
            'units' => ProductUnit::query()->orderBy('unit_name')->pluck('unit_name')->all(),
            'shipping_types' => ['ส่งปกติ', 'ส่งเร็ว', 'ส่งด่วน'],
        ];

        return response()->json(['data' => $search === ''
            ? Cache::remember('reference-data:v5', now()->addMinutes(5), $load)
            : $load()]);
    }

    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($data['search'] ?? ''));

        return response()->json(['data' => Customer::query()
            ->select(['id', 'ar_code', 'ar_name', 'tax_id', 'billing_address', 'delivery_address', 'province', 'district', 'sub_district', 'zipcode'])
            ->with(['addresses' => fn ($addresses) => $addresses->where('is_active', true)])
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($items) => $items
                    ->where('ar_code', 'ilike', "%{$search}%")
                    ->orWhere('ar_name', 'ilike', "%{$search}%")))
            ->orderByRaw('CASE WHEN ar_code = ? THEN 0 WHEN ar_code ILIKE ? THEN 1 ELSE 2 END', [$search, "{$search}%"])
            ->orderBy('ar_code')
            ->limit(80)
            ->get()]);
    }

    public function locations(ThaiLocationService $locations): JsonResponse
    {
        return response()->json(['data' => $locations->all()]);
    }
}
