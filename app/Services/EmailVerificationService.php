<?php

namespace App\Services;

use App\Mail\VerifyEmail;
use App\Models\EmailVerificationThrottle;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EmailVerificationService
{
    /**
     * Send verification email with throttling
     */
    public function sendVerificationEmail(User $user): void
    {
        // Check throttle
        if (!$this->canSendEmail($user->email)) {
            throw new TooManyRequestsHttpException(3600, 'Too many verification emails sent. Please wait.');
        }

        // Generate token
        $token = Str::random(64);
        $user->update([
            'email_verification_token' => $token,
            'email_verification_sent_at' => now(),
        ]);

        // Send email
        Mail::to($user->email)->send(new VerifyEmail($user, $token));

        // Update throttle
        $this->recordEmailSent($user->email);
    }

    /**
     * Check if email can be sent based on throttle rules
     */
    public function canSendEmail(string $email): bool
    {
        $throttle = EmailVerificationThrottle::where('email', $email)->first();

        if (!$throttle)
            return true;

        // Reset if past reset time
        if (now()->greaterThan($throttle->reset_at)) {
            $throttle->delete();
            return true;
        }

        // Check attempts (max 3 per hour)
        return $throttle->attempts < 3;
    }

    /**
     * Record an email send attempt
     */
    private function recordEmailSent(string $email): void
    {
        $throttle = EmailVerificationThrottle::firstOrNew(['email' => $email]);

        if ($throttle->exists && now()->lessThan($throttle->reset_at)) {
            $throttle->increment('attempts');
            $throttle->update(['last_attempt_at' => now()]);
        } else {
            $throttle->fill([
                'email' => $email,
                'attempts' => 1,
                'last_attempt_at' => now(),
                'reset_at' => now()->addHour(),
            ])->save();
        }
    }
}
