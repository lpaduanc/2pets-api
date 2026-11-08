<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Upload do A1 — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §3: abre com a
 * senha, está na validade, CNPJ da organização (ou da matriz, mesma raiz), guardado cifrado, nunca
 * devolvido nem logado, só o dono, com trilha de auditoria.
 */
class FiscalCertificateUploadTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    private const URL = '/api/professional/fiscal-settings/certificate';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSefazClinic(withCertificate: false, withCsc: false);
        Event::listen(MessageLogged::class, fn (MessageLogged $message) => $this->logged[] = $message->message.json_encode($message->context));
    }

    public function test_the_owner_uploads_a_valid_certificate_and_only_metadata_comes_back(): void
    {
        $pfx = TestCertificate::pfx(self::CLINIC_CNPJ);

        $response = $this->upload($pfx)->assertCreated()
            ->assertJsonPath('data.subject_document', self::CLINIC_CNPJ)
            ->assertJsonPath('data.cnpj_match', 'exact')
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.expires_soon', false);

        $this->assertGreaterThanOrEqual(363, $response->json('data.days_to_expire'));
        $this->assertSecretsAbsent($response->getContent(), $pfx);
        $stored = FiscalCertificate::query()->where('organization_id', $this->clinic->id)->firstOrFail();
        // Cifrado em repouso: nem os bytes nem a senha aparecem no banco.
        $this->assertStringNotContainsString(base64_encode($pfx), $stored->pfx_ciphertext);
        $this->assertStringNotContainsString(TestCertificate::PASSWORD, $stored->password_ciphertext);
        $this->assertSame('fiscal_certificates:'.$stored->id, $this->clinic->fresh()->certificate_ref);
        $this->assertTrue($this->clinic->fresh()->certificate_expires_at->equalTo($stored->valid_to));
    }

    public function test_the_metadata_endpoint_never_exposes_the_file_the_password_or_the_reference(): void
    {
        $pfx = TestCertificate::pfx(self::CLINIC_CNPJ);
        $this->upload($pfx)->assertCreated();

        $response = $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.subject_document', self::CLINIC_CNPJ);
        foreach (['pfx_ciphertext', 'password_ciphertext', 'certificate_ref', 'password', 'pfx'] as $field) {
            $response->assertJsonMissingPath("data.{$field}");
        }
        $this->assertSecretsAbsent($response->getContent(), $pfx);
    }

    public function test_a_branch_may_use_the_certificate_of_its_headquarters_with_the_same_cnpj_root(): void
    {
        $this->clinic->forceFill(['cnpj' => '11222333000262'])->save();

        $this->upload(TestCertificate::pfx(self::CLINIC_CNPJ))->assertCreated()->assertJsonPath('data.cnpj_match', 'root');
    }

    public function test_a_wrong_password_is_refused_without_echoing_it(): void
    {
        $response = $this->upload(TestCertificate::pfx(self::CLINIC_CNPJ), 'senha-errada-999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertStringNotContainsString('senha-errada-999', $response->getContent());
        $this->assertDatabaseCount('fiscal_certificates', 0);
        $this->assertNoLogContains('senha-errada-999');
    }

    public function test_an_expired_certificate_is_refused(): void
    {
        $pfx = TestCertificate::pfx(self::CLINIC_CNPJ, validDays: 1);
        $this->travel(3)->days();

        $this->upload($pfx)->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->assertDatabaseCount('fiscal_certificates', 0);
    }

    public function test_a_certificate_of_another_company_is_refused(): void
    {
        $this->upload(TestCertificate::pfx('99888777000166'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $this->assertDatabaseCount('fiscal_certificates', 0);
    }

    public function test_only_pfx_or_p12_files_are_accepted(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->post(self::URL, ['certificate' => UploadedFile::fake()->createWithContent('cert.txt', 'not a certificate'), 'password' => 'x'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');
    }

    public function test_receptionist_and_groomer_cannot_upload_view_or_remove(): void
    {
        foreach ([$this->receptionist, $this->groomer] as $user) {
            $this->actingAs($user, 'sanctum')->getJson(self::URL)->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->post(self::URL, ['certificate' => $this->file(TestCertificate::pfx(self::CLINIC_CNPJ)), 'password' => TestCertificate::PASSWORD], ['Accept' => 'application/json'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson(self::URL)->assertForbidden();
        }

        $this->assertDatabaseCount('fiscal_certificates', 0);
    }

    public function test_replacing_and_removing_are_audited_with_metadata_only(): void
    {
        $this->upload(TestCertificate::pfx(self::CLINIC_CNPJ))->assertCreated();
        $this->upload(TestCertificate::pfx(self::CLINIC_CNPJ))->assertCreated();
        $this->assertDatabaseCount('fiscal_certificates', 1);

        $this->actingAs($this->owner, 'sanctum')->deleteJson(self::URL)->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('fiscal_certificates', 0);
        $this->assertNull($this->clinic->fresh()->certificate_ref);

        $events = Activity::query()->where('log_name', 'fiscal')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['fiscal_certificate.uploaded', 'fiscal_certificate.uploaded', 'fiscal_certificate.removed'], $events);
        $properties = Activity::query()->where('log_name', 'fiscal')->get()->map(fn (Activity $activity): string => json_encode($activity->properties))->implode('|');
        $this->assertStringNotContainsString(TestCertificate::PASSWORD, $properties);
        $this->assertStringContainsString('fingerprint', $properties);
        $this->assertNoLogContains(TestCertificate::PASSWORD);
    }

    private function upload(string $pfx, string $password = TestCertificate::PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->post(self::URL, ['certificate' => $this->file($pfx), 'password' => $password], ['Accept' => 'application/json']);
    }

    private function file(string $pfx): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);
    }

    private function assertSecretsAbsent(string $content, string $pfx): void
    {
        $this->assertStringNotContainsString(TestCertificate::PASSWORD, $content);
        $this->assertStringNotContainsString(base64_encode($pfx), $content);
        $this->assertStringNotContainsString('ciphertext', $content);
        $this->assertStringNotContainsString('fiscal_certificates:', $content);
    }

    private function assertNoLogContains(string $secret): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($secret, $line);
        }
    }
}
