<?php

namespace App\Modules\Journal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostJournalEntryRequest extends FormRequest
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

