<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PaymentTermService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    protected array $listSearchable = ['name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    protected string $listStatusColumn = 'is_active';

    /** Urutan manual (sort_order) dipertahankan sebagai kunci pertama. */
    protected array $listDefaultSort = ['sort_order' => 'asc', 'name' => 'asc'];

    protected array $listSortable = ['name', 'sort_order', 'is_active'];

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,PaymentTerm>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = PaymentTerm::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $this->toBool($filters['is_active']));
        }

        return $this->applyListQuery($query, $filters);
    }

    public function create(array $data): PaymentTerm
    {
        $code = strtoupper((string) $data['code']);
        if (PaymentTerm::query()->where('code', $code)->exists()) {
            throw ApiException::make('DUPLICATE_PAYMENT_TERM_CODE', 'Payment term code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        $data['code'] = $code;
        $this->normalize($data);

        return PaymentTerm::query()->create($data);
    }

    public function update(PaymentTerm $paymentTerm, array $data): PaymentTerm
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
            if ($data['code'] !== $paymentTerm->code && PaymentTerm::query()->where('code', $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_PAYMENT_TERM_CODE', 'Payment term code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $this->normalize($data);
        $paymentTerm->fill($data);
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    public function deactivate(PaymentTerm $paymentTerm): PaymentTerm
    {
        $paymentTerm->is_active = false;
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    public function activate(PaymentTerm $paymentTerm): PaymentTerm
    {
        $paymentTerm->is_active = true;
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    private function normalize(array &$data): void
    {
        if (($data['is_custom'] ?? false) === true) {
            $data['days'] = null;
        }
        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }
        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = 0;
        }
    }
}
