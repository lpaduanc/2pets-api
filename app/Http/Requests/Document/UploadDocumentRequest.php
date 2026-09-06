<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Only accept files that are likely identity/credential docs.
            // Block scripts and executables by restricting MIMEs explicitly.
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'document_type' => 'required|string|in:crmv,rg,cpf,diploma,specialization,cnpj,proof_of_address,other',
            'description' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'O arquivo não pode ultrapassar 10 MB.',
            'file.mimes' => 'Formato aceito: PDF, JPG ou PNG.',
            'document_type.in' => 'Tipo de documento inválido.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'file' => ['description' => 'Arquivo do documento (PDF ou imagem).'],
            'document_type' => ['description' => 'Tipo: crmv, rg, cpf, diploma, specialization, cnpj, proof_of_address ou other.'],
        ];
    }
}
