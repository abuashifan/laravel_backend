<?php

// Rancangan 26 Oktober 2026: satu tab per kelompok, tujuh kolom dan akses khusus.
// Setiap feature berisi awalan kunci, label, dan aksi yang tersedia di matriks.
$all = ['view', 'create', 'edit', 'delete', 'approve', 'print', 'export'];
$master = ['view', 'create', 'edit', 'delete', 'export'];
$report = ['view', 'print', 'export'];

return [
    'general' => [
        'label' => 'Umum & Pengaturan',
        'features' => [
            'dashboard.overview' => ['Dashboard', ['view']],
            'settings.company' => ['Profil & pengaturan perusahaan', ['view', 'create', 'edit']],
            'settings.transactions' => ['Pengaturan transaksi', ['view', 'create', 'edit']],
            'settings.account_mapping' => ['Pemetaan akun', ['view', 'create', 'edit']],
            'setup.wizard' => ['Setup awal', ['view', 'create', 'edit', 'approve']],
            'access.users' => ['Pengguna & hak akses', ['view', 'create', 'edit', 'delete']],
            'access.invitations' => ['Undangan pengguna', ['view', 'create', 'delete']],
        ],
        'special' => [
            'dashboard.financial.view' => 'Angka keuangan dashboard',
            'access.audit.view' => 'Audit akses',
            'audit.document_history.view' => 'Riwayat edit dokumen',
            'setup.wizard.reopen' => 'Buka kembali setup',
        ],
    ],
    'master_data' => [
        'label' => 'Master Data',
        'features' => [
            'master_data.coa' => ['Akun (COA)', $master],
            'master_data.contacts' => ['Kontak', $master],
            'master_data.products' => ['Produk & kategori produk', $master],
            'master_data.units' => ['Satuan', $master],
            'master_data.warehouses' => ['Gudang', $master],
            'master_data.payment_terms' => ['Syarat bayar', $master],
            'master_data.departments' => ['Departemen', $master],
            'master_data.projects' => ['Proyek', $master],
        ],
        'special' => [
            'master_data.import.execute' => 'Impor master data',
            'master_data.import.revert' => 'Batalkan impor',
        ],
    ],
    'accounting' => [
        'label' => 'Buku Besar',
        'features' => [
            'accounting.journals' => ['Jurnal umum', $all],
            'accounting.opening_balance' => ['Saldo awal', ['view', 'create', 'edit', 'delete', 'approve']],
            'accounting.period_locks' => ['Periode akuntansi', ['view', 'create', 'edit']],
            'accounting.period_end' => ['Akhir periode', ['view', 'approve']],
            'accounting.fiscal_year' => ['Tahun fiskal & tutup buku', ['view', 'approve']],
        ],
        'special' => [
            'accounting.journals.import' => 'Impor jurnal',
            'accounting.opening_balance.reopen' => 'Buka kembali saldo awal',
            'accounting.period_end.reopen' => 'Buka kembali akhir periode',
            'accounting.fiscal_year.reopen' => 'Buka kembali tahun fiskal',
            'accounting.period_locks.override' => 'Transaksi di periode terkunci',
        ],
    ],
    'budget' => [
        'label' => 'Anggaran',
        'features' => [
            'budget.submissions' => ['Pagu anggaran & RAB', ['view', 'create', 'edit', 'approve', 'export']],
        ],
        'special' => [
            'budget.submissions.approve_head' => 'Setujui tingkat kepala',
            'budget.submissions.approve_finance' => 'Setujui tingkat keuangan',
            'budget.periods.manage' => 'Kelola pagu',
        ],
    ],
    'cash_bank' => [
        'label' => 'Kas & Bank',
        'features' => [
            'cash_bank.receipts' => ['Penerimaan kas', $all],
            'cash_bank.payments' => ['Pengeluaran kas', $all],
            'cash_bank.transfers' => ['Transfer antar kas/bank', $all],
            'cash_bank.reconciliations' => ['Rekonsiliasi bank', ['view', 'create', 'edit', 'approve', 'print', 'export']],
        ],
        'special' => ['cash_bank.statements.view' => 'Laporan rekening koran'],
    ],
    'sales' => [
        'label' => 'Penjualan',
        'features' => [
            'sales.quotations' => ['Penawaran', $all],
            'sales.orders' => ['Sales Order', $all],
            'sales.delivery_orders' => ['Pengiriman (DO)', $all],
            'sales.proformas' => ['Proforma', $all],
            'sales.invoices' => ['Invoice penjualan', $all],
            'sales.deposits' => ['Uang muka pelanggan', $all],
            'sales.receipts' => ['Penerimaan pelanggan', $all],
            'sales.returns' => ['Retur penjualan', $all],
            'sales.ar' => ['Piutang', ['view']],
        ],
        'special' => [
            'sales.documents.convert' => 'Konversi dokumen penjualan',
            'sales.deposits.refund' => 'Refund uang muka pelanggan',
            'sales.ar.reconcile' => 'Rekonsiliasi piutang',
            'sales.invoices.import' => 'Impor invoice penjualan',
        ],
    ],
    'purchase' => [
        'label' => 'Pembelian',
        'features' => [
            'purchase.requests' => ['Permintaan pembelian', $all],
            'purchase.orders' => ['Purchase Order', $all],
            'purchase.goods_receipts' => ['Penerimaan barang', $all],
            'purchase.bills' => ['Tagihan vendor', $all],
            'purchase.deposits' => ['Uang muka vendor', $all],
            'purchase.payments' => ['Pembayaran vendor', $all],
            'purchase.returns' => ['Retur pembelian', $all],
            'purchase.ap' => ['Hutang', ['view']],
        ],
        'special' => [
            'purchase.documents.convert' => 'Konversi dokumen pembelian',
            'purchase.deposits.refund' => 'Refund uang muka vendor',
            'purchase.ap.reconcile' => 'Rekonsiliasi hutang',
            'purchase.bills.import' => 'Impor tagihan vendor',
        ],
    ],
    'inventory' => [
        'label' => 'Persediaan',
        'features' => [
            'inventory.stock' => ['Saldo stok', ['view', 'export']],
            'inventory.movements' => ['Mutasi stok manual', $all],
            'inventory.adjustments' => ['Penyesuaian stok', $all],
            'inventory.opname' => ['Stock opname', $all],
            'inventory.valuation' => ['Nilai persediaan', ['view', 'export']],
        ],
        'special' => ['inventory.opname.reopen' => 'Buka kembali opname'],
    ],
    'fixed_assets' => [
        'label' => 'Aktiva Tetap',
        'features' => [
            'fixed_assets.assets' => ['Daftar aktiva', ['view', 'create', 'edit', 'delete', 'approve', 'export']],
            'fixed_assets.depreciation' => ['Penyusutan', ['view', 'approve']],
            'fixed_assets.disposal' => ['Disposal aktiva', ['view', 'approve']],
            'fixed_assets.settings' => ['Kategori & pengaturan aktiva', ['view', 'create', 'edit']],
        ],
        'special' => ['fixed_assets.opening.import' => 'Impor aset awal'],
    ],
    'reports' => [
        'label' => 'Laporan',
        'features' => [
            'reports.financial' => ['Keuangan', $report],
            'reports.general_ledger' => ['Buku besar', $report],
            'reports.sales' => ['Penjualan', $report],
            'reports.purchase' => ['Pembelian', $report],
            'reports.ar' => ['Piutang', $report],
            'reports.ap' => ['Hutang', $report],
            'reports.reconciliation' => ['Rekonsiliasi', $report],
            'reports.inventory' => ['Persediaan', $report],
            'reports.fixed_assets' => ['Aktiva tetap', $report],
            'reports.cash_bank' => ['Kas & bank', $report],
            'reports.tax' => ['Pajak', $report],
            'reports.budget' => ['Anggaran', $report],
            'reports.project' => ['Proyek', $report],
        ],
        'special' => [
            'reports.saved.manage' => 'Simpan & bagikan laporan',
            'reports.multi_period.use' => 'Laporan multi-periode',
        ],
    ],
];
