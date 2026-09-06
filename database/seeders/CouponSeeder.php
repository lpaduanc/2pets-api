<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            [
                'code' => 'PETS10',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'min_amount' => null,
                'max_uses' => 1000,
                'expires_at' => now()->addMonths(6),
            ],
            [
                'code' => 'PRIMEIRAVET',
                'discount_type' => 'fixed',
                'discount_value' => 50.00,
                'min_amount' => 100.00,
                'max_uses' => 500,
                'expires_at' => now()->addMonths(3),
            ],
            [
                'code' => '2PETS20',
                'discount_type' => 'percentage',
                'discount_value' => 20.00,
                'min_amount' => 50.00,
                'max_uses' => 200,
                'expires_at' => now()->addMonths(6),
            ],
        ];

        foreach ($coupons as $coupon) {
            Coupon::updateOrCreate(
                ['code' => $coupon['code']],
                $coupon
            );
        }
    }
}
