<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateAvatarRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Services\Profile\ProfileAvatarService;
use App\Services\ProfileUpdateService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly ProfileUpdateService $profileUpdateService,
        private readonly ProfileAvatarService $profileAvatarService,
    ) {}

    /**
     * Get authenticated user profile.
     *
     * Devolve o mesmo shape de `updateProfile()` (UserProfileResource) para
     * que o formulario de edicao do frontend reatribua a resposta do PUT
     * direto ao estado, sem remapear campos.
     */
    public function profile(Request $request)
    {
        $user = $request->user()
            ->load(['professional', 'company', 'media'])
            ->loadCount('pets');

        return new UserProfileResource($user);
    }

    /**
     * Update user profile.
     *
     * Endereco alterado dispara re-geocodificacao (ProfileUpdateService) para
     * que latitude/longitude — e a coluna PostGIS `location`, via o trait
     * HasGeoPoint no model User — nunca fiquem presas no endereco antigo.
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $this->profileUpdateService->update(
            $request->user(),
            $request->validated()
        );

        return new UserProfileResource($user->load('media')->loadCount('pets'));
    }

    /**
     * Replace the authenticated user's profile photo.
     *
     * Multipart, e por isso POST e não PUT: o PHP não faz o parse de corpo
     * multipart em PUT, então o arquivo chegaria vazio do outro lado.
     *
     * Responde com o mesmo `UserProfileResource` do GET/PUT — a tela de perfil
     * reatribui a resposta ao estado e a foto nova aparece sem refetch.
     */
    public function updateAvatar(UpdateAvatarRequest $request)
    {
        $user = $this->profileAvatarService->update(
            $request->user(),
            $request->file('avatar')
        );

        return new UserProfileResource(
            $user->load(['professional', 'company'])->loadCount('pets')
        );
    }

    /**
     * Remove the authenticated user's profile photo.
     */
    public function destroyAvatar(Request $request)
    {
        $user = $this->profileAvatarService->remove($request->user());

        return new UserProfileResource(
            $user->load(['professional', 'company'])->loadCount('pets')
        );
    }

    /**
     * Get dashboard stats
     */
    public function dashboardStats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'totalPets' => $user->pets()->count(),
            'appointments' => 0, // TODO: Implement appointments
            'healthRecords' => 0, // TODO: Implement health records
            'walletBalance' => 0, // TODO: Implement wallet
        ];

        return response()->json($stats);
    }
}
