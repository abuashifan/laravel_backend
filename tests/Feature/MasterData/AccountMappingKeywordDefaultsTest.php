<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;

/**
 * COA hasil impor memakai kode perusahaan sendiri (contoh nyata: PT Rizkitama),
 * bukan kode templat. Pengisian mapping otomatis harus tetap menemukan akun
 * yang benar lewat NAMA akun.
 */
class AccountMappingKeywordDefaultsTest extends MasterDataTestCase
{
    private const COA = [
        ['1101', 'Kas', 'asset', null, false],
        ['1101.02', 'Kas Di Tangan', 'asset', '1101', true],
        ['1101.06', 'Kas Desti', 'asset', '1101', false],
        ['1101.06.01', 'Kas Penerimaan Pangkalan', 'asset', '1101.06', true],
        ['1101.06.02', 'Kas Kecil Desti', 'asset', '1101.06', true],
        ['1102', 'Bank', 'asset', null, false],
        ['1102.01', 'Bank BCA', 'asset', '1102', true],
        ['1102.02', 'Bank Mandiri', 'asset', '1102', true],
        ['1102.03', 'BRI', 'asset', '1102', true],
        ['1103', 'Piutang', 'asset', null, false],
        ['1103.01', 'Piutang Usaha', 'asset', '1103', false],
        ['1103.03', 'Piutang Karyawan', 'asset', '1103', false],
        ['1106', 'Persediaan', 'asset', null, false],
        ['1106.01', 'Persediaan Barang Dagang', 'asset', '1106', false],
        ['1108', 'Biaya Dibayar Dimuka', 'asset', null, false],
        ['1108.03', 'Penebusan DO Dibayar Dimuka', 'asset', '1108', false],
        ['1109', 'Pajak Dibayar Dimuka', 'asset', null, false],
        ['1109.01', 'PPN Masukan', 'asset', '1109', false],
        ['1110', 'Transaksi Aktiva Tetap', 'asset', null, false],
        ['1110.02', 'Pembelian Kendaraan', 'asset', '1110', false],
        ['1110.04', 'Transaksi Aktiva Tetap Lainnya', 'asset', '1110', false],
        ['1111', 'Perlengkapan & Sparepart Kendaraan', 'asset', null, false],
        ['1111.01', 'Perlengkapan Kantor', 'asset', '1111', false],
        ['1201', 'Aktiva Tetap', 'asset', null, false],
        ['1201.02', 'Bangunan', 'asset', '1201', false],
        ['1201.03', 'Kendaraan', 'asset', '1201', false],
        ['1201.04', 'Peralatan & Furniture Kantor', 'asset', '1201', false],
        ['1201.05', 'Tabung Gas', 'asset', '1201', false],
        ['1202', 'Aktiva Tetap Tak Berwujud', 'asset', null, false],
        ['1202.02', 'Software', 'asset', '1202', false],
        ['1203', 'Akumulasi Penyusutan & Amortisasi', 'asset', null, false],
        ['1203.01', 'Akumulasi Peny. Bangunan', 'asset', '1203', false],
        ['1203.02', 'Akumulasi Peny. Kendaraan', 'asset', '1203', false],
        ['1203.03', 'Akumulasi Peny. Peralatan', 'asset', '1203', false],
        ['1203.04', 'Akumulasi Amortisasi Software', 'asset', '1203', false],
        ['9999', 'Penampung Kasbon', 'asset', null, false],
        ['2101', 'Hutang Usaha', 'liability', null, false],
        ['2101.01', 'Utang Dagang', 'liability', '2101', false],
        ['2101.02', 'Hutang Barang Diterima Belum Ditagih', 'liability', '2101', false],
        ['2103', 'Hutang Pajak', 'liability', null, false],
        ['2103.01', 'PPN Keluaran', 'liability', '2103', false],
        ['2105', 'Pendapatan Diterima Dimuka', 'liability', null, false],
        ['3101', 'Laba Ditahan', 'equity', null, false],
        ['3102', 'Modal Saham', 'equity', null, false],
        ['3103', 'Ekuitas Saldo Awal', 'equity', null, false],
        ['3104', 'Modal Disetor', 'equity', null, false],
        ['3105', 'Penampung Saldo Awal', 'equity', null, false],
        ['3106', 'Laba Tahun Berjalan', 'equity', null, false],
        ['4101', 'Penjualan Usaha', 'revenue', null, false],
        ['4101.01', 'Penjualan Gas LPG Subsidi', 'revenue', '4101', false],
        ['4101.02', 'Penjualan Gas LPG Non Subsidi 5,5 Kg', 'revenue', '4101', false],
        ['4101.03', 'Penjualan Gas LPG Non Subsidi 12 Kg', 'revenue', '4101', false],
        ['4102', 'Retur & Diskon Penjualan', 'revenue', null, false],
        ['4102.01', 'Retur Penjualan', 'revenue', '4102', false],
        ['4102.02', 'Diskon Penjualan', 'revenue', '4102', false],
        ['8100', 'Pendapatan Lainnya', 'revenue', null, false],
        ['8100.01', 'Pendapatan Bunga', 'revenue', '8100', false],
        ['8100.01.01', 'Pendapatan Bunga - Bank', 'revenue', '8100.01', false],
        ['8100.02', 'Pendapatan Cashback', 'revenue', '8100', false],
        ['8100.03', 'Selisih Lebih Persediaan', 'revenue', '8100', false],
        ['8100.04', 'Laba Pelepasan Aktiva Tetap', 'revenue', '8100', false],
        ['8100.99', 'Pendapatan Lain-lain', 'revenue', '8100', false],
        ['5101', 'Harga Pokok Penjualan', 'expense', null, false],
        ['5101.01', 'HPP', 'expense', '5101', false],
        ['5102', 'Retur & Diskon Pembelian', 'expense', null, false],
        ['5102.01', 'Retur Pembelian', 'expense', '5102', false],
        ['5102.02', 'Diskon Pembelian', 'expense', '5102', false],
        ['6100', 'Biaya Administrasi & Umum', 'expense', null, false],
        ['6100.01', 'Beban Gaji Pegawai', 'expense', '6100', false],
        ['6100.02', 'Biaya THR Karyawan', 'expense', '6100', false],
        ['6100.03', 'Biaya Lembur', 'expense', '6100', false],
        ['6100.04', 'Honorarium Komisaris', 'expense', '6100', false],
        ['6100.05', 'Biaya Upah Harian', 'expense', '6100', false],
        ['6100.06', 'Biaya Tunjangan Asuransi Kesehatan', 'expense', '6100', false],
        ['6100.07', 'Biaya Tunjangan Asuransi BPJS Ketenagakerjaan', 'expense', '6100', false],
        ['6100.08', 'Biaya Tunjangan Transportasi', 'expense', '6100', false],
        ['6100.09', 'Biaya Tunjangan Makan / Konsumsi', 'expense', '6100', false],
        ['6100.12', 'Kuota Sopir', 'expense', '6100', false],
        ['6200', 'Biaya Kendaraan/Transport', 'expense', null, false],
        ['6200.01', 'Biaya Ritase Supir', 'expense', '6200', false],
        ['6200.02', 'Biaya Bahan Bakar / Parkir', 'expense', '6200', false],
        ['6200.03', 'Biaya Surat/Pajak Kendaraan', 'expense', '6200', false],
        ['6200.04', 'Beban Service / Pemeliharaan Truck', 'expense', '6200', false],
        ['6200.04.01', 'Beban Service / Pemeliharan Carry', 'expense', '6200', false],
        ['6200.06', 'Biaya Kendaraan/Transport Lain-lain', 'expense', '6200', false],
        ['6300', 'Biaya Kantor', 'expense', null, false],
        ['6300.01', 'Biaya ATK/Perlengkapan/P3K Kantor', 'expense', '6300', false],
        ['6300.02', 'Biaya Telepon/Listrik/Air Kantor', 'expense', '6300', false],
        ['6300.06', 'Biaya Sanksi/Denda pajak', 'expense', '6300', false],
        ['6300.08', 'Biaya Pemasanga Plastik Wraf', 'expense', '6300', false],
        ['6300.09', 'Biaya Pemeliharaan Kantor', 'expense', '6300', false],
        ['6300.10', 'Beban Perlengkapan Kantor', 'expense', '6300', false],
        ['6300.11', 'Cashback Pangkalan', 'expense', '6300', false],
        ['6500', 'Beban Penyusutan / Amortisasi', 'expense', null, false],
        ['6500.01', 'Beban Penyusutan Bangunan', 'expense', '6500', false],
        ['6500.02', 'Beban Penyusutan Kendaraan', 'expense', '6500', false],
        ['6500.03', 'Beban Penyusutan Peralatan', 'expense', '6500', false],
        ['6500.04', 'Beban Amortisasi Software', 'expense', '6500', false],
        ['6600', 'Beban Komisaris & Direksi', 'expense', null, false],
        ['6600.01', 'Biaya Listrik, Air, Gas & Telp Komisaris', 'expense', '6600', false],
        ['6600.03', 'Beban Upah Pegawai Komisaris', 'expense', '6600', false],
        ['6600.04', 'Biaya bahan bakar/ parkir direksi', 'expense', '6600', false],
        ['6600.05', 'Beban Perawatan Rumah Komisaris', 'expense', '6600', false],
        ['6600.06', 'Beban Perawatan Kendaraan Komisaris & Direksi', 'expense', '6600', false],
        ['7100', 'Beban Lainnya', 'expense', null, false],
        ['7100.01', 'Biaya Iuran Hiswana', 'expense', '7100', false],
        ['7100.04', 'Biaya Administrasi Bank', 'expense', '7100', false],
        ['7100.10', 'Biaya Sumbangan Kantor', 'expense', '7100', false],
        ['7100.11', 'Biaya Kordinasi Lapangan (ORMAS, LSM, dst)', 'expense', '7100', false],
        ['7100.12', 'Selisih Kurang Persediaan', 'expense', '7100', false],
        ['7100.13', 'Rugi Pelepasan Aktiva Tetap', 'expense', '7100', false],
        ['7100.99', 'Biaya Lain-lain Non-Operasional', 'expense', '7100', false],
        ['9000', 'Pajak Penghasilan', 'expense', null, false],
        ['9000.01', 'Pajak Penghasilan Badan', 'expense', '9000', false],
    ];

