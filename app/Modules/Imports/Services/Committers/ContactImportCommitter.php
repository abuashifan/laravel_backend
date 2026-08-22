<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\Concerns\DetectsDuplicateCodesInBatch;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Services\ContactService;
use App\Shared\Exceptions\ApiException;
use Throwable;

class ContactImportCommitter implements ImportProfileCommitter
{
    use DetectsDuplicateCodesInBatch;

    private const TYPES = ['customer', 'supplier', 'employee', 'other'];

    public function __construct(
        private readonly ContactService $contacts,
    ) {}

    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $type = trim((string) ($normalized['type'] ?? ''));
        if ($type !== '' && ! in_array($type, self::TYPES, true)) {
            $errors['type'][] = sprintf('Type "%s" tidak dikenal. Pakai salah satu: %s.', $type, implode(', ', self::TYPES));
        }

        $code = trim((string) ($normalized['code'] ?? ''));
        if ($code !== '') {
            if (Contact::query()->where('contact_code', $code)->exists()) {
                $errors['code'][] = "Kode kontak \"{$code}\" sudah dipakai.";
            } elseif ($this->isCodeUsedElsewhereInBatch($batch, 'code', $code)) {
                $errors['code'][] = "Kode kontak \"{$code}\" dipakai lebih dari sekali di berkas ini.";
            }
        }

        return $errors;
    }

    public function commit(ImportBatch $batch): array
    {
        $results = [];

        foreach ($batch->rows()->where('status', 'valid')->orderBy('row_number')->get() as $row) {
            $results[$row->id] = $this->commitRow($row);
        }

        return $results;
    }

    /**
     * @return array{status: string, document_id: ?int, document_type: ?string, error: ?string}
     */
    private function commitRow(ImportRow $row): array
    {
        $normalized = (array) $row->normalized;
        $type = trim((string) ($normalized['type'] ?? ''));

        try {
            $contact = $this->contacts->create([
                'contact_code' => $this->nullableString($normalized['code'] ?? null),
                'name' => trim((string) $normalized['name']),
                'contact_type' => $type !== '' ? $type : null,
                'is_customer' => $type === 'customer',
                'is_supplier' => $type === 'supplier',
                'is_employee' => $type === 'employee',
                'email' => $this->nullableString($normalized['email'] ?? null),
                'phone' => $this->nullableString($normalized['phone'] ?? null),
                'address' => $this->nullableString($normalized['address'] ?? null),
                'tax_number' => $this->nullableString($normalized['tax_number'] ?? null),
                'is_active' => true,
            ]);

            return ['status' => 'committed', 'document_id' => $contact->id, 'document_type' => Contact::class, 'error' => null];
        } catch (ApiException $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Gagal membuat kontak: '.$exception->getMessage()];
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
