<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\DietaryRestriction;
use App\Models\FoodAllergy;
use App\Models\FoodBrand;
use App\Models\Pathology;
use App\Models\Specialty;
use App\Models\VaccineCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public function pathologies(Request $request): JsonResponse
    {
        $query = Pathology::query();
        if ($request->has('species')) {
            $query->where('species', $request->input('species'));
        }
        return response()->json($query->orderBy('name')->get());
    }

    public function vaccineCatalog(Request $request): JsonResponse
    {
        $query = VaccineCatalog::query();
        if ($request->has('species')) {
            $query->where('species', $request->input('species'));
        }
        return response()->json($query->orderBy('name')->get());
    }

    public function foodBrands(Request $request): JsonResponse
    {
        $query = FoodBrand::query();
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->has('species')) {
            $query->where('species_target', $request->input('species'));
        }
        return response()->json($query->orderBy('name')->get());
    }

    public function specialties(): JsonResponse
    {
        return response()->json(Specialty::orderBy('name')->get());
    }

    public function foodAllergies(): JsonResponse
    {
        return response()->json(FoodAllergy::orderBy('name')->get());
    }

    public function dietaryRestrictions(): JsonResponse
    {
        return response()->json(DietaryRestriction::orderBy('name')->get());
    }
}
