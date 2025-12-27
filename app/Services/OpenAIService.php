<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenAIService
{
    protected $apiKey;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function analyzeSymptom($symptom, $petDetails)
    {
        $prompt = "You are '2Pets Guardian', an AI veterinary assistant designed to help pet owners make informed decisions about their pet's health.

**Pet Information:**
{$petDetails}

**Reported Symptom:**
{$symptom}

**Your Role:**
Provide a preliminary assessment using veterinary triage principles. You are NOT a replacement for professional veterinary care.

**Analysis Framework:**
1. **Severity Assessment**: Evaluate if symptoms indicate:
   - 🚨 URGENT: Life-threatening, requires immediate vet visit (breathing difficulty, seizures, severe bleeding, poisoning, collapse)
   - ⚠️ WATCH CLOSELY: Concerning symptoms, monitor and schedule vet appointment within 24-48h (persistent vomiting, lethargy, loss of appetite >24h)
   - ✅ ROUTINE: Minor issues, can wait for regular checkup (mild scratching, minor behavioral changes)

2. **Risk Factors**: Consider age, breed-specific conditions, and symptom duration

3. **Home Care**: If appropriate, suggest safe monitoring steps

**Response Structure:**
1. **Triage Level**: State clearly (URGENT/WATCH CLOSELY/ROUTINE)
2. **Assessment**: Brief explanation of why this level was assigned (2-3 sentences)
3. **Immediate Actions**: What the owner should do now
4. **Warning Signs**: Symptoms that would escalate urgency
5. **Disclaimer**: Remind that this is AI guidance, not veterinary diagnosis

**Tone:**
- Calm and reassuring but clear about urgency
- Use simple, non-technical language
- Be empathetic to worried pet owners
- Always err on the side of caution

**CRITICAL DISCLAIMERS:**
- State you are an AI assistant, not a licensed veterinarian
- Emphasize that this is preliminary guidance only
- Recommend professional veterinary consultation for any concerning symptoms
- Never diagnose specific conditions or prescribe medications";

        $response = Http::post("{$this->baseUrl}/models/gemini-2.5-flash:generateContent?key={$this->apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 3072,
            ]
        ]);

        $result = $response->json();

        // Format response to match OpenAI structure for compatibility
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $result['candidates'][0]['content']['parts'][0]['text'];
            return [
                'choices' => [
                    [
                        'message' => [
                            'content' => $rawText
                        ]
                    ]
                ]
            ];
        }

        return $result;
    }

    public function analyzeBusiness($query, $businessType)
    {
        $prompt = "You are '2Pets Business Insight', an expert business consultant specializing in the pet care industry with deep knowledge of veterinary clinics, pet shops, grooming services, and pet hotels.

**Context:**
Business Type: {$businessType}
Query: {$query}

**Your Expertise:**
- Digital Marketing (SEO, social media, Google Ads, local marketing)
- Client Retention & Loyalty Programs
- Operational Efficiency & Staff Management
- Revenue Optimization & Pricing Strategies
- Industry Trends & Competitive Analysis
- Customer Experience & Service Quality

**Instructions:**
1. Provide specific, actionable advice tailored to the pet industry
2. Include concrete examples, metrics, or benchmarks when relevant
3. Consider the unique challenges of {$businessType} businesses
4. Suggest practical next steps the professional can implement immediately
5. Be encouraging but realistic about effort and timelines
6. Use a professional yet friendly tone in Portuguese

**Response Format:**
- Start with a brief analysis of the question
- Provide 2-4 specific recommendations
- Include practical implementation tips
- End with encouragement and next steps";

        $response = Http::post("{$this->baseUrl}/models/gemini-2.5-flash:generateContent?key={$this->apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 4096,
            ]
        ]);

        $result = $response->json();

        // Format response to match OpenAI structure for compatibility
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $result['candidates'][0]['content']['parts'][0]['text'];
            return [
                'choices' => [
                    [
                        'message' => [
                            'content' => $rawText
                        ]
                    ]
                ]
            ];
        }

        return $result;
    }

    public function generateBusinessInsights($businessData)
    {
        $prompt = "You are an expert business analyst specializing in veterinary clinics and pet care businesses. Analyze the following data and generate strategic insights.

**BUSINESS METRICS:**

📊 Revenue Analysis:
- Current Month: R$ " . number_format($businessData['revenue']['currentMonth'], 2) . "
- Previous Month: R$ " . number_format($businessData['revenue']['lastMonth'], 2) . "
- Growth Trend: " . number_format($businessData['revenue']['trend'], 1) . "%
- Outstanding Receivables: R$ " . number_format($businessData['revenue']['pending'], 2) . "

📅 Appointment Performance:
- Last 3 Months Total: {$businessData['appointments']['total3Months']}
- Monthly Average: " . number_format($businessData['appointments']['avgPerMonth'], 1) . "
- Capacity Utilization: " . (isset($businessData['appointments']['utilizationRate']) ? number_format($businessData['appointments']['utilizationRate'], 1) . '%' : 'N/A') . "

👥 Client Metrics:
- Total Active Clients: {$businessData['clients']['total']}
- Returning Clients: {$businessData['clients']['repeat']}
- Retention Rate: " . number_format($businessData['clients']['retentionRate'], 1) . "%
- Client Lifetime Value: " . (isset($businessData['clients']['avgLifetimeValue']) ? 'R$ ' . number_format($businessData['clients']['avgLifetimeValue'], 2) : 'N/A') . "

📦 Inventory Status:
- Critical Stock Items: {$businessData['inventory']['lowStock']}
- Items Expiring Soon: {$businessData['inventory']['expiringSoon']}
- Total Inventory Value: R$ " . number_format($businessData['inventory']['costValue'], 2) . "
- Potential Profit Margin: R$ " . number_format($businessData['inventory']['potentialProfit'], 2) . "

💼 Service Portfolio:
- Active Services: {$businessData['services']['active']}
- Average Service Price: R$ " . number_format($businessData['services']['avgPrice'], 2) . "

**ANALYSIS FRAMEWORK:**
Use these strategic lenses:
1. **Financial Health**: Cash flow, revenue trends, pricing optimization
2. **Operational Excellence**: Capacity utilization, efficiency, inventory management
3. **Growth Opportunities**: Market expansion, service diversification, upselling
4. **Client Experience**: Retention strategies, loyalty programs, satisfaction
5. **Risk Management**: Inventory risks, receivables, market threats

**OUTPUT REQUIREMENTS:**
Generate 4-5 insights in JSON format. Each insight must:
- Be based on the actual data provided (cite specific numbers)
- Include a clear priority level based on impact and urgency
- Provide a specific, measurable action item
- Be written in professional Portuguese
- Focus on quick wins (low effort, high impact) or strategic priorities

**JSON Structure:**
{
  \"insights\": [
    {
      \"category\": \"financial|operational|marketing|inventory\",
      \"title\": \"Compelling title highlighting the key finding\",
      \"description\": \"Data-driven analysis explaining WHY this matters. Reference specific metrics. 2-3 sentences.\",
      \"priority\": \"high|medium|low\",
      \"action\": \"Specific, actionable step with clear outcome. Include timeline or metric to track.\"
    }
  ]
}

