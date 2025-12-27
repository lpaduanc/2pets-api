<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenAIService;
use Illuminate\Http\Request;

class AiController extends Controller
{
    protected $openAI;

    public function __construct(OpenAIService $openAI)
    {
        $this->openAI = $openAI;
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'symptom' => 'required|string',
            'pet_details' => 'required|string',
        ]);

        $result = $this->openAI->analyzeSymptom(
            $request->input('symptom'),
            $request->input('pet_details')
        );

        return response()->json($result);
    }
}