    private const EXPECTED = [
        'sales.accounts_receivable' => '1103.01',
        'sales.discount' => '4102.02',
        'sales.return' => '4102.01',
        'sales.tax_output' => '2103.01',
        'sales.customer_deposit' => '2105',
        'sales.default_cash_bank' => '1101.06.01',
        'purchase.accounts_payable' => '2101.01',
        'purchase.expense' => '7100.99',
        'purchase.inventory_interim' => '2101.02',
        'purchase.tax_input' => '1109.01',
        'purchase.discount' => '5102.02',
        'purchase.return' => '5102.01',
        'purchase.vendor_deposit' => '1108.03',
        'purchase.default_cash_bank' => '1101.02',
        'inventory.asset' => '1106.01',
        'inventory.cogs' => '5101.01',
        'inventory.adjustment_gain' => '8100.03',
        'inventory.adjustment_loss' => '7100.12',
        'inventory.write_off' => '7100.12',
        'fixed_assets.clearing' => '1110.04',
        'fixed_assets.cost' => '1201.04',
        'fixed_assets.disposal_gain' => '8100.04',
        'fixed_assets.disposal_loss' => '7100.13',
        'fixed_assets.vehicle_cost' => '1201.03',
        'fixed_assets.vehicle_accumulated_depreciation' => '1203.02',
        'fixed_assets.vehicle_depreciation_expense' => '6500.02',
        'fixed_assets.building_cost' => '1201.02',
        'fixed_assets.building_accumulated_depreciation' => '1203.01',
        'fixed_assets.building_depreciation_expense' => '6500.01',
        'fixed_assets.equipment_cost' => '1201.04',
        'fixed_assets.equipment_accumulated_depreciation' => '1203.03',
        'fixed_assets.equipment_depreciation_expense' => '6500.03',
        'fixed_assets.software_cost' => '1202.02',
        'fixed_assets.software_accumulated_amortization' => '1203.04',
        'fixed_assets.software_amortization_expense' => '6500.04',
        'cash_bank.default_cash' => '1101.02',
        'cash_bank.default_bank' => '1102.01',
        'cash_bank.bank_admin_fee' => '7100.04',
        'cash_bank.bank_interest_income' => '8100.01.01',
        'opening_balance.equity' => '3103',
        'opening_balance.clearing' => '3105',
        'closing.retained_earnings' => '3101',
        'closing.current_year_earnings' => '3106',
    ];

