<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Inventory::with('professional')
            ->where('professional_id', $request->user()->id);
        $items = $query->orderBy('item_name')->get();
        return response()->json($items);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_name' => 'required|string|max:255',
            'category' => 'required|in:medication,vaccine,supply,equipment',
            'quantity' => 'required|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'min_quantity' => 'nullable|integer|min:0',
            'cost_price' => 'nullable|numeric',
            'selling_price' => 'nullable|numeric',
            'supplier' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;
        $inventory = Inventory::create($data);
        return response()->json(['message' => 'Item added to inventory', 'item' => $inventory], 201);
    }

    public function show(Request $request, $id)
    {
        $item = Inventory::with('professional')
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($item);
    }

    public function update(Request $request, $id)
    {
        $inventory = Inventory::where('professional_id', $request->user()->id)->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'item_name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:medication,vaccine,supply,equipment',
            'quantity' => 'sometimes|required|integer|min:0',
            'unit' => 'sometimes|nullable|string|max:50',
            'min_quantity' => 'sometimes|nullable|integer|min:0',
            'cost_price' => 'sometimes|nullable|numeric',
            'selling_price' => 'sometimes|nullable|numeric',
            'supplier' => 'sometimes|nullable|string|max:255',
            'expiry_date' => 'sometimes|nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $inventory->update($validator->validated());
        return response()->json(['message' => 'Inventory item updated', 'item' => $inventory]);
    }

    public function destroy(Request $request, $id)
    {
        $inventory = Inventory::where('professional_id', $request->user()->id)->findOrFail($id);
        $inventory->delete();
        return response()->json(['message' => 'Inventory item removed']);
    }
}
