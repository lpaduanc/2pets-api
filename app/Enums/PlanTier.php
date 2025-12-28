<?php

namespace App\Enums;

enum PlanTier: string
{
    case BASIC = 'basic';
    case PRO = 'pro';
    case ENTERPRISE = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::BASIC => 'Básico',
            self::PRO => 'Profissional',
            self::ENTERPRISE => 'Empresarial',
        };
    }

    public function features(): array
    {
        return match ($this) {
            self::BASIC => [
                'basic_profile',
                'appointment_management',
                'basic_reports',
            ],
            self::PRO => [
                'basic_profile',
                'appointment_management',
                'basic_reports',
                'advanced_profile',
                'online_booking',
                'sms_notifications',
                'advanced_reports',
                'priority_support',
            ],
            self::ENTERPRISE => [
                'basic_profile',
                'appointment_management',
                'basic_reports',
                'advanced_profile',
                'online_booking',
                'sms_notifications',
                'advanced_reports',
                'priority_support',
                'multiple_locations',
                'team_management',
                'api_access',
                'custom_branding',
                'dedicated_support',
            ],
        };
    }

    public function limits(): array
    {
        return match ($this) {
            self::BASIC => [
                'appointments_per_month' => 50,
                'ai_queries_per_month' => 10,
                'storage_gb' => 1,
                'team_members' => 1,
            ],
            self::PRO => [
                'appointments_per_month' => 200,
                'ai_queries_per_month' => 100,
                'storage_gb' => 10,
                'team_members' => 5,
            ],
            self::ENTERPRISE => [
                'appointments_per_month' => -1, // Unlimited
                'ai_queries_per_month' => -1,
                'storage_gb' => 50,
                'team_members' => -1,
            ],
        };
    }
}

