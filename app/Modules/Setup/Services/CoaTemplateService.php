<?php

namespace App\Modules\Setup\Services;

use App\Modules\Journal\Models\JournalEntryLine;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\AccountMappingStorageService;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Modules\OpeningBalance\Models\OpeningBalanceLine;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

class CoaTemplateService
{
    public function __construct(
        private readonly ChartOfAccountService $chartOfAccountService,
        private readonly AccountMappingStorageService $accountMappingStorageService,
    ) {}

    /**
     * @return array<int, array{id:string, label:string, description:string, account_count:int, accounts:array}>
     */
    public function templates(): array
    {
        $templates = (array) config('coa_templates.templates', []);

        $result = [];
        foreach ($templates as $id => $template) {
            $accounts = (array) ($template['accounts'] ?? []);
            $result[] = [
                'id' => (string) $id,
                'label' => (string) ($template['label'] ?? $id),
                'description' => (string) ($template['description'] ?? ''),
                'account_count' => count($accounts),
                'accounts' => $accounts,
            ];
        }

        return $result;
    }

    /**
     * Menerapkan template COA (atau versi yang sudah dikustomisasi user) ke
     * Chart of Accounts perusahaan. Idempotent terhadap akun bertanda
     * `is_system_default` dari pemakaian sebelumnya -- akun itu diganti,
     * akun yang dibuat manual di Master Data tidak disentuh.
     *
     * @param  array<int, array{code:string, name:string, type:string, parent_code:?string, is_cash_bank?:bool, description?:?string, normal_balance?:?string}>  $accounts
     * @return array<int, ChartOfAccount>
     */
    public function applyTemplate(string $templateId, array $accounts): array
    {
        if (! array_key_exists($templateId, (array) config('coa_templates.templates', []))) {
            throw ApiException::make('UNKNOWN_COA_TEMPLATE', 'Unknown COA template.', 422, [
                'template_id' => ['Unknown COA template.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($templateId, $accounts) {
            $this->replacePreviousTemplateAccounts();

            $codeToId = [];
            $created = [];

            foreach ($accounts as $row) {
                $code = (string) $row['code'];
                $parentCode = $row['parent_code'] ?? null;
                $parentId = null;

                if ($parentCode !== null) {
                    $parentCode = (string) $parentCode;
                    if (! array_key_exists($parentCode, $codeToId)) {
                        throw ApiException::make(
                            'COA_TEMPLATE_PARENT_NOT_FOUND',
                            "Parent account [{$parentCode}] must appear before its child [{$code}] in the template.",
                            422,
                        );
                    }
                    $parentId = $codeToId[$parentCode];
                }

                $account = $this->chartOfAccountService->create([
                    'account_code' => $code,
                    'account_name' => (string) $row['name'],
                    'account_type' => (string) $row['type'],
                    // Diteruskan apa adanya supaya akun kontra-aset (akumulasi
                    // penyusutan/amortisasi: tipe `asset`, saldo normal `credit`)
                    // tidak ikut diturunkan jadi `debit` oleh
                    // ChartOfAccountService::validateNormalBalance(). Null tetap
                    // berarti "turunkan dari tipe", seperti sebelumnya.
                    'normal_balance' => $row['normal_balance'] ?? null,
                    'parent_account_id' => $parentId,
                    'is_cash_bank' => (bool) ($row['is_cash_bank'] ?? false),
                    'description' => $row['description'] ?? null,
                    'is_system_default' => true,
                    'metadata' => ['template_id' => $templateId],
                ]);

                $codeToId[$code] = $account->id;
                $created[] = $account;
            }

            $this->accountMappingStorageService->syncDefaultMappingsFromConfig();

            return $created;
        });
    }

    /**
     * Menghapus akun bertanda `is_system_default` dari penerapan template
     * sebelumnya, supaya kode akun bisa dipakai ulang oleh template baru.
     * Ditolak kalau salah satu akun itu sudah dipakai jurnal/saldo awal --
     * constraint FK `restrictOnDelete()` sebenarnya sudah mencegah ini di
     * level DB, tapi guard ini memberi pesan yang jelas alih-alih exception
     * SQL mentah.
     */
    private function replacePreviousTemplateAccounts(): void
    {
        $existingIds = ChartOfAccount::query()
            ->where('is_system_default', true)
            ->pluck('id');

        if ($existingIds->isEmpty()) {
            return;
        }

        $referenced = JournalEntryLine::query()->whereIn('account_id', $existingIds)->exists()
            || OpeningBalanceLine::query()->whereIn('account_id', $existingIds)->exists();

        if ($referenced) {
            throw ApiException::make(
                'COA_TEMPLATE_ACCOUNTS_IN_USE',
                'Template tidak bisa diganti karena akun dari template sebelumnya sudah dipakai transaksi. Kelola Chart of Accounts secara manual di Master Data.',
                422,
            );
        }

        ChartOfAccount::query()->whereIn('id', $existingIds)->delete();
    }
}
