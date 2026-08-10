<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditProfile;
use App\Models\Customer;
use App\Models\MasterProduct;
use App\Models\Salesman;
use App\Services\Reference\ThaiLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(['data' => [
            'customers' => Customer::query()
                ->select(['ar_code', 'ar_name', 'tax_id'])
                ->when($search !== '', fn ($query) => $query->where('ar_code', 'ilike', "%{$search}%")->orWhere('ar_name', 'ilike', "%{$search}%"))
                ->orderBy('ar_code')->get(),
            'products' => MasterProduct::query()->select(['sku_code', 'sku_name', 'sku_e_name'])->orderBy('sku_code')->get(),
            'salesmen' => Salesman::query()->select(['salesman_code', 'salesman_name'])->orderBy('salesman_code')->get(),
            'credit_profiles' => CreditProfile::query()->select(['ar_code', 'ar_name', 'credit_limit', 'credit_outstanding', 'credit_term'])->orderBy('ar_code')->get(),
            'units' => ['ชิ้น', 'กล่อง/30ชิ้น', 'กล่องใหญ่/60ชิ้น'],
            'shipping_types' => ['ส่งปกติ', 'ส่งเร็ว', 'ส่งด่วน'],
        ]]);
    }

    public function locations(ThaiLocationService $locations): JsonResponse
    {
        return response()->json(['data' => $locations->all()]);
    }
}
