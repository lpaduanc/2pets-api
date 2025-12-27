<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Surgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SurgeryController extends Controller
{
    public function index(Request $request)
    {
        $query = Surgery::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id);
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        $surgeries = $query->orderBy('surgery_date', 'desc')->get();
        return response()->json($surgeries);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_id' => 'required|exists:pets,id',
            'surgery_date' => 'required|date',
            'surgery_type' => 'required|string|max:255',
            'status' => 'required|in:scheduled,in_progress,completed,cancelled',
            'pre_op_notes' => 'nullable|string',
            'procedure_description' => 'nullable|string',
            'post_op_notes' => 'nullable|string',
            'anesthesia_used' => 'nullable|string',
            'complications' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;

        $surgery = Surgery::create($data);
        return response()->json(['message' => 'Surgery scheduled', 'surgery' => $surgery], 201);
    }

    public function show(Request $request, $id)
    {
        $surgery = Surgery::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($surgery);
    }

    public function update(Request $request, $id)
    {
        $surgery = Surgery::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'surgery_date' => 'sometimes|date',
            'surgery_type' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:scheduled,in_progress,completed,cancelled',
            'pre_op_notes' => 'nullable|string',
            'procedure_description' => 'nullable|string',
            'post_op_notes' => 'nullable|string',
            'anesthesia_used' => 'nullable|string',
            'complications' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $surgery->update($validator->validated());
        return response()->json(['message' => 'Surgery updated', 'surgery' => $surgery]);
    }

    public function destroy(Request $request, $id)
    {
        $surgery = Surgery::where('professional_id', $request->user()->id)->findOrFail($id);
        $surgery->delete();
        return response()->json(['message' => 'Surgery removed']);
    }
}
