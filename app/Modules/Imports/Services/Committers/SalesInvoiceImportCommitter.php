<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\Concerns\ResolvesModelByCodeOrName;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Profil impor faktur penjualan — profil transaksi pertama (Fase 3).
 *
 * Dua aturan tunggal:
 * 1. WAJIB lewat SalesInvoiceService::create() — tidak menulis model langsung.
 * 2. Selalu TransactionStatus::DRAFT — tidak pernah auto-post.
 *
 * Multi-line grouping: beberapa baris CSV dengan Ref sama membentuk
 * satu faktur dengan banyak baris barang. Group-level validation
 * mendeteksi inkonsistensi tanggal/pelanggan dalam satu Ref.
 */
class SalesInvoiceImportCommitter implements ImportProfileCommitter
{
    use ResolvesModelByCodeOrName;

    public function __construct(private readonly SalesInvoiceService $invoiceService) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $ref = trim((string) ($normalized['ref'] ?? ''));
        $invoiceDate = trim((string) ($normalized['invoice_date'] ?? ''));
        $customerValue = trim((string) ($normalized['customer'] ?? ''));
        $itemValue = trim((string) ($normalized['item'] ?? ''));
        $quantity = trim((string) ($normalized['quantity'] ?? ''));
        $unitPrice = trim((string) ($normalized['unit_price'] ?? ''));
        $dueDate = trim((string) ($normalized['due_date'] ?? ''));

        // ── Ref wajib ──────────────────────────────────────────────────
        if ($ref === '') {
            $errors['ref'][] = 'Ref wajib diisi.';
        }

        // ── Invoice date ───────────────────────────────────────────────
        $parsedDate = null;
        if ($invoiceDate === '') {
            $errors['invoice_date'][] = 'Invoice Date wajib diisi.';
        } else {
            try {
                $parsedDate = CarbonImmutable::createFromFormat('Y-m-d', $invoiceDate);
                if ($parsedDate === false) {
                    $errors['invoice_date'][] = 'Invoice Date harus dalam format YYYY-MM-DD.';
                    $parsedDate = null;
                }
            } catch (Throwable) {
                $errors['invoice_date'][] = 'Invoice Date harus dalam format YYYY-MM-DD.';
            }
        }

        // ── Customer lookup ────────────────────────────────────────────
        $customer = null;
        if ($customerValue === '') {
            $errors['customer'][] = 'Customer wajib diisi.';
        } else {
            $customer = $this->findContactByCodeOrName($customerValue);

            if (! $customer instanceof Contact) {
                $errors['customer'][] = "Pelanggan '{$customerValue}' tidak ditemukan.";
            } elseif (! $customer->isCustomer()) {
                $errors['customer'][] = "'{$customerValue}' bukan pelanggan (tipe: {$customer->contact_type}).";
            } elseif (! $customer->isActive()) {
                $errors['customer'][] = "Pelanggan '{$customerValue}' sudah tidak aktif.";
            }
        }

        // ── Product lookup ─────────────────────────────────────────────
        $product = null;
        if ($itemValue === '') {
            $errors['item'][] = 'Item wajib diisi.';
        } else {
            $product = $this->findProductByCodeOrName($itemValue);

            if (! $product instanceof Product) {
                $errors['item'][] = "Produk '{$itemValue}' tidak ditemukan.";
            }
        }

        // ── Quantity ───────────────────────────────────────────────────
        if ($quantity === '') {
            $errors['quantity'][] = 'Quantity wajib diisi.';
        } elseif (! is_numeric($quantity)) {
            $errors['quantity'][] = 'Quantity harus berupa angka.';
        } elseif ((float) $quantity <= 0) {
            $errors['quantity'][] = 'Quantity harus lebih dari 0.';
        }

        // ── Unit Price ─────────────────────────────────────────────────
        if ($unitPrice === '') {
            $errors['unit_price'][] = 'Unit Price wajib diisi.';
        } elseif (! is_numeric($unitPrice)) {
            $errors['unit_price'][] = 'Unit Price harus berupa angka.';
        } elseif ((float) $unitPrice < 0) {
            $errors['unit_price'][] = 'Unit Price tidak boleh negatif.';
        }

        // ── Due Date ───────────────────────────────────────────────────
        if ($dueDate !== '') {
            try {
                $parsedDue = CarbonImmutable::createFromFormat('Y-m-d', $dueDate);
                if ($parsedDue === false) {
                    $errors['due_date'][] = 'Due Date harus dalam format YYYY-MM-DD.';
                } elseif ($parsedDate instanceof CarbonImmutable && $parsedDue->lt($parsedDate)) {
                    $errors['due_date'][] = 'Due Date tidak boleh sebelum Invoice Date.';
                }
            } catch (Throwable) {
                $errors['due_date'][] = 'Due Date harus dalam format YYYY-MM-DD.';
            }
        }

