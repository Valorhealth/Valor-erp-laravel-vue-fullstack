<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MasterProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);

        $customers = Customer::query()
            ->select(['id', 'ar_code', 'ar_name', 'tax_id'])
            ->when($search !== '', fn ($query) => $query->where(
                fn ($nested) => $nested
                    ->where('ar_code', 'ilike', "%{$search}%")
                    ->orWhere('ar_name', 'ilike', "%{$search}%")
            ))
            ->orderBy('ar_code')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($customers);
    }

    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);

        $products = MasterProduct::query()
            ->select(['sku_code', 'sku_name', 'sku_e_name'])
            ->with('units:unit_id,unit_name')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($nested) => $nested
                    ->where('sku_code', 'ilike', "%{$search}%")
                    ->orWhere('sku_name', 'ilike', "%{$search}%")
                    ->orWhere('sku_e_name', 'ilike', "%{$search}%")
            ))
            ->orderBy('sku_code')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($products);
    }
}
