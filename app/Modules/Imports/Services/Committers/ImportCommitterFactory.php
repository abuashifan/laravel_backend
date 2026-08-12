<?php

namespace App\Modules\Imports\Services\Committers;

class ImportCommitterFactory
{
    /**
     * Profil yang belum punya committer (mis. profil transaksi — rencana
     * impor data Fase 3-4) sengaja TIDAK terdaftar di sini, bukan diarahkan
     * ke implementasi kosong. `ImportBatchService::commit()` menolaknya
     * eksplisit lewat `has()`, dengan pesan yang jelas ketimbang gagal diam-
     * diam atau melempar exception generik dari factory ini.
     */
    public function __construct(
        private readonly ContactImportCommitter $contact,
        private readonly ProductImportCommitter $product,
        private readonly ChartOfAccountImportCommitter $chartOfAccount,
        private readonly SalesInvoiceImportCommitter $salesInvoice,
        private readonly VendorBillImportCommitter $vendorBill,
        private readonly JournalEntryImportCommitter $journalEntry,
    ) {}

    public function has(string $profile): bool
    {
        return array_key_exists($profile, $this->map());
    }

    public function make(string $profile): ImportProfileCommitter
    {
        return $this->map()[$profile];
    }

    /**
     * @return array<string, ImportProfileCommitter>
     */
    private function map(): array
    {
        return [
            'contact' => $this->contact,
            'product' => $this->product,
            'chart_of_account' => $this->chartOfAccount,
            'sales_invoice' => $this->salesInvoice,
            'vendor_bill' => $this->vendorBill,
            'journal_entry' => $this->journalEntry,
        ];
    }
}
