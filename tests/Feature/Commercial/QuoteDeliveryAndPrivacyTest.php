<?php

namespace Tests\Feature\Commercial;

use App\Enums\VetAccessLevel;
use App\Models\NotificationPreference;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\QuoteReceivedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Entrega do orçamento por e-mail (link sem login + PDF) e o recorte de quem vê orçamento na
 * linha do tempo do animal — docs/gap-simplesvet/24-orcamentos.md.
 */
class QuoteDeliveryAndPrivacyTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_sending_emails_the_tutor_the_public_link_and_the_pdf(): void
    {
        Notification::fake();
        ['id' => $id] = $this->draftQuoteWithThreeItems();

        $token = $this->sendQuote($id);

        Notification::assertSentTo($this->tutor, QuoteReceivedMail::class, function (QuoteReceivedMail $mail) use ($token): bool {
            $message = $mail->toMail($this->tutor);

            $this->assertStringContainsString("/orcamento/{$token}", $message->actionUrl);
            $this->assertCount(1, $message->rawAttachments);
            $this->assertSame('application/pdf', $message->rawAttachments[0]['options']['mime']);
            $this->assertStringStartsWith('%PDF', $message->rawAttachments[0]['data']);
            $this->assertStringContainsString($this->clinic->business_name, $message->subject);
            $this->assertStringNotContainsString('interno', implode(' ', $message->introLines));

            return true;
        });
    }

    public function test_a_tutor_who_turned_quote_emails_off_gets_no_email_but_still_gets_the_in_app_notice(): void
    {
        Notification::fake();
        NotificationPreference::create([
            'user_id' => $this->tutor->id,
            'notification_type' => 'quote_received',
            'channel' => 'email',
            'enabled' => false,
        ]);
        ['id' => $id] = $this->draftQuoteWithThreeItems();

        $this->sendQuote($id);

        Notification::assertNotSentTo($this->tutor, QuoteReceivedMail::class);
        Notification::assertSentTo($this->tutor, \App\Notifications\InAppNotification::class);
    }

    public function test_a_vet_from_another_clinic_with_clinical_access_does_not_see_quote_values_in_the_timeline(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);

        $otherClinicVet = User::factory()->professional()->create();
        // Os dois têm acesso CLÍNICO ao animal; só um é da clínica que emitiu o orçamento.
        foreach ([$otherClinicVet, $this->owner] as $vet) {
            PetVetAccess::create([
                'pet_id' => $this->pet->id,
                'veterinarian_id' => $vet->id,
                'granted_by' => $this->tutor->id,
                'access_level' => VetAccessLevel::WRITE,
                'status' => PetVetAccess::STATUS_ACCEPTED,
                'is_active' => true,
                'granted_at' => now(),
                'responded_at' => now(),
            ]);
        }

        $types = fn (User $viewer) => collect(
            $this->actingAs($viewer, 'sanctum')->getJson("/api/pets/{$this->pet->id}/timeline")->assertOk()->json('data')
        )->where('type', 'quote')->pluck('id')->values()->all();

        $this->assertSame([], $types($otherClinicVet), 'clinical access does not grant commercial visibility');
        $this->assertSame([$id], $types($this->tutor));
        $this->assertSame([$id], $types($this->owner), 'the issuing clinic sees its own quote');

        // Sem acesso clínico, nem a própria clínica lê a linha do tempo (regra do histórico).
        $this->actingAs($this->groomer, 'sanctum')->getJson("/api/pets/{$this->pet->id}/timeline")->assertForbidden();
    }
}
