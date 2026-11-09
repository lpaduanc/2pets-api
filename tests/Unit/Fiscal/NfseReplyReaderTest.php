<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Nfse\NfseReply;
use App\Services\Fiscal\Nfse\NfseReplyReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Classificação das respostas da Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6. Os nomes dos campos não constam do manual
 * oficial: a leitura tem de aceitar variações de caixa e erro único ou em lista.
 */
class NfseReplyReaderTest extends TestCase
{
    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string}> */
    public static function authorizationReplies(): array
    {
        $nfse = ['chaveAcesso' => str_repeat('1', 50), 'nfseXmlGZipB64' => base64_encode((string) gzencode('<NFSe/>'))];

        return [
            'gerada' => [201, $nfse, NfseReplyReader::ACCEPTED],
            'recusa em lista, caixa alta' => [400, ['Erros' => [['Codigo' => 'E0300', 'Descricao' => 'Código inexistente']]], NfseReplyReader::REJECTED],
            'recusa única, caixa baixa' => [400, ['erro' => ['codigo' => 'E0008', 'descricao' => 'dhEmi posterior']], NfseReplyReader::REJECTED],
            'DPS já usada' => [400, ['erros' => [['codigo' => 'E0014', 'descricao' => 'Duplicidade']]], NfseReplyReader::DUPLICATE],
            '400 sem lista conhecida' => [400, ['title' => 'One or more validation errors occurred.'], NfseReplyReader::REJECTED],
            'certificado recusado' => [403, ['message' => 'Forbidden'], NfseReplyReader::UNAVAILABLE],
            '2xx sem a NFS-e' => [200, ['idDps' => 'DPS1'], NfseReplyReader::UNAVAILABLE],
            'XML corrompido' => [201, ['nfseXmlGZipB64' => 'não é base64'], NfseReplyReader::UNAVAILABLE],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('authorizationReplies')]
    public function test_authorization_replies_are_classified(int $status, array $body, string $expected): void
    {
        $this->assertSame($expected, (new NfseReplyReader)->authorization(new NfseReply($status, $body, true)));
    }

    public function test_reason_lists_every_error_with_code_description_and_complement(): void
    {
        $reply = new NfseReply(400, ['erros' => [
            ['Codigo' => 'E0300', 'Descricao' => 'Código de tributação nacional inexistente.', 'Complemento' => 'cTribNac 999999'],
            ['Codigo' => 'E0712', 'Descricao' => 'Para ME/EPP indTotTrib nunca poderá ser informado.', 'Complemento' => null],
        ]], true);

        $this->assertSame(
            'E0300 - Código de tributação nacional inexistente. (cTribNac 999999); E0712 - Para ME/EPP indTotTrib nunca poderá ser informado.',
            (new NfseReplyReader)->reason($reply),
        );
    }

    public function test_alerts_and_access_key_are_read_and_invalid_keys_ignored(): void
    {
        $reply = new NfseReply(201, ['ChaveAcesso' => str_repeat('7', 50), 'Alertas' => [['Codigo' => 'A001', 'Descricao' => 'Atenção ao prazo']]], true);
        $invalid = new NfseReply(201, ['chaveAcesso' => '123'], true);

        $this->assertSame(str_repeat('7', 50), $reply->accessKey());
        $this->assertSame(['A001 - Atenção ao prazo'], $reply->alerts());
        $this->assertNull($invalid->accessKey());
    }

    public function test_event_reply_is_accepted_on_success_and_refused_with_errors(): void
    {
        $reader = new NfseReplyReader;

        $this->assertSame(NfseReplyReader::ACCEPTED, $reader->event(new NfseReply(201, ['eventoXmlGZipB64' => base64_encode((string) gzencode('<evento/>'))], true)));
        $this->assertSame(NfseReplyReader::REJECTED, $reader->event(new NfseReply(400, ['erros' => [['codigo' => 'E0822', 'descricao' => 'Prazo expirado']]], true)));
        $this->assertSame(NfseReplyReader::UNAVAILABLE, $reader->event(new NfseReply(401, [], true)));
    }
}
