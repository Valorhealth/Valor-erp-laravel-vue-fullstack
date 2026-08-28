<?php

namespace App\Services\Documents;

use App\Models\Customer;
use App\Models\MasterProduct;

final class PurchaseOrderMatcher
{
    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function enrich(array $result): array
    {
        $products = MasterProduct::query()->select(['sku_code', 'sku_name', 'sku_e_name'])->get();
        $items = collect($result['items'] ?? [])->map(function (array $item) use ($products): array {
            $source = trim(($item['source_code'] ?? '').' '.($item['source_name'] ?? ''));
            $ranked = $products->map(fn (MasterProduct $product): array => [
                'product' => $product,
                'score' => $this->productScore($source, $product),
            ])->sortByDesc('score')->first();
            $score = round((float) ($ranked['score'] ?? 0), 3);
            $product = $ranked['product'] ?? null;
            $matched = $product && $score >= 0.72;

            return [
                ...$item,
                'matched_sku' => $matched ? $product->sku_code : null,
                'matched_name' => $matched ? $product->sku_name : null,
                'suggested_sku' => ! $matched && $product && $score >= 0.52 ? $product->sku_code : null,
                'suggested_name' => ! $matched && $product && $score >= 0.52 ? $product->sku_name : null,
                'match_confidence' => $score,
                'match_status' => $matched ? ($score >= 0.9 ? 'matched' : 'review') : 'unmatched',
            ];
        })->values()->all();

        $customer = $this->matchCustomer((string) ($result['buyer_name'] ?? ''), (string) ($result['tax_id'] ?? ''));

        return [
            ...$result,
            'items' => $items,
            'matched_customer_code' => $customer['customer']?->ar_code,
            'matched_customer_name' => $customer['customer']?->ar_name,
            'customer_match_confidence' => round($customer['score'], 3),
        ];
    }

    private function productScore(string $source, MasterProduct $product): float
    {
        $source = $this->normalize($source);
        $code = $this->normalize((string) $product->sku_code);
        $thai = $this->normalize((string) $product->sku_name);
        $english = $this->normalize((string) $product->sku_e_name);
        if ($source === '' || ($code === '' && $thai === '' && $english === '')) return 0;
        if ($code !== '' && ($source === $code || str_contains($source, $code))) return 1;
        if (($thai !== '' && $source === $thai) || ($english !== '' && $source === $english)) return 0.97;

        return max($this->similarity($source, $thai), $this->similarity($source, $english));
    }

    /** @return array{customer: Customer|null, score: float} */
    private function matchCustomer(string $name, string $taxId): array
    {
        $taxDigits = preg_replace('/\D+/', '', $taxId);
        if ($taxDigits !== '') {
            $exact = Customer::query()->whereRaw("REGEXP_REPLACE(COALESCE(tax_id, ''), '[^0-9]', '', 'g') = ?", [$taxDigits])->first();
            if ($exact) return ['customer' => $exact, 'score' => 1.0];
        }
        $source = $this->normalize($name);
        $ranked = Customer::query()->select(['ar_code', 'ar_name', 'tax_id'])->get()
            ->map(fn (Customer $customer): array => ['customer' => $customer, 'score' => $this->similarity($source, $this->normalize((string) $customer->ar_name))])
            ->sortByDesc('score')->first();

        return ($ranked['score'] ?? 0) >= 0.72 ? $ranked : ['customer' => null, 'score' => (float) ($ranked['score'] ?? 0)];
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') return 0;
        similar_text($left, $right, $percent);
        $contains = str_contains($left, $right) || str_contains($right, $left) ? 0.88 : 0;

        return max($percent / 100, $contains);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($value))) ?? '';
    }
}
