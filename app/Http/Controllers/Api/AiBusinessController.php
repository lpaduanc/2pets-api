<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenAIService;
use Illuminate\Http\Request;

class AiBusinessController extends Controller
{
    protected $openAI;

    public function __construct(OpenAIService $openAI)
    {
        $this->openAI = $openAI;
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
            'business_type' => 'required|string',
        ]);

        $result = $this->openAI->analyzeBusiness(
            $request->input('query'),
            $request->input('business_type')
        );

        return response()->json($result);
    }
}
