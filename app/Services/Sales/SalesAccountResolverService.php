<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesInvoiceLine;

class SalesAccountResolverService
{
    public const RECEIVABLE_MAPPING_MESSAGE = 'Akun Piutang Usaha belum diatur. Buka Pengaturan > Pemetaan Akun > Sales > Piutang Usaha atau atur Akun Piutang khusus di master data pelanggan.';
    public const REVENUE_MAPPING_MESSAGE = 'Akun Pendapatan Penjualan belum diatur. Buka Pengaturan > Pemetaan Akun > Sales > Pendapatan Penjualan atau atur Akun Penjualan di master data produk.';

    /**
     * Resolve and snapshot the AR account used by a sales invoice.
     */
    public function resolveInvoiceReceivableAccountId(SalesInvoice $invoice): int
    {
        if ($invoice->ar_account_id) {
            return $this->existingSnapshotAccountId((int) $invoice->ar_account_id, 'asset', self::RECEIVABLE_MAPPING_MESSAGE);
        }

        $customer = $invoice->relationLoaded('customer')
            ? $invoice->customer
            : Contact::query()->find($invoice->customer_id);

        if (! $customer) {
            throw ApiException::make('CUSTOMER_NOT_FOUND', 'Customer not found.', 422);
        }

        $accountId = $this->getReceivableAccountId($customer);
        $invoice->ar_account_id = $accountId;
        $invoice->save();

        return $accountId;
    }

    public function getReceivableAccountId(Contact $customer): int
    {
        if ($customer->receivable_account_id && $this->activeAccountExists((int) $customer->receivable_account_id, 'asset')) {
            return (int) $customer->receivable_account_id;
        }

        foreach (['sales.accounts_receivable', 'accounts_receivable'] as $mappingKey) {
            $accountId = $this->mappingAccountId($mappingKey, 'asset');
            if ($accountId !== null) {
                return $accountId;
            }
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::RECEIVABLE_MAPPING_MESSAGE, 422);
    }

    public function getRevenueAccountIdForLine(array|SalesInvoiceLine $line): int
    {
        $accountId = $this->tryRevenueAccountIdForLine($line);
        if ($accountId !== null) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', self::REVENUE_MAPPING_MESSAGE, 422);
    }

    public function tryRevenueAccountIdForLine(array|SalesInvoiceLine $line): ?int
    {
        $snapshotAccountId = $line instanceof SalesInvoiceLine
            ? $line->revenue_account_id
            : ($line['revenue_account_id'] ?? null);

        if ($snapshotAccountId && $this->activeAccountExists((int) $snapshotAccountId, 'revenue')) {
            return (int) $snapshotAccountId;
        }

        $productId = $line instanceof SalesInvoiceLine
            ? $line->product_id
            : ($line['product_id'] ?? null);

        if ($productId) {
            $product = Product::query()->find((int) $productId);
            if ($product?->sales_account_id && $this->activeAccountExists((int) $product->sales_account_id, 'revenue')) {
                return (int) $product->sales_account_id;
            }
        }

        return $this->mappingAccountId('sales.revenue', 'revenue');
    }

    private function existingSnapshotAccountId(int $accountId, string $accountType, string $message): int
    {
        if ($this->activeAccountExists($accountId, $accountType)) {
            return $accountId;
        }

        throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422);
    }

    private function mappingAccountId(string $mappingKey, string $accountType): ?int
    {
        $mapping = AccountMapping::query()
            ->where('mapping_key', $mappingKey)
            ->where('is_active', true)
            ->first();

        if (! $mapping?->account_id) {
            return null;
        }

        $accountId = (int) $mapping->account_id;

        return $this->activeAccountExists($accountId, $accountType) ? $accountId : null;
    }

    private function activeAccountExists(int $accountId, string $accountType): bool
    {
        return ChartOfAccount::query()
            ->whereKey($accountId)
            ->where('account_type', $accountType)
            ->where('is_active', true)
            ->exists();
    }
}
