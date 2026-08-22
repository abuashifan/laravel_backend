<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\Concerns\ResolvesModelByCodeOrName;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Services\VendorBillService;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Profil impor tagihan pembelian — Fase 4.
 *
 * Cerminan dari SalesInvoiceImportCommitter dengan perbedaan:
 * - Vendor (pemasok) sebagai kontak, bukan customer
 * - VendorBillService::create() sebagai jalur penulisan
 * - unit_cost sebagai harga, bukan unit_price
 * - Stok masuk (ditangani service), bukan keluar
 */
class VendorBillImportCommitter implements ImportProfileCommitter
{
    use Concerns\NormalizesImportDates;
    use ResolvesModelByCodeOrName;

    public function __construct(private readonly VendorBillService $billService) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $ref = trim((string) ($normalized['ref'] ?? ''));
        $billDate = trim((string) ($normalized['bill_date'] ?? ''));
        $vendorValue = trim((string) ($normalized['vendor'] ?? ''));
        $itemValue = trim((string) ($normalized['item'] ?? ''));
        $quantity = trim((string) ($normalized['quantity'] ?? ''));
        $unitCost = trim((string) ($normalized['unit_cost'] ?? ''));
        $dueDate = trim((string) ($normalized['due_date'] ?? ''));

        if ($ref === '') {
            $errors['ref'][] = 'Ref wajib diisi.';
        }

        $parsedDate = null;
        if ($billDate === '') {
            $errors['bill_date'][] = 'Bill Date wajib diisi.';
        } else {
            $parsedDate = $this->parseImportDate($billDate);
            if ($parsedDate === null) {
                $errors['bill_date'][] = 'Bill Date harus dalam format DD/MM/YYYY dan tanggal valid.';
            }
        }

        $vendor = null;
        if ($vendorValue === '') {
            $errors['vendor'][] = 'Vendor wajib diisi.';
        } else {
            $vendor = $this->findContactByCodeOrName($vendorValue);

            if (! $vendor instanceof Contact) {
                $errors['vendor'][] = "Pemasok '{$vendorValue}' tidak ditemukan.";
            } elseif (! $vendor->isSupplier()) {
                $errors['vendor'][] = "'{$vendorValue}' bukan pemasok (tipe: {$vendor->contact_type}).";
            } elseif (! $vendor->isActive()) {
                $errors['vendor'][] = "Pemasok '{$vendorValue}' sudah tidak aktif.";
            }
        }

        $product = null;
        if ($itemValue === '') {
            $errors['item'][] = 'Item wajib diisi.';
        } else {
            $product = $this->findProductByCodeOrName($itemValue);

            if (! $product instanceof Product) {
                $errors['item'][] = "Produk '{$itemValue}' tidak ditemukan.";
            }
        }

        if ($quantity === '') {
            $errors['quantity'][] = 'Quantity wajib diisi.';
        } elseif (! is_numeric($quantity)) {
            $errors['quantity'][] = 'Quantity harus berupa angka.';
        } elseif ((float) $quantity <= 0) {
            $errors['quantity'][] = 'Quantity harus lebih dari 0.';
        }

        if ($unitCost === '') {
            $errors['unit_cost'][] = 'Unit Cost wajib diisi.';
        } elseif (! is_numeric($unitCost)) {
            $errors['unit_cost'][] = 'Unit Cost harus berupa angka.';
        } elseif ((float) $unitCost < 0) {
            $errors['unit_cost'][] = 'Unit Cost tidak boleh negatif.';
        }

        if ($dueDate !== '') {
            $parsedDue = $this->parseImportDate($dueDate);
            if ($parsedDue === null) {
                $errors['due_date'][] = 'Due Date harus dalam format DD/MM/YYYY dan tanggal valid.';
            } elseif ($parsedDate instanceof CarbonImmutable && $parsedDue->lt($parsedDate)) {
                $errors['due_date'][] = 'Due Date tidak boleh sebelum Bill Date.';
            }
        }

        $taxCode = trim((string) ($normalized['tax_code'] ?? ''));
        if ($taxCode !== '' && ! is_numeric($taxCode)) {
            $taxCodes = (array) config('imports.profiles.vendor_bill.tax_codes', []);
            if (! array_key_exists(mb_strtoupper($taxCode), $taxCodes)) {
                $known = implode(', ', array_keys($taxCodes));
                $errors['tax_code'][] = "Kode pajak '{$taxCode}' tidak dikenal. Kode yang tersedia: {$known}.";
            }
        }