        // ── Tax Code ───────────────────────────────────────────────────
        $taxCode = trim((string) ($normalized['tax_code'] ?? ''));
        if ($taxCode !== '' && ! is_numeric($taxCode)) {
            $taxCodes = (array) config('imports.profiles.sales_invoice.tax_codes', []);
            if (! array_key_exists(mb_strtoupper($taxCode), $taxCodes)) {
                $known = implode(', ', array_keys($taxCodes));
                $errors['tax_code'][] = "Kode pajak '{$taxCode}' tidak dikenal. Kode yang tersedia: {$known}.";
            }
        }

        // ── Group consistency ──────────────────────────────────────────
        if ($ref !== '' && $customer instanceof Contact && $parsedDate instanceof CarbonImmutable) {
            $earlierRow = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('external_ref', $ref)
                ->whereNot('status', 'invalid')
                ->orderBy('row_number')
                ->first();

            if ($earlierRow instanceof ImportRow) {
                $earlierNormalized = (array) ($earlierRow->normalized ?? []);
                $earlierDate = trim((string) ($earlierNormalized['invoice_date'] ?? ''));
                $earlierCustomer = trim((string) ($earlierNormalized['customer'] ?? ''));

                if ($earlierDate !== '' && $earlierDate !== $invoiceDate) {
                    $errors['ref'][] = "Ref '{$ref}' sudah dipakai di baris {$earlierRow->row_number} dengan tanggal berbeda ({$earlierDate}). Semua baris dengan Ref sama harus punya Invoice Date yang sama.";
                }

                if ($earlierCustomer !== '' && mb_strtolower($earlierCustomer) !== mb_strtolower($customerValue)) {
                    $errors['ref'][] = "Ref '{$ref}' sudah dipakai di baris {$earlierRow->row_number} dengan pelanggan berbeda ({$earlierCustomer}). Semua baris dengan Ref sama harus punya Customer yang sama.";
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

        $validRows = $batch->rows()
            ->where('status', 'valid')
            ->orderBy('row_number')
            ->get();

        // Kelompokkan berdasarkan external_ref.
        // Baris tanpa ref (seharusnya tidak ada — required) → satu group sendiri.
        /** @var array<string, array<int, ImportRow>> $groups */
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
     * Commit satu group baris (satu faktur multi-baris).
     *
     * @param  array<int, ImportRow>  $rows
     * @param  array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>  $results
     */
    private function commitGroup(array $rows, array &$results): void
    {
        $first = $rows[0];
        $normalized = (array) ($first->normalized ?? []);

        $customerValue = trim((string) ($normalized['customer'] ?? ''));
        $customer = $this->findContactByCodeOrName($customerValue);

        if (! $customer instanceof Contact) {
            $this->markGroupFailed($rows, $results, "Pelanggan '{$customerValue}' tidak ditemukan saat commit.");

            return;
        }

        $invoiceDate = trim((string) ($normalized['invoice_date'] ?? ''));
        $dueDate = trim((string) ($normalized['due_date'] ?? ''));
        $notes = trim((string) ($normalized['notes'] ?? ''));

        // Bangun lines dari setiap baris.
        $lines = [];
        $groupHasTax = false;
        $taxCodes = (array) config('imports.profiles.sales_invoice.tax_codes', []);

        foreach ($rows as $row) {
            $n = (array) ($row->normalized ?? []);
            $itemValue = trim((string) ($n['item'] ?? ''));
            $product = $this->findProductByCodeOrName($itemValue);
            $taxCode = trim((string) ($n['tax_code'] ?? ''));
            $taxRate = $this->resolveTaxRate($taxCode, $taxCodes);

            if ($taxRate !== null && $taxRate > 0) {
                $groupHasTax = true;
            }

            $lines[] = [
                'product_id' => $product?->id,
                'product_code' => $product?->product_code ?? $itemValue,
                'description' => $product?->product_name ?? $itemValue,
                'quantity' => (float) ($n['quantity'] ?? 0),
                'unit_id' => $product?->unit_id,
                'unit_price' => (float) ($n['unit_price'] ?? 0),
                'tax_rate' => $taxRate,
            ];
        }

        try {
            $invoice = $this->invoiceService->create([
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'is_taxable' => $groupHasTax,
                'notes' => $notes !== '' ? $notes : null,
                'lines' => $lines,
            ], ['suppress_auto_post' => true]);

            foreach ($rows as $row) {
                $results[$row->id] = [
                    'status' => 'committed',
                    'document_id' => $invoice->id,
                    'document_type' => \App\Modules\Sales\Models\SalesInvoice::class,
                    'error' => null,
                ];
            }
        } catch (ApiException $e) {
            $this->markGroupFailed($rows, $results, $e->getMessage());
        } catch (Throwable $e) {
            $this->markGroupFailed($rows, $results, 'Gagal membuat faktur: '.$e->getMessage());
        }
    }

    /**
     * Resolve kode pajak ke persentase. Mendukung:
     * - Kosong → null
     * - Angka langsung → float
     * - Kode (PPN, VAT, dll) → lookup di config tax_codes
     *
     * @param  array<string, float>  $taxCodes
     */
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
                'status' => 'failed',
                'document_id' => null,
                'document_type' => null,
                'error' => $message,
            ];
        }
    }
}
