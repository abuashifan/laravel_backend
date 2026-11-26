<?php

namespace App\Modules\CashBank\Services;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use Illuminate\Support\Str;

class WorkspaceDisbursementOptions
{
    public function __construct(private readonly CashBankAccountService $cashAccounts) {}

    public function search(string $type, string $search): array
    {
        if ($type === 'cash') {
            return $this->cashAccounts->getCashBankAccounts()->map(fn ($account) => [
                'value' => $account->id, 'label' => $account->account_code.' · '.$account->account_name,
            ])->filter(fn ($option) => $search === '' || Str::contains(Str::lower($option['label']), Str::lower($search)))
                ->take(20)->values()->all();
        }

        [$query, $code, $name] = match ($type) {
            'expense' => [ChartOfAccount::query()->where('is_active', true)->where('account_type', 'expense')->whereDoesntHave('children'), 'account_code', 'account_name'],
            // Uang muka kegiatan: aset bukan kas/bank, misalnya "Uang Muka Kegiatan".
            'advance' => [ChartOfAccount::query()->where('is_active', true)->where('account_type', 'asset')->where('is_cash_bank', false)->whereDoesntHave('children'), 'account_code', 'account_name'],
            'department' => [Department::query()->active(), 'code', 'name'],
            'project' => [Project::query()->usable(), 'code', 'name'],
        };

        return $query->when($search !== '', fn ($options) => $options->where(
            fn ($matches) => $matches->whereLike($code, '%'.$search.'%')->orWhereLike($name, '%'.$search.'%'),
        ))->orderBy($code)->limit(20)->get(['id', $code, $name])->map(fn ($option) => [
            'value' => $option->id, 'label' => $option->$code.' · '.$option->$name,
        ])->all();
    }
}
