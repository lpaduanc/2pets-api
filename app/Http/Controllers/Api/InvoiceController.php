<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['client', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        $invoices = $query->orderBy('issue_date', 'desc')->get();
        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:users,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'items' => 'required|array',
            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'tax' => 'nullable|numeric',
            'total' => 'required|numeric',
            'status' => 'required|in:pending,paid,overdue,cancelled',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;
        $data['invoice_number'] = 'INV-' . strtoupper(Str::random(8)); // Simple generation

        $invoice = Invoice::create($data);
        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function show($id)
    {
        $invoice = Invoice::with(['client', 'professional', 'appointment'])
            ->where('professional_id', request()->user()->id)
            ->findOrFail($id);
        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'due_date' => 'sometimes|date',
            'items' => 'sometimes|array',
            'subtotal' => 'sometimes|numeric',
            'discount' => 'nullable|numeric',
            'tax' => 'nullable|numeric',
            'total' => 'sometimes|numeric',
            'status' => 'sometimes|in:pending,paid,overdue,cancelled',
            'payment_method' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $invoice->update($validator->validated());
        return response()->json(['message' => 'Invoice updated', 'invoice' => $invoice]);
    }

    public function destroy(Request $request, $id)
    {
        $invoice = Invoice::where('professional_id', $request->user()->id)->findOrFail($id);
        $invoice->delete();
        return response()->json(['message' => 'Invoice removed']);
    }
}
