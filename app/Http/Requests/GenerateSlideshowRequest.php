<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateSlideshowRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Skip authentication/authorization per instruction
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:10000'],
            'slide_count' => ['required', 'integer', 'min:3', 'max:20'],
            'language' => ['required', 'string', 'max:100'],
            'image_pack_id' => ['nullable', 'string', 'max:100'],
            'theme' => ['nullable', 'string', 'in:modern,minimal,vibrant,dark,sunset,ocean'],
        ];
    }
}

