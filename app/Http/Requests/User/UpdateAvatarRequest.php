<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil do usuário autenticado.
 *
 * As regras espelham `CompleteTutorRegistrationRequest` de propósito: a mesma foto
 * que entra no cadastro precisa poder ser trocada depois sem esbarrar num limite
 * diferente. `image` + `mimes` não confiam na extensão — os dois resolvem o tipo
 * pelo conteúdo real (finfo), então `payload.php` renomeado para `.jpg` é rejeitado
 * antes de chegar na MediaLibrary.
 *
 * HEIC fica de fora: a coleção `avatar` do model User só aceita jpeg/png/webp, e
 * não há conversão rodando (o Docker não sobe worker de fila).
 */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Selecione uma imagem.',
            'avatar.image' => 'O arquivo enviado deve ser uma imagem.',
            'avatar.mimes' => 'A foto deve estar em jpg, jpeg, png ou webp.',
            'avatar.max' => 'A foto deve ter no máximo 5MB.',
        ];
    }
}
