<?php

namespace App\Modules\Imports\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;

class ImportTemplateService
{
    /**
     * @return array{filename:string, headers:list<string>, rows:list<list<string>>}
     */
    public function csv(string $profile): array
    {
        $profileConfig = $this->profile($profile);

        // 'samples' (array of arrays) dipakai untuk profil yang butuh
        // beberapa baris contoh — mis. jurnal umum (debit + kredit).
        // 'sample' (array tunggal) tetap didukung untuk backward compatibility.
        $rows = [];
        if (isset($profileConfig['samples']) && is_array($profileConfig['samples'])) {
            foreach ($profileConfig['samples'] as $sampleRow) {
                $rows[] = (array) $sampleRow;
            }
        } elseif (isset($profileConfig['sample'])) {
            $rows[] = (array) $profileConfig['sample'];
        }

        return [
            'filename' => 'template-'.$profile.'.csv',
            'headers' => (array) $profileConfig['headers'],
            'rows' => $rows,
        ];
    }

    private function profile(string $profile): array
    {
        $profiles = (array) config('imports.profiles', []);

        if (! array_key_exists($profile, $profiles)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Profil impor tidak dikenal.',
                422,
                ['profile' => ['Profil impor tidak dikenal.']]
            );
        }

        return (array) $profiles[$profile];
    }
}
