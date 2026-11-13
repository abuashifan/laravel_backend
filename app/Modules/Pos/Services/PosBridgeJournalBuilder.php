<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCreditPayment;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleLine;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Models\PosSaleReturnLine;
use App\Modules\Pos\Models\PosShift;
use RuntimeException;

/**
 * Menyusun jurnal "Rekap Kasir" untuk SATU shift yang sudah ditutup (Fase 5).
 *
 * Shift tertutup tidak bisa berubah (batal hanya selama shift terbuka, retur
 * dan pelunasan kasbon tercatat di shift pemrosesnya), jadi aman dijadikan
 * dasar jurnal. Satu jurnal berisi:
 *
 *   Debit  Kas / Bank / QRIS / Piutang kasbon   sesuai cara bayar
 *   Debit  Diskon penjualan                     diskon total nota
 *   Kredit Pendapatan penjualan                 subtotal + pembulatan - pajak yang sudah termasuk harga
 *   Kredit Pajak keluaran                       pajak penjualan
 *   Debit  Retur penjualan, Debit Pajak keluaran, Kredit Kas/Bank/QRIS/Piutang   retur di shift ini
 *   Debit  Kas/Bank/QRIS, Kredit Piutang        pelunasan kasbon di shift ini
 *   Debit  HPP, Kredit Persediaan               opsional (`post_cogs`), harga modal saat jual
 *
 * Akun yang sama dijumlahkan bersih, jadi kas yang masuk dari penjualan dan
 * keluar untuk retur muncul sebagai satu baris.
 */
class PosBridgeJournalBuilder
{
    /** Kunci pemetaan akun dan apakah wajib terisi saat disimpan. */
    public const MAP_KEYS = [
        'cash' => 'Kas laci',
        'bank' => 'Bank (transfer)',
        'qris' => 'QRIS',
        'receivable' => 'Piutang kasbon',
        'revenue' => 'Pendapatan penjualan',
        'discount' => 'Diskon penjualan',
        'sales_return' => 'Retur penjualan',
        'tax' => 'Pajak keluaran',
        'cogs' => 'Harga pokok penjualan',
        'inventory' => 'Persediaan',
    ];

    /** Wajib saat jembatan dinyalakan. Sisanya hanya dibutuhkan kalau nilainya muncul. */
    public const REQUIRED_KEYS = ['cash', 'bank', 'qris', 'receivable', 'revenue'];

