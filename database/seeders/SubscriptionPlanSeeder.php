<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Gratuito',
                'slug' => 'tutor-free',
                'tier' => 'basic',
                'monthly_price' => 0,
                'yearly_price' => 0,
                'trial_days' => 0,
                'is_active' => true,
                'sort_order' => 1,
                'features' => ['2_pets', 'basic_search', 'virtual_card', 'exam_history_6m', 'favorites_5'],
                'limits' => ['pets' => 2, 'favorites' => 5, 'exam_history_months' => 6],
            ],
            [
                'name' => 'Premium',
                'slug' => 'tutor-premium',
                'tier' => 'pro',
                'monthly_price' => 19.90,
                'yearly_price' => 199.00,
                'trial_days' => 7,
                'is_active' => true,
                'sort_order' => 2,
                'features' => ['unlimited_pets', 'advanced_search', 'virtual_card', 'unlimited_exam_history', 'unlimited_favorites', 'expense_control', 'full_rewards_club', 'whatsapp_alerts'],
                'limits' => ['pets' => -1, 'favorites' => -1, 'exam_history_months' => -1],
            ],
            [
                'name' => 'Gratuito',
                'slug' => 'professional-free',
                'tier' => 'basic',
                'monthly_price' => 0,
                'yearly_price' => 0,
                'trial_days' => 0,
                'is_active' => true,
                'sort_order' => 3,
                'features' => ['basic_profile', 'search_listing', 'appointments_10'],
                'limits' => ['appointments_per_month' => 10, 'staff_users' => 1],
            ],
            [
                'name' => 'Essencial',
                'slug' => 'professional-essential',
                'tier' => 'pro',
                'monthly_price' => 79.90,
                'yearly_price' => 799.00,
                'trial_days' => 7,
                'is_active' => true,
                'sort_order' => 4,
                'features' => ['full_profile', 'gallery', 'unlimited_appointments', 'one_system', 'basic_reports'],
                'limits' => ['appointments_per_month' => -1, 'staff_users' => 3],
            ],
            [
                'name' => 'Profissional',
                'slug' => 'professional-pro',
                'tier' => 'enterprise',
                'monthly_price' => 149.90,
                'yearly_price' => 1499.00,
                'trial_days' => 7,
                'is_active' => true,
                'sort_order' => 5,
                'features' => ['full_profile', 'gallery', 'unlimited_appointments', 'all_systems', 'full_reports', 'priority_support', 'search_boost', 'pro_badge'],
                'limits' => ['appointments_per_month' => -1, 'staff_users' => 10],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