    public function test_mappings_are_filled_by_account_name_for_custom_coa(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedCoa();

        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertOk();

        $this->assertSame(self::EXPECTED, array_intersect_key($this->mappedCodes(), self::EXPECTED));
        // Akun Beban dulu salah terisi "Biaya THR Karyawan" lewat kode 6100.02.
        $this->assertNotSame('6100.02', $this->mappedCodes()['purchase.expense']);
    }

    public function test_reset_rescans_names_and_reports_no_missing_required(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedCoa();

        $this->postJson('/api/master-data/account-mappings/reset-defaults', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.summary.missing_required', []);
    }

    /**
     * @return array<string, string|null>
     */
    private function mappedCodes(): array
    {
        return AccountMapping::query()->with('account')->get()
            ->mapWithKeys(fn (AccountMapping $mapping): array => [$mapping->mapping_key => $mapping->account?->account_code])
            ->all();
    }

    private function seedCoa(): void
    {
        $ids = [];
        foreach (self::COA as [$code, $name, $type, $parent, $cashBank]) {
            $ids[$code] = ChartOfAccount::query()->create([
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
                'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
                'parent_account_id' => $parent !== null ? $ids[$parent] : null,
                'is_cash_bank' => $cashBank,
                'is_active' => true,
                'is_system_default' => false,
            ])->id;
        }
    }
}
