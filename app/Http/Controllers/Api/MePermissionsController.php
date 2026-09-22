<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Permission\PermissionSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET me/permissions — item 22 do backlog gap-simplesvet. O app monta menu e
 * gate de UI a partir disto: item sem permissão não é renderizado, não apenas
 * desabilitado (ver critério de aceite do documento de origem).
 */
class MePermissionsController extends Controller
{
    public function __construct(private readonly PermissionSummaryService $permissionSummaryService) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(
            $this->permissionSummaryService->forUser($request->user())
        );
    }
}