        // Group consistency.
        if ($ref !== '' && $vendor instanceof Contact && $parsedDate instanceof CarbonImmutable) {
            $earlierRow = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('external_ref', $ref)
                ->whereNot('status', 'invalid')
                ->orderBy('row_number')
                ->first();

            if ($earlierRow instanceof ImportRow) {
                $earlierNormalized = (array) ($earlierRow->normalized ?? []);
                $earlierDate = trim((string) ($earlierNormalized['bill_date'] ?? ''));
                $earlierVendor = trim((string) ($earlierNormalized['vendor'] ?? ''));

                if ($earlierDate !== '' && $earlierDate !== $billDate) {
                    $errors['ref'][] = "Ref '{$ref}' sudah dipakai di baris {$earlierRow->row_number} dengan tanggal berbeda ({$earlierDate}).";
                }

                if ($earlierVendor !== '' && mb_strtolower($earlierVendor) !== mb_strtolower($vendorValue)) {
                    $errors['ref'][] = "Ref '{$ref}' sudah dipakai di baris {$earlierRow->row_number} dengan pemasok berbeda ({$earlierVendor}).";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    public function commit(ImportBatch $batch): array
    {
        $results = [];

        $validRows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get();

        $groups = [];
        foreach ($validRows as $row) {
            $ref = trim((string) ($row->external_ref ?? ''));
            if ($ref === '') {
                $ref = '_no_ref_'.($row->id);
            }
            $groups[$ref][] = $row;
        }

        foreach ($groups as $rows) {
            $this->commitGroup($rows, $results);
        }

        return $results;
    }

    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>  $results
     */
    private function commitGroup(array $rows, array &$results): void
    {
        $first = $rows[0];
        $normalized = (array) ($first->normalized ?? []);

        $vendorValue = trim((string) ($normalized['vendor'] ?? ''));
        $vendor = $this->findContactByCodeOrName($vendorValue);

        if (! $vendor instanceof Contact) {
            $this->markGroupFailed($rows, $results, "Pemasok '{$vendorValue}' tidak ditemukan saat commit.");

            return;
        }

        $billDate = $this->normalizeDate(trim((string) ($normalized['bill_date'] ?? '')));
        $dueDate = $this->normalizeDate(trim((string) ($normalized['due_date'] ?? '')));
        $notes = trim((string) ($normalized['notes'] ?? ''));
        $taxCodes = (array) config('imports.profiles.vendor_bill.tax_codes', []);

        $lines = [];
        $groupHasTax = false;

        foreach ($rows as $row) {
            $n = (array) ($row->normalized ?? []);
            $itemValue = trim((string) ($n['item'] ?? ''));
            $product = $this->findProductByCodeOrName($itemValue);
            $taxCode = trim((string) ($n['tax_code'] ?? ''));
            $taxRate = $this->resolveTaxRate($taxCode, $taxCodes);

            if ($taxRate !== null && $taxRate > 0) {
                $groupHasTax = true;
            }

            // warehouse_id diambil dari data baris (kolom opsional). Produk
            // berstok memerlukan warehouse; produk non-stok dibiarkan null.
            $warehouseId = isset($n['warehouse_id']) && $n['warehouse_id'] !== ''
                ? (int) $n['warehouse_id'] : null;

            $lines[] = [
                'product_id' => $product?->id,
                'product_code' => $product?->product_code ?? $itemValue,
                'description' => $product?->product_name ?? $itemValue,
                'quantity' => (float) ($n['quantity'] ?? 0),
                'unit_id' => $product?->unit_id,
                'unit_price' => (float) ($n['unit_cost'] ?? 0),
                'tax_rate' => $taxRate,
                'warehouse_id' => $warehouseId,
            ];
        }

        try {
            $bill = $this->billService->create([
                'vendor_id' => $vendor->id,
                'bill_date' => $billDate,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'is_taxable' => $groupHasTax,
                'notes' => $notes !== '' ? $notes : null,
                'lines' => $lines,
            ], ['suppress_auto_post' => true]);

            foreach ($rows as $row) {
                $results[$row->id] = [
                    'status' => 'committed',
                    'document_id' => $bill->id,
                    'document_type' => VendorBill::class,
                    'error' => null,
                ];
            }
        } catch (ApiException $e) {
            $this->markGroupFailed($rows, $results, $e->getMessage());
        } catch (Throwable $e) {
            $this->markGroupFailed($rows, $results, 'Gagal membuat tagihan: '.$e->getMessage());
        }
    }

    private function resolveTaxRate(string $code, array $taxCodes): ?float
    {
        if ($code === '') {
            return null;
        }
        if (is_numeric($code)) {
            return (float) $code;
        }

        return $taxCodes[mb_strtoupper($code)] ?? null;
    }

    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>  $results
     */
    private function markGroupFailed(array $rows, array &$results, string $message): void
    {
        foreach ($rows as $row) {
            $results[$row->id] = [
                'status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $message,
            ];
        }
    }
}
