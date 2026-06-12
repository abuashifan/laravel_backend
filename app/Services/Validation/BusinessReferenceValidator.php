<?php

namespace App\Services\Validation;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Department;
use App\Models\Tenant\PaymentTerm;
use App\Models\Tenant\Product;
use App\Models\Tenant\Project;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;

class BusinessReferenceValidator
{
    public function customer(int $customerId): Contact
    {
        $contact = Contact::query()->find($customerId);
        if (! $contact || ! $contact->isActive() || ! $contact->isCustomer()) {
            throw ApiException::make('CUSTOMER_NOT_VALID', 'Customer is inactive or not marked as customer.', 422);
        }

        return $contact;
    }

    public function vendor(int $vendorId): Contact
    {
        $contact = Contact::query()->find($vendorId);
        if (! $contact || ! $contact->isActive() || ! $contact->isSupplier()) {
            throw ApiException::make('VENDOR_NOT_VALID', 'Vendor is inactive or not marked as supplier.', 422);
        }

        return $contact;
    }

    public function product(int $productId, bool $requireStockable = false): Product
    {
        $product = Product::query()->find($productId);
        if (! $product || ! $product->isActive()) {
            throw ApiException::make('PRODUCT_NOT_VALID', 'Product is inactive or not found.', 422);
        }

        if ($requireStockable && ! $product->isStockItem()) {
            throw ApiException::make('PRODUCT_NOT_STOCKABLE', 'Product is not stockable, stock movement cannot be posted.', 422);
        }

        return $product;
    }

    public function unit(?int $unitId, ?float $quantity = null): ?Unit
    {
        if (! $unitId) {
            return null;
        }

        $unit = Unit::query()->find($unitId);
        if (! $unit || ! (bool) $unit->is_active) {
            throw ApiException::make('UNIT_NOT_VALID', 'Unit is inactive or not found.', 422);
        }

        if ($quantity !== null) {
            $this->assertUnitPrecision($quantity, (int) $unit->precision);
        }

        return $unit;
    }

    public function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()->find($warehouseId);
        if (! $warehouse || ! $warehouse->isActive()) {
            throw ApiException::make('WAREHOUSE_NOT_VALID', 'Warehouse is inactive or not found.', 422);
        }

        return $warehouse;
    }

    public function account(int $accountId, ?array $accountTypes = null, bool $requirePostable = true): ChartOfAccount
    {
        $account = ChartOfAccount::query()->find($accountId);
        if (! $account || ! $account->isActive()) {
            throw ApiException::make('ACCOUNT_NOT_VALID', 'Account is inactive or not found.', 422);
        }

        if ($accountTypes !== null && ! in_array((string) $account->account_type, $accountTypes, true)) {
            throw ApiException::make('ACCOUNT_TYPE_NOT_VALID', 'Account type is not valid for this transaction.', 422);
        }

        if ($requirePostable && ChartOfAccount::query()->where('parent_account_id', $account->id)->exists()) {
            throw ApiException::make('ACCOUNT_NOT_POSTABLE', 'Account is not postable.', 422);
        }

        return $account;
    }

    public function accountMapping(string $key, ?array $accountTypes = null): int
    {
        $mapping = AccountMapping::query()
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->first();

        if (! $mapping?->account_id) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping '.$key.' is missing.', 422);
        }

        $this->account((int) $mapping->account_id, $accountTypes);

        return (int) $mapping->account_id;
    }

    public function paymentTerm(?int $paymentTermId): ?PaymentTerm
    {
        if (! $paymentTermId) {
            return null;
        }

        $term = PaymentTerm::query()->find($paymentTermId);
        if (! $term || ! (bool) $term->is_active) {
            throw ApiException::make('PAYMENT_TERM_NOT_VALID', 'Payment term is inactive or not found.', 422);
        }

        return $term;
    }

    public function department(?int $departmentId): ?Department
    {
        if (! $departmentId) {
            return null;
        }

        $department = Department::query()->find($departmentId);
        if (! $department || ! $department->isActive()) {
            throw ApiException::make('DEPARTMENT_NOT_VALID', 'Department is inactive or not found.', 422);
        }

        return $department;
    }

    public function project(?int $projectId): ?Project
    {
        if (! $projectId) {
            return null;
        }

        $project = Project::query()->find($projectId);
        if (! $project || ! $project->isUsable()) {
            throw ApiException::make('PROJECT_NOT_VALID', 'Project is inactive or closed.', 422);
        }

        return $project;
    }

    public function stockMovementLine(array $line, bool $requireWarehouse = true): Product
    {
        if (empty($line['product_id'])) {
            throw ApiException::make('PRODUCT_REQUIRED', 'Product is required for stock movement.', 422);
        }

        $product = $this->product((int) $line['product_id'], true);

        if ($requireWarehouse && empty($line['warehouse_id'])) {
            throw ApiException::make('WAREHOUSE_REQUIRED', 'Warehouse is required for stock item.', 422);
        }
        if (! empty($line['warehouse_id'])) {
            $this->warehouse((int) $line['warehouse_id']);
        }

        $quantity = (float) ($line['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw ApiException::make('QUANTITY_NOT_VALID', 'Quantity must be greater than zero.', 422);
        }

        $unitId = $line['unit_id'] ?? $product->unit_id;
        if (! $unitId) {
            throw ApiException::make('UNIT_REQUIRED', 'Unit is required for stock item.', 422);
        }

        $this->unit((int) $unitId, $quantity);
        $this->department(isset($line['department_id']) ? (int) $line['department_id'] : null);
        $this->project(isset($line['project_id']) ? (int) $line['project_id'] : null);

        return $product;
    }

    public function transactionalLine(array $line): ?Product
    {
        $product = null;
        if (! empty($line['product_id'])) {
            $product = $this->product((int) $line['product_id']);
        }

        $quantity = (float) ($line['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw ApiException::make('QUANTITY_NOT_VALID', 'Quantity must be greater than zero.', 422);
        }

        $unitId = $line['unit_id'] ?? $product?->unit_id;
        if ($unitId) {
            $this->unit((int) $unitId, $quantity);
        }

        if (! empty($line['warehouse_id'])) {
            $this->warehouse((int) $line['warehouse_id']);
        }

        $this->department(isset($line['department_id']) ? (int) $line['department_id'] : null);
        $this->project(isset($line['project_id']) ? (int) $line['project_id'] : null);

        return $product;
    }

    public function requireWarehouseForStockLine(array $line): void
    {
        if (empty($line['product_id'])) {
            return;
        }

        $product = $this->product((int) $line['product_id']);
        if (! $product->isStockItem()) {
            return;
        }

        if (empty($line['warehouse_id'])) {
            throw ApiException::make('WAREHOUSE_REQUIRED', 'Warehouse is required for stock item.', 422);
        }

        $this->warehouse((int) $line['warehouse_id']);
    }

    private function assertUnitPrecision(float $quantity, int $precision): void
    {
        if ($precision < 0) {
            return;
        }

        $factor = 10 ** $precision;
        if (abs($quantity * $factor - round($quantity * $factor)) > 0.000001) {
            throw ApiException::make('UNIT_PRECISION_INVALID', 'Quantity does not match unit precision.', 422);
        }
    }
}