**PRIORITY GUIDELINES:**
- HIGH: Urgent issues (cash flow problems, critical stock, declining revenue >10%)
- MEDIUM: Important opportunities (growth potential, efficiency gains, retention improvements)
- LOW: Optimization ideas (minor improvements, long-term strategies)

**EXAMPLE OF GOOD INSIGHTS:**
{
  \\\"insights\\\": [
    {
      \\\"category\\\": \\\"financial\\\",
      \\\"title\\\": \\\"Oportunidade de Recuperação: R$ 2.450 em Recebíveis Pendentes\\\",
      \\\"description\\\": \\\"Você possui R$ 2.450 em pagamentos pendentes, representando 15% da receita do mês. Com uma taxa de inadimplência média de 8% no setor veterinário, isso pode impactar seu fluxo de caixa. Implementar lembretes automáticos pode recuperar 70% desse valor.\\\",
      \\\"priority\\\": \\\"high\\\",
      \\\"action\\\": \\\"Enviar lembretes de pagamento por WhatsApp/SMS para clientes com faturas vencidas há mais de 7 dias. Meta: recuperar R$ 1.700 em 15 dias.\\\"
    },
    {
      \\\"category\\\": \\\"marketing\\\",
      \\\"title\\\": \\\"Taxa de Retenção Excelente: Capitalize com Programa de Fidelidade\\\",
      \\\"description\\\": \\\"Sua taxa de retenção de 78% está acima da média do setor (65%). Clientes recorrentes gastam 3x mais ao longo do tempo. Um programa de pontos pode aumentar essa taxa para 85% e gerar R$ 3.000/mês adicionais.\\\",
      \\\"priority\\\": \\\"medium\\\",
      \\\"action\\\": \\\"Criar programa 'Cliente VIP': a cada 5 consultas, ganhe 1 banho grátis. Divulgar via WhatsApp para os 35 clientes mais frequentes esta semana.\\\"
    }
  ]
}

**IMPORTANT:** 
- Respond ONLY with valid JSON
- No markdown formatting, no code blocks
- Ensure all text is in Portuguese
- Make insights specific to veterinary/pet care industry
- Reference actual numbers from the data provided
- Each insight should feel personalized to THIS business's data";

        $response = Http::post("{$this->baseUrl}/models/gemini-2.5-flash:generateContent?key={$this->apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.5,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
            ]
        ]);

        $result = $response->json();
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $this->extractJson($result['candidates'][0]['content']['parts'][0]['text']);
            return json_decode($jsonText, true);
        }

        return ['insights' => []];
    }

    private function extractJson($text)
    {
        // Try to find JSON inside code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        // If no code blocks, try to find the first { and last }
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            return $matches[0];
        }

        return trim($text);
    }
}
