<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Troca e remoção da foto de perfil.
 *
 * A coleção `avatar` do model User é `singleFile()`: adicionar uma nova imagem já
 * apaga a anterior do disco. Não chame `clearMediaCollection()` antes de adicionar —
 * se o `addMedia` falhar no meio, o usuário fica sem foto nenhuma.
 *
 * Diferente do cadastro (`RegistrationCompletionService::attachAvatar`, que engole a
 * falha para não travar o onboarding), aqui a falha é o resultado da ação: se o upload
 * não salvou, o usuário precisa saber, senão fica olhando para a foto antiga achando
 * que o app perdeu o arquivo.
 */
class ProfileAvatarService
{
    public function update(User $user, UploadedFile $avatar): User
    {
        try {
            $user->addMedia($avatar)->toMediaCollection('avatar');
        } catch (Throwable $e) {
            Log::error('ProfileAvatarService: failed to store avatar', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'avatar' => 'Não foi possível salvar a foto. Tente novamente.',
            ])->status(422);
        }

        return $this->refreshed($user);
    }

    public function remove(User $user): User
    {
        $user->clearMediaCollection('avatar');

        return $this->refreshed($user);
    }

    /**
     * A relação `media` já estava carregada quando o controller montou o usuário;
     * sem recarregar, `getFirstMediaUrl()` devolveria a foto ANTERIOR no mesmo
     * request — a resposta do upload mostraria a imagem trocada só no próximo F5.
     */
    private function refreshed(User $user): User
    {
        return $user->load('media');
    }
}
