<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MasterProduct;
use App\Models\ProductUnit;
use App\Models\Salesman;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'unit_ids' => ['nullable', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:product_units,unit_id'],
            'default_unit_id' => ['nullable', 'integer', 'exists:product_units,unit_id'],
        ]);
        $product = DB::transaction(function () use ($data): MasterProduct {
            $unitIds = collect($data['unit_ids'] ?? [ProductUnit::query()->where('unit_name', 'ชิ้น')->value('unit_id')])->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $defaultUnitId = (int) ($data['default_unit_id'] ?? $unitIds->first());
            if (! $unitIds->contains($defaultUnitId)) {
                throw ValidationException::withMessages(['default_unit_id' => 'หน่วยเริ่มต้นต้องอยู่ในรายการหน่วยของสินค้านี้']);
            }
            $product = MasterProduct::query()->create(collect($data)->only(['sku_code', 'sku_name', 'sku_e_name'])->all());
            $product->units()->sync($unitIds->mapWithKeys(fn (int $id): array => [$id => ['is_default' => $id === $defaultUnitId]])->all());

            return $product->load('units');
        });

        return response()->json(['data' => $product], 201);
    }

    public function updateProduct(Request $request, MasterProduct $product): JsonResponse
    {
        $data = $request->validate([
            'sku_name' => ['required', 'string', 'max:255'],
            'sku_e_name' => ['nullable', 'string', 'max:255'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:product_units,unit_id'],
            'default_unit_id' => ['required', 'integer', 'exists:product_units,unit_id'],
        ]);
        $unitIds = collect($data['unit_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $defaultUnitId = (int) $data['default_unit_id'];
        if (! $unitIds->contains($defaultUnitId)) {
            throw ValidationException::withMessages(['default_unit_id' => 'หน่วยเริ่มต้นต้องอยู่ในรายการหน่วยของสินค้านี้']);
        }

        DB::transaction(function () use ($product, $data, $unitIds, $defaultUnitId): void {
            $product->update(collect($data)->only(['sku_name', 'sku_e_name'])->all());
            $product->units()->sync($unitIds->mapWithKeys(fn (int $id): array => [$id => ['is_default' => $id === $defaultUnitId]])->all());
        });

        return response()->json(['data' => $product->fresh()->load('units')]);
    }

    public function units(): JsonResponse
    {
        return response()->json(['data' => ProductUnit::query()->withCount('products')->orderBy('unit_name')->get()]);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $request->merge(['unit_name' => trim((string) $request->input('unit_name'))]);
        $data = $request->validate([
            'unit_name' => ['required', 'string', 'max:100', Rule::unique('product_units', 'unit_name')],
        ]);

        return response()->json(['data' => ProductUnit::query()->create($data)], 201);
    }

    public function destroyUnit(ProductUnit $unit): JsonResponse
    {
        if ($unit->products()->exists()) {
            throw ValidationException::withMessages(['unit' => 'หน่วยนี้ยังผูกกับสินค้าอยู่ กรุณาถอดหน่วยออกจากสินค้าทุกชิ้นก่อนลบ']);
        }
        $usedHistorically = DB::table('bill_items')->where('unit_type', $unit->unit_name)->exists()
            || DB::table('quotation_items')->where('unit_type', $unit->unit_name)->exists();
        if ($usedHistorically) {
            throw ValidationException::withMessages(['unit' => 'หน่วยนี้ถูกใช้ในเอกสารแล้ว จึงลบไม่ได้ กรุณาถอดออกจากสินค้าที่ไม่ใช้แทน']);
        }
        $unit->delete();

        return response()->json(status: 204);
    }
}
