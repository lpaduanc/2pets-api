<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST professional/clients/{client}/tags` aceita `tag_id` (etiqueta já existente) OU `name`
 * (cria na hora) — o fluxo "digitar e criar" da ficha do cliente não obriga passar por
 * `POST tags` primeiro.
 */
class AttachTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tag_id' => ['required_without:name', 'nullable', 'integer'],
            'name' => ['required_without:tag_id', 'nullable', 'string', 'max:60'],
        ];
    }
}
