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
            // `radiology_license`: documento comprobatório por ITEM de equipamento, não por
            // perfil inteiro (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §1.3/§4) —
            // licença/alvará da instalação radiológica ou registro do equipamento no Ministério
            // da Saúde, exigido de quem declara `xray_machine` em `professionals.equipment`
            // (ver `ClinicalEquipment::requiredDocumentType()`). Não bloqueia o cadastro: o
            // item fica pendente de aprovação manual do admin até o documento existir.
            'document_type' => 'required|string|in:crmv,rg,cpf,diploma,specialization,cnpj,proof_of_address,radiology_license,other',
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
            'document_type' => ['description' => 'Tipo: crmv, rg, cpf, diploma, specialization, cnpj, proof_of_address, radiology_license ou other.'],
        ];
    }
}
