<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::where('professional_id', $request->user()->id);
        if ($request->has('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        $services = $query->orderBy('name')->get();
        return response()->json($services);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|in:consultation,surgery,exam,grooming,other',
            'duration' => 'required|integer',
            'price' => 'required|numeric',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;

        $service = Service::create($data);
        return response()->json(['message' => 'Service created', 'service' => $service], 201);
    }

    public function show($id)
    {
        $service = Service::where('professional_id', request()->user()->id)->findOrFail($id);
        return response()->json($service);
    }

    public function update(Request $request, $id)
    {
        $service = Service::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|required|in:consultation,surgery,exam,grooming,other',
            'duration' => 'sometimes|required|integer',
            'price' => 'sometimes|required|numeric',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service->update($validator->validated());
        return response()->json(['message' => 'Service updated', 'service' => $service]);
    }

    public function destroy($id)
    {
        $service = Service::where('professional_id', request()->user()->id)->findOrFail($id);
        $service->delete();
        return response()->json(['message' => 'Service removed']);
    }
}
