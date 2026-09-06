<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Services\ProfileUpdateService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly ProfileUpdateService $profileUpdateService,
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
            ->load(['professional', 'company'])
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

        return new UserProfileResource($user->loadCount('pets'));
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
