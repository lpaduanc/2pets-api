<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LoyaltyService
{
    // Points earning rules
    private const POINTS_APPOINTMENT_BOOKING = 10;
    private const POINTS_APPOINTMENT_COMPLETION = 50;
    private const POINTS_REVIEW_SUBMISSION = 25;
    private const POINTS_REFERRAL_SIGNUP = 100;
    private const POINTS_REFERRAL_FIRST_BOOKING = 200;

    public function getOrCreateAccount(User $user): LoyaltyAccount
    {
        return LoyaltyAccount::firstOrCreate(
            ['user_id' => $user->id],
            [
                'points_balance' => 0,
                'lifetime_points' => 0,
                'tier' => 'bronze',
            ]
        );
    }

    public function awardPointsForAppointmentBooking(User $user, int $appointmentId): void
    {
        $account = $this->getOrCreateAccount($user);

        $account->addPoints(
            self::POINTS_APPOINTMENT_BOOKING,
            'Appointment booking',
            'earned',
            ['type' => 'appointment', 'id' => $appointmentId]
        );
    }

    public function awardPointsForAppointmentCompletion(User $user, int $appointmentId): void
    {
        $account = $this->getOrCreateAccount($user);

        $account->addPoints(
            self::POINTS_APPOINTMENT_COMPLETION,
            'Appointment completed',
            'earned',
            ['type' => 'appointment', 'id' => $appointmentId]
        );
    }

    public function awardPointsForReview(User $user, int $reviewId): void
    {
        $account = $this->getOrCreateAccount($user);

        $account->addPoints(
            self::POINTS_REVIEW_SUBMISSION,
            'Review submission',
            'earned',
            ['type' => 'review', 'id' => $reviewId]
        );
    }

    public function createReferralCode(User $user): string
    {
        return strtoupper(Str::random(8));
    }

    public function processReferral(string $referralCode, User $newUser): ?Referral
    {
        $referrer = User::where('referral_code', $referralCode)->first();

        if (!$referrer) {
            return null;
        }

        $referral = Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $newUser->id,
            'referral_code' => $referralCode,
            'status' => 'pending',
        ]);

        // Award signup bonus
        $account = $this->getOrCreateAccount($referrer);
        $account->addPoints(
            self::POINTS_REFERRAL_SIGNUP,
            "Referral signup: {$newUser->name}",
            'bonus',
            ['type' => 'referral', 'id' => $referral->id]
        );

        return $referral;
    }

    public function completeReferral(Referral $referral): void
    {
        if ($referral->status !== 'pending') {
            return;
        }

        $referral->complete(self::POINTS_REFERRAL_FIRST_BOOKING);

        // Award first booking bonus
        $account = $this->getOrCreateAccount($referral->referrer);
        $account->addPoints(
            self::POINTS_REFERRAL_FIRST_BOOKING,
            "Referral first booking: {$referral->referred->name}",
            'bonus',
            ['type' => 'referral', 'id' => $referral->id]
        );

        $referral->update([
            'status' => 'rewarded',
            'points_awarded' => self::POINTS_REFERRAL_SIGNUP + self::POINTS_REFERRAL_FIRST_BOOKING,
        ]);
    }

    public function redeemReward(LoyaltyAccount $account, Reward $reward): RewardRedemption
    {
        if (!$reward->isAvailable()) {
            throw new \Exception('Reward is not available');
        }

        if ($account->points_balance < $reward->points_cost) {
            throw new \Exception('Insufficient points');
        }

        return DB::transaction(function () use ($account, $reward) {
            $account->deductPoints($reward->points_cost, "Redeemed: {$reward->name}");

            $redemption = RewardRedemption::create([
                'loyalty_account_id' => $account->id,
                'reward_id' => $reward->id,
                'points_spent' => $reward->points_cost,
                'status' => 'pending',
                'expires_at' => now()->addMonths(6),
            ]);

            $reward->decrementStock();

            return $redemption;
        });
    }

    public function getTierBenefits(string $tier): array
    {
        return match ($tier) {
            'bronze' => [
                'discount_percentage' => 0,
                'priority_booking' => false,
                'free_consultations' => 0,
            ],
            'silver' => [
                'discount_percentage' => 5,
                'priority_booking' => false,
                'free_consultations' => 0,
            ],
            'gold' => [
                'discount_percentage' => 10,
                'priority_booking' => true,
                'free_consultations' => 0,
            ],
            'platinum' => [
                'discount_percentage' => 15,
                'priority_booking' => true,
                'free_consultations' => 1,
            ],
            default => [],
        };
    }
}

