<?php

/**
 * Feature flags — V2/V3 features stay off in production until MVP ships.
 * Env defaults keep production safe; staging overrides via .env.
 *
 * Usage:
 *   - Backend: route middleware `feature:video_consultations`
 *   - Frontend: `GET /api/features` returns the public map
 */
return [
    // Beta launch flag — MVP goes out 100% free; paid plans come in fast-follow
    // once Stripe is wired with real keys. Turning this on:
    //   - reveals Premium/Essential/Pro in GET /subscriptions/plans
    //   - allows subscribe/upgrade to non-free plans
    //   - unhides the Subscription item in Settings
    'paid_plans' => env('FEATURE_PAID_PLANS', false),

    // V2
    'tutor_expenses' => env('FEATURE_TUTOR_EXPENSES', false),
    'advantage_club' => env('FEATURE_ADVANTAGE_CLUB', false),
    'loyalty' => env('FEATURE_LOYALTY', false),
    'clinic_erp' => env('FEATURE_CLINIC_ERP', true),  // Clinic system is partial MVP
    'petshop_erp' => env('FEATURE_PETSHOP_ERP', false),

    // V3
    'video_consultations' => env('FEATURE_VIDEO_CONSULTATIONS', false),
    'ai_guardian' => env('FEATURE_AI_GUARDIAN', false),
    'ai_business' => env('FEATURE_AI_BUSINESS', false),
    'marketplace' => env('FEATURE_MARKETPLACE', false),

    // Off-scope (não está na spec)
    'insurance' => env('FEATURE_INSURANCE', false),
    'advertising' => env('FEATURE_ADVERTISING', false),
    'social_media' => env('FEATURE_SOCIAL_MEDIA', false),
];
