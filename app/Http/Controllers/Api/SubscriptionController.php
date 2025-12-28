<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Subscription\FeatureGateService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly FeatureGateService $featureGateService
    ) {}

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function current(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscriptions()
            ->where('status', 'active')
            ->orWhere('status', 'trialing')
            ->with('plan')
            ->first();

        if (!$subscription) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $subscription,
            'usage' => $subscription->usage()->get(),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        $subscription = $this->subscriptionService->subscribe(
            $request->user(),
            $plan,
            $validated['billing_cycle']
        );

        return response()->json([
            'message' => 'Subscription created successfully',
            'data' => $subscription->load('plan'),
        ], 201);
    }

    public function upgrade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $subscription = $request->user()->subscriptions()
            ->where('status', 'active')
            ->firstOrFail();

        $newPlan = SubscriptionPlan::findOrFail($validated['plan_id']);

        $updated = $this->subscriptionService->upgrade($subscription, $newPlan);

        return response()->json([
            'message' => 'Subscription upgraded successfully',
            'data' => $updated->load('plan'),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'immediately' => 'boolean',
        ]);

        $subscription = $request->user()->subscriptions()
            ->where('status', 'active')
            ->firstOrFail();

        $this->subscriptionService->cancel(
            $subscription,
            $validated['immediately'] ?? false
        );

        return response()->json(['message' => 'Subscription cancelled successfully']);
    }

    public function checkFeature(Request $request, string $feature): JsonResponse
    {
        $hasAccess = $this->featureGateService->canAccessFeature(
            $request->user(),
            $feature
        );

        return response()->json([
            'has_access' => $hasAccess,
            'feature' => $feature,
        ]);
    }

    public function checkUsage(Request $request, string $feature): JsonResponse
    {
        $canUse = $this->featureGateService->checkUsageLimit(
            $request->user(),
            $feature
        );

        $remaining = $this->featureGateService->getRemainingUsage(
            $request->user(),
            $feature
        );

        return response()->json([
            'can_use' => $canUse,
            'remaining' => $remaining,
            'feature' => $feature,
        ]);
    }
}

