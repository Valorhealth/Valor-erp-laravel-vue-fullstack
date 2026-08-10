<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MasterProduct;
use App\Models\Salesman;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(Customer::query()
            ->select(['id', 'ar_code', 'ar_name', 'tax_id', 'created_at'])
            ->when($search !== '', fn ($query) => $query->where('ar_code', 'ilike', "%{$search}%")->orWhere('ar_name', 'ilike', "%{$search}%"))
            ->orderBy('ar_code')->paginate(min(max($request->integer('per_page', 25), 5), 100)));
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ar_code' => ['required', 'string', 'max:255', Rule::unique('customers', 'ar_code')],
            'ar_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
        ]);
        $customer = Customer::query()->create([...$data, 'created_at' => now()]);

        return response()->json(['data' => $customer], 201);
    }

    public function destroyCustomer(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(status: 204);
    }

    public function salesmen(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(Salesman::query()
            ->select(['id', 'salesman_code', 'salesman_name', 'created_at'])
            ->when($search !== '', fn ($query) => $query->where('salesman_code', 'ilike', "%{$search}%")->orWhere('salesman_name', 'ilike', "%{$search}%"))
            ->orderBy('salesman_code')->paginate(min(max($request->integer('per_page', 25), 5), 100)));
    }

    public function storeSalesman(Request $request): JsonResponse
    {
        $data = $request->validate([
            'salesman_code' => ['required', 'string', 'max:255', Rule::unique('salesmen', 'salesman_code')],
            'salesman_name' => ['required', 'string', 'max:1000'],
        ]);
        $salesman = Salesman::query()->create([...$data, 'created_at' => now()]);

        return response()->json(['data' => $salesman], 201);
    }

    public function destroySalesman(Salesman $salesman): JsonResponse
    {
        $salesman->delete();

        return response()->json(status: 204);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_code' => ['required', 'string', 'max:255', Rule::unique('master_products', 'sku_code')],
            'sku_name' => ['required', 'string', 'max:255'],
            'sku_e_name' => ['nullable', 'string', 'max:255'],
        ]);
        $product = MasterProduct::query()->create($data);

        return response()->json(['data' => $product], 201);
    }
}
