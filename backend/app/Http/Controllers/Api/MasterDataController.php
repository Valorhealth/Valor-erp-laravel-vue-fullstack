<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MasterProduct;
use App\Models\ProductUnit;
use App\Models\Salesman;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(Customer::query()
            ->select(['id', 'ar_code', 'ar_name', 'tax_id', 'billing_address', 'delivery_address', 'province', 'district', 'sub_district', 'zipcode', 'created_at'])
            ->with('addresses')
            ->when($search !== '', fn ($query) => $query->where('ar_code', 'ilike', "%{$search}%")->orWhere('ar_name', 'ilike', "%{$search}%"))
            ->orderBy('ar_code')->paginate(min(max($request->integer('per_page', 25), 5), 100)));
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ar_code' => ['required', 'string', 'max:255', Rule::unique('customers', 'ar_code')],
            'ar_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'billing_address' => ['nullable', 'string', 'max:5000'],
            'delivery_address' => ['nullable', 'string', 'max:5000'],
            'province' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'sub_district' => ['nullable', 'string', 'max:255'],
            'zipcode' => ['nullable', 'string', 'max:20'],
        ]);
        $customer = Customer::query()->create([...$data, 'created_at' => now()]);
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $customer], 201);
    }

    public function destroyCustomer(Customer $customer): JsonResponse
    {
        $customer->delete();
        $this->forgetReferenceDataCache();

        return response()->json(status: 204);
    }

    public function storeCustomerAddress(Request $request, Customer $customer): JsonResponse
    {
        $data = $this->validateCustomerAddress($request, $customer);
        $address = DB::transaction(function () use ($customer, $data): CustomerAddress {
            $this->clearAddressDefaults($customer, $data);

            return $customer->addresses()->create($data);
        });
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $address], 201);
    }

    public function updateCustomerAddress(Request $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
        abort_unless((int) $address->customer_id === (int) $customer->id, 404);
        $data = $this->validateCustomerAddress($request, $customer, $address);
        DB::transaction(function () use ($customer, $address, $data): void {
            $this->clearAddressDefaults($customer, $data, $address->address_id);
            $address->update($data);
        });
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $address->fresh()]);
    }

    public function destroyCustomerAddress(Customer $customer, CustomerAddress $address): JsonResponse
    {
        abort_unless((int) $address->customer_id === (int) $customer->id, 404);
        $address->update([
            'is_active' => false,
            'is_default_billing' => false,
            'is_default_delivery' => false,
        ]);
        $this->forgetReferenceDataCache();

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
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $salesman], 201);
    }

    public function destroySalesman(Salesman $salesman): JsonResponse
    {
        $salesman->delete();
        $this->forgetReferenceDataCache();

        return response()->json(status: 204);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_code' => ['required', 'string', 'max:255', Rule::unique('master_products', 'sku_code')],
            'sku_name' => ['required', 'string', 'max:255'],
            'sku_e_name' => ['nullable', 'string', 'max:255'],
        ]);
        $product = DB::transaction(function () use ($data): MasterProduct {
            $product = MasterProduct::query()->create(collect($data)->only(['sku_code', 'sku_name', 'sku_e_name'])->all());
            $pieceId = ProductUnit::query()->where('unit_name', 'ชิ้น')->value('unit_id');
            if ($pieceId) {
                $product->units()->syncWithoutDetaching([$pieceId => ['is_default' => true]]);
            }

            return $product;
        });
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $product], 201);
    }

    public function updateProduct(Request $request, MasterProduct $product): JsonResponse
    {
        $data = $request->validate([
            'sku_name' => ['required', 'string', 'max:255'],
            'sku_e_name' => ['nullable', 'string', 'max:255'],
        ]);
        $product->update(collect($data)->only(['sku_name', 'sku_e_name'])->all());
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $product->fresh()]);
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

        $unit = ProductUnit::query()->create($data);
        $this->forgetReferenceDataCache();

        return response()->json(['data' => $unit], 201);
    }

    public function destroyUnit(ProductUnit $unit): JsonResponse
    {
        if ($unit->unit_name === 'ชิ้น') {
            throw ValidationException::withMessages(['unit' => 'หน่วย “ชิ้น” เป็นหน่วยเริ่มต้นของระบบ จึงไม่สามารถลบได้']);
        }
        $usedHistorically = DB::table('bill_items')->where('unit_type', $unit->unit_name)->exists()
            || DB::table('quotation_items')->where('unit_type', $unit->unit_name)->exists();
        if ($usedHistorically) {
            throw ValidationException::withMessages(['unit' => 'หน่วยนี้ถูกใช้ในเอกสารแล้ว จึงลบไม่ได้ กรุณาถอดออกจากสินค้าที่ไม่ใช้แทน']);
        }
        DB::transaction(function () use ($unit): void {
            $unit->products()->detach();
            $unit->delete();
        });
        $this->forgetReferenceDataCache();

        return response()->json(status: 204);
    }

    private function forgetReferenceDataCache(): void
    {
        Cache::forget('reference-data:v3');
        Cache::forget('reference-data:v4');
        Cache::forget('reference-data:v5');
    }

    /** @return array<string, mixed> */
    private function validateCustomerAddress(Request $request, Customer $customer, ?CustomerAddress $address = null): array
    {
        return $request->validate([
            'bplus_address_code' => ['nullable', 'string', 'max:255', Rule::unique('customer_addresses', 'bplus_address_code')->where('customer_id', $customer->id)->ignore($address?->address_id, 'address_id')],
            'address_name' => ['required', 'string', 'max:500'],
            'address_type' => ['required', Rule::in(['billing', 'delivery', 'both'])],
            'full_address' => ['required', 'string', 'max:5000'],
            'province' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'sub_district' => ['nullable', 'string', 'max:255'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'tax_branch_code' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'is_default_billing' => ['boolean'],
            'is_default_delivery' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function clearAddressDefaults(Customer $customer, array $data, ?int $except = null): void
    {
        $query = $customer->addresses()->when($except, fn ($items) => $items->whereKeyNot($except));
        if ($data['is_default_billing'] ?? false) {
            (clone $query)->update(['is_default_billing' => false]);
        }
        if ($data['is_default_delivery'] ?? false) {
            (clone $query)->update(['is_default_delivery' => false]);
        }
    }
}
