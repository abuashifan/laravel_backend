<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tahap pencairan RAB Kegiatan Workspace: biaya langsung (`direct`) atau
 * dana muka (`advance`) yang diselesaikan dengan biaya terverifikasi.
 */
class WorkspaceDisbursementTranche extends Model
{
    public const DIRECT = 'direct';

    public const ADVANCE = 'advance';

    protected $connection = 'tenant';

    protected $guarded = [];

    protected $casts = [
        'lines' => 'array',
        'settlement_lines' => 'array',
        'disbursed_on' => 'date:Y-m-d',
        'settled_on' => 'date:Y-m-d',
        'amount_minor' => 'integer',
        'refund_minor' => 'integer',
        'sequence' => 'integer',
    ];

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(WorkspaceDisbursement::class, 'workspace_disbursement_id');
    }

    public function cashPayment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function advanceAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'advance_account_id');
    }

    public function settlementJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_journal_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Posisi tahap: menunggu QC, QC dikembalikan, menunggu posting kas keluar,
     * menunggu penyelesaian dana muka, selesai, atau dibatalkan.
     */
    public function state(): string
    {
        $payment = $this->cashPayment?->status;

        return match (true) {
            ! $this->isActive() => 'cancelled',
            $this->qc_status === 'rejected' => 'returned',
            $this->qc_status !== 'approved' => 'awaiting_qc',
            $payment !== 'posted' => $payment === 'void' ? 'payment_void' : 'awaiting_posting',
            $this->kind === self::ADVANCE && $this->settlement_journal_id === null => 'awaiting_settlement',
            default => 'complete',
        };
    }
}
