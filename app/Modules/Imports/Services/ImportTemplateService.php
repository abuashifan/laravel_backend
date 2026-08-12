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

        return [
            'filename' => 'template-'.$profile.'.csv',
            'headers' => (array) $profileConfig['headers'],
            'rows' => [(array) $profileConfig['sample']],
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
