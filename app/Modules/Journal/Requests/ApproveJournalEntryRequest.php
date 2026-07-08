<?php

namespace App\Modules\Journal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}