    /**
     * @param  array<string,int|null>  $map
     * @return array{lines: list<array{account_id:int, debit:float, credit:float, description:string, line_order:int}>, summary: array<string,float|int>, empty: bool}
     */
    public function forShift(PosShift $shift, array $map, bool $postCogs): array
    {
        /** @var array<int, array{net: float, labels: list<string>}> $accounts */
        $accounts = [];
        $add = function (string $key, float $amount, string $label, ?string $fallback = null) use (&$accounts, $map): void {
            if (abs($amount) < 0.005) {
                return;
            }
            $accountId = $map[$key] ?? ($fallback !== null ? ($map[$fallback] ?? null) : null);
            if (! $accountId) {
                throw new RuntimeException('Pemetaan akun "'.self::MAP_KEYS[$key].'" belum diisi, padahal ada nilainya di shift ini ('.$label.').');
            }
            $accounts[$accountId]['net'] = ($accounts[$accountId]['net'] ?? 0.0) + $amount;
            $accounts[$accountId]['labels'][] = $label;
        };

        $sales = PosSale::query()->where('shift_id', $shift->id)->where('status', 'completed');
        $byMethod = (clone $sales)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total, SUM(paid_amount - change_amount) as received, SUM(credit_amount) as credit')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');
        $totals = (clone $sales)
            ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(rounding_amount), 0) as rounding, COALESCE(SUM(total), 0) as total, COALESCE(SUM(CASE WHEN tax_inclusive THEN tax_amount ELSE 0 END), 0) as inclusive_tax')
            ->first();

        // Uang muka kasbon masuk laci sebagai tunai, sama seperti rekap shift.
        $cashFromSales = (float) ($byMethod['cash']->received ?? 0) + (float) ($byMethod['credit']->received ?? 0);
        $add('cash', $cashFromSales, 'penjualan tunai');
        $add('bank', (float) ($byMethod['transfer']->total ?? 0), 'penjualan transfer');
        $add('qris', (float) ($byMethod['qris']->total ?? 0), 'penjualan QRIS');
        $add('receivable', (float) ($byMethod['credit']->credit ?? 0), 'penjualan kasbon');

        $add('discount', (float) $totals->discount, 'diskon penjualan', 'revenue');
        $add('revenue', -((float) $totals->subtotal + (float) $totals->rounding - (float) $totals->inclusive_tax), 'pendapatan penjualan');
        $add('tax', -(float) $totals->tax, 'pajak penjualan');

        $returns = PosSaleReturn::query()->where('shift_id', $shift->id);
        $returnsByMethod = (clone $returns)->selectRaw('refund_method, SUM(total) as total')->groupBy('refund_method')->pluck('total', 'refund_method');
        $returnsTotal = (float) (clone $returns)->sum('total');
        $returnsTax = (float) (clone $returns)->sum('tax_amount');
        $add('cash', -(float) ($returnsByMethod['cash'] ?? 0), 'uang retur tunai');
        $add('bank', -(float) ($returnsByMethod['transfer'] ?? 0), 'uang retur transfer');
        $add('qris', -(float) ($returnsByMethod['qris'] ?? 0), 'uang retur QRIS');
        $add('receivable', -(float) ($returnsByMethod['credit'] ?? 0), 'retur potong kasbon');
        $add('sales_return', $returnsTotal - $returnsTax, 'retur penjualan', 'revenue');
        $add('tax', $returnsTax, 'pajak retur');

        $payments = PosCreditPayment::query()->where('shift_id', $shift->id);
        $paymentsByMethod = (clone $payments)->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method');
        $paymentsTotal = (float) (clone $payments)->sum('amount');
        $add('cash', (float) ($paymentsByMethod['cash'] ?? 0), 'pelunasan kasbon tunai');
        $add('bank', (float) ($paymentsByMethod['transfer'] ?? 0), 'pelunasan kasbon transfer');
        $add('qris', (float) ($paymentsByMethod['qris'] ?? 0), 'pelunasan kasbon QRIS');
        $add('receivable', -$paymentsTotal, 'pelunasan kasbon');

        $cogs = 0.0;
        if ($postCogs) {
            $sold = (float) PosSaleLine::query()
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
                ->where('pos_sales.shift_id', $shift->id)->where('pos_sales.status', 'completed')
                ->selectRaw('COALESCE(SUM(pos_sale_lines.unit_cost * pos_sale_lines.quantity), 0) as cost')->value('cost');
            // Hanya barang retur yang kembali ke stok mengurangi HPP; yang rusak tetap biaya.
            $restocked = (float) PosSaleReturnLine::query()
                ->join('pos_sale_returns', 'pos_sale_returns.id', '=', 'pos_sale_return_lines.return_id')
                ->where('pos_sale_returns.shift_id', $shift->id)->where('pos_sale_return_lines.restock', true)
                ->selectRaw('COALESCE(SUM(pos_sale_return_lines.unit_cost * pos_sale_return_lines.quantity), 0) as cost')->value('cost');
            $cogs = round($sold - $restocked, 2);
            $add('cogs', $cogs, 'harga pokok penjualan');
            $add('inventory', -$cogs, 'pengurangan persediaan');
        }

        $lines = [];
        $order = 1;
        $balance = 0.0;
        foreach ($accounts as $accountId => $entry) {
            $net = round($entry['net'], 2);
            if (abs($net) < 0.005) {
                continue;
            }
            $balance += $net;
            $lines[] = [
                'account_id' => (int) $accountId,
                'debit' => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? -$net : 0.0,
                'description' => 'Rekap Kasir: '.implode(', ', array_unique($entry['labels'])),
                'line_order' => $order++,
            ];
        }

        if (abs($balance) >= 0.01) {
            throw new RuntimeException('Rekap shift tidak seimbang (selisih '.number_format($balance, 2, ',', '.').'). Jurnal tidak dikirim.');
        }

        return [
            'lines' => $lines,
            'empty' => $lines === [],
            'summary' => [
                'sales_count' => (int) $byMethod->sum('count'),
                'sales_total' => round((float) $totals->total, 2),
                'returns_total' => round($returnsTotal, 2),
                'credit_payments_total' => round($paymentsTotal, 2),
                'cogs' => $cogs,
            ],
        ];
    }
}
