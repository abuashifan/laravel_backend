<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;

class PurchaseAccountResolverService
{
    public const PAYABLE_MAPPING_MESSAGE = 'Akun Hutang Usaha belum diatur. Buka Pengaturan > Pemetaan Akun > Purchase > Hutang Usaha.';
    public const PURCHASE_EXPENSE_MAPPING_MESSAGE = 'Akun Pembelian/Beban belum diatur. Buka Pengaturan > Pemetaan Akun > Purchase > Beban Pembelian atau atur Akun Pembelian di master data produk.';
    public const INVENTORY_MAPPING_MESSAGE = 'Akun Persediaan belum diatur. Buka Pengaturan > Pemetaan Akun > Inventory > Persediaan atau atur Akun Persediaan di master data produk.';
    public const INVENTORY_INTERIM_MAPPING_MESSAGE = 'Akun Inventory Interim/GRNI belum diatur. Buka Pengaturan > Pemetaan Akun > Purchase > Inventory Interim sebelum menerima barang persediaan.';
    public const FIXED_ASSET_CLEARING_MAPPING_MESSAGE = 'Akun Fixed Asset Clearing belum diatur. Buka Pengaturan > Pemetaan Akun > Fixed Assets > Clearing.';

    public function resolveBillPayableAccountId(VendorBill $bill): int
    {
        if ($bill->ap_account_id) {
            return $this->existingSnapshotAccountId((int) $bill->ap_account_id, ['liability'], self::PAYABLE_MAPPING_MESSAGE);
        }

        $vendor = $bill->relationLoaded('vendor')
            ? $bill->vendor
            : Contact::query()->find($bill->vendor_id);

        if (! $vendor) {
            throw ApiException::make('VENDOR_NOT_FOUND', 'Vendor not found.', 422);
        }

        $accountId = $this->getPayableAccountId($vendor);
        $bill->ap_account_id = $accountId;
        $bill->save();

        return $accountId;
    }

    public function getPayableAccountId(Contact $vendor): int
    {
        foreach (['purchase.accounts_payable', 'purchase.payable'] as $mappingKey) {
            $accountId = $this->mappingAccountId($mappingKey, ['liability']);
            if ($accountId !== null) {
                return $accountId;
            }
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::PAYABLE_MAPPING_MESSAGE, 422);
    }

    public function getPurchaseExpenseAccountIdForLine(array|VendorBillLine $line): int
    {
        $accountId = $this->tryPurchaseExpenseAccountIdForLine($line);
        if ($accountId !== null) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::PURCHASE_EXPENSE_MAPPING_MESSAGE, 422);
    }

    public function tryPurchaseExpenseAccountIdForLine(array|VendorBillLine $line): ?int
    {
        $snapshotAccountId = $line instanceof VendorBillLine
            ? $line->expense_account_id
            : ($line['expense_account_id'] ?? null);

        if ($snapshotAccountId && $this->activeAccountExists((int) $snapshotAccountId, ['asset', 'expense'])) {
            return (int) $snapshotAccountId;
        }

        $product = $this->lineProduct($line);
        if ($product?->purchase_account_id && $this->activeAccountExists((int) $product->purchase_account_id, ['expense'])) {
            return (int) $product->purchase_account_id;
        }

        foreach (['purchase.expense', 'purchase.default_purchase'] as $mappingKey) {
            $accountId = $this->mappingAccountId($mappingKey, ['asset', 'expense']);
            if ($accountId !== null) {
                return $accountId;
            }
        }

        return null;
    }

    public function getInventoryAccountIdForLine(array|VendorBillLine $line): int
    {
        $product = $this->lineProduct($line);
        if ($product?->inventory_account_id && $this->activeAccountExists((int) $product->inventory_account_id, ['asset'])) {
            return (int) $product->inventory_account_id;
        }

        $accountId = $this->mappingAccountId('inventory.asset', ['asset']);
        if ($accountId !== null) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::INVENTORY_MAPPING_MESSAGE, 422);
    }

    public function getInventoryInterimAccountId(): int
    {
        $accountId = $this->mappingAccountId('purchase.inventory_interim', ['liability']);
        if ($accountId !== null) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::INVENTORY_INTERIM_MAPPING_MESSAGE, 422);
    }

    public function getFixedAssetClearingAccountId(): int
    {
        $accountId = $this->mappingAccountId('fixed_assets.clearing', ['asset']);
        if ($accountId !== null) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::FIXED_ASSET_CLEARING_MAPPING_MESSAGE, 422);
    }

    public function lineIsStockItem(array|VendorBillLine $line): bool
    {
        return (bool) $this->lineProduct($line)?->is_stock_item;
    }

    private function existingSnapshotAccountId(int $accountId, array $accountTypes, string $message): int
    {
        if ($this->activeAccountExists($accountId, $accountTypes)) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422);
    }

    private function lineProduct(array|VendorBillLine $line): ?Product
    {
        if ($line instanceof VendorBillLine && $line->relationLoaded('product')) {
            return $line->product;
        }

        $productId = $line instanceof VendorBillLine
            ? $line->product_id
            : ($line['product_id'] ?? null);

        return $productId ? Product::query()->find((int) $productId) : null;
    }

    private function mappingAccountId(string $mappingKey, array $accountTypes): ?int
    {
        $mapping = AccountMapping::query()
            ->where('mapping_key', $mappingKey)
            ->where('is_active', true)
            ->first();

        if (! $mapping?->account_id) {
            return null;
        }

        $accountId = (int) $mapping->account_id;

        return $this->activeAccountExists($accountId, $accountTypes) ? $accountId : null;
    }

    private function activeAccountExists(int $accountId, array $accountTypes): bool
    {
        return ChartOfAccount::query()
            ->whereKey($accountId)
            ->whereIn('account_type', $accountTypes)
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->exists();
    }
}
