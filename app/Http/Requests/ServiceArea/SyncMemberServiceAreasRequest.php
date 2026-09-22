<?php

namespace App\Http\Requests\ServiceArea;

use Illuminate\Foundation\Http\FormRequest;

/** `PUT organizations/{organization}/members/{member}/service-areas` — item 21. */
class SyncMemberServiceAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_area_ids' => ['present', 'array'],
            'service_area_ids.*' => ['integer', 'exists:service_areas,id'],
        ];
    }
}
