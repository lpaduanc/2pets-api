<?php

namespace App\Http\Requests\MedicalRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vídeo fica fora desta entrega de propósito (item 9 do MVP,
 * docs/atendimento-veterinario/00-dominio-e-escopo.md §6) — só imagem e PDF.
 */
class StoreMedicalRecordAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,heic,heif', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'O arquivo é obrigatório.',
            'file.mimes' => 'Formato inválido. Envie imagem (JPG, PNG, HEIC) ou PDF.',
            'file.max' => 'O arquivo deve ter no máximo 10MB.',
        ];
    }
}
