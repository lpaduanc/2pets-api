<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Sefaz\SefazOutcome;
use App\Services\Fiscal\Sefaz\SefazReplyReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tabela de cStat — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §7.2. O lote
 * (103/104/105/108/109/204) e o documento (`protNFe`: 100/150/120 autoriza, 110/205/301/302/303
 * denega, o resto rejeita) são lidos do envelope SOAP inteiro.
 */
class SefazReplyReaderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string|null, 2: SefazOutcome}> */
    public static function authorizationReplies(): array
    {
        return [
            '100 autorizado' => ['104', '100', SefazOutcome::AUTHORIZED],
            '150 autorizado fora de prazo' => ['104', '150', SefazOutcome::AUTHORIZED],
            '120 autorizado com alerta' => ['104', '120', SefazOutcome::AUTHORIZED],
            '110 denegado' => ['104', '110', SefazOutcome::DENIED],
            '301 denegado emitente' => ['104', '301', SefazOutcome::DENIED],
            '302 denegado destinatário' => ['104', '302', SefazOutcome::DENIED],
            '225 schema' => ['104', '225', SefazOutcome::REJECTED],
            '539 chave diferente' => ['104', '539', SefazOutcome::REJECTED],
            '204 duplicidade no documento' => ['104', '204', SefazOutcome::DUPLICATE],
            '103 lote recebido' => ['103', null, SefazOutcome::PROCESSING],
            '105 em processamento' => ['105', null, SefazOutcome::PROCESSING],
            '108 paralisado' => ['108', null, SefazOutcome::UNAVAILABLE],
            '109 sem previsão' => ['109', null, SefazOutcome::UNAVAILABLE],
            '204 duplicidade no lote' => ['204', null, SefazOutcome::DUPLICATE],
            '215 schema do lote' => ['215', null, SefazOutcome::REJECTED],
        ];
    }

    #[DataProvider('authorizationReplies')]
    public function test_authorization_status_is_classified(string $batch, ?string $document, SefazOutcome $expected): void
    {
        $protocol = $document === null ? '' : "<protNFe versao=\"4.00\"><infProt><chNFe>1</chNFe><nProt>135260000000001</nProt><cStat>{$document}</cStat><xMotivo>Documento</xMotivo></infProt></protNFe>";
        $reply = (new SefazReplyReader)->authorization($this->envelope("<retEnviNFe><cStat>{$batch}</cStat><xMotivo>Lote</xMotivo><infRec><nRec>351000000000777</nRec></infRec>{$protocol}</retEnviNFe>"));

        $this->assertSame($expected, $reply->outcome);
        $this->assertSame($document ?? $batch, $reply->status);
    }

    public function test_an_empty_or_garbled_reply_is_a_transport_failure(): void
    {
        $this->assertSame(SefazOutcome::UNAVAILABLE, (new SefazReplyReader)->authorization('')->outcome);
        $this->assertSame(SefazOutcome::UNAVAILABLE, (new SefazReplyReader)->authorization('<html>502 Bad Gateway')->outcome);
    }

    public function test_the_receipt_and_the_protocol_are_extracted(): void
    {
        $reader = new SefazReplyReader;

        $this->assertSame('351000000000777', $reader->authorization($this->envelope('<retEnviNFe><cStat>103</cStat><xMotivo>Lote recebido</xMotivo><infRec><nRec>351000000000777</nRec></infRec></retEnviNFe>'))->receipt);
        $event = $reader->event($this->envelope('<retEnvEvento><cStat>128</cStat><xMotivo>Lote</xMotivo><retEvento><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo><nProt>135260000000009</nProt></infEvento></retEvento></retEnvEvento>'));
        $this->assertSame([SefazOutcome::AUTHORIZED, '135260000000009', '135 - Evento registrado'], [$event->outcome, $event->protocol, $event->reason()]);
        $this->assertSame(SefazOutcome::REJECTED, $reader->event($this->envelope('<retEnvEvento><cStat>128</cStat><xMotivo>Lote</xMotivo><retEvento><infEvento><cStat>501</cStat><xMotivo>Prazo</xMotivo></infEvento></retEvento></retEnvEvento>'))->outcome);
        $this->assertSame(SefazOutcome::AUTHORIZED, $reader->invalidation($this->envelope('<retInutNFe><infInut><cStat>102</cStat><xMotivo>Homologado</xMotivo><nProt>135260000000010</nProt></infInut></retInutNFe>'))->outcome);
        $this->assertSame(SefazOutcome::UNAVAILABLE, $reader->invalidation($this->envelope('<retInutNFe><infInut><cStat>108</cStat><xMotivo>Paralisado</xMotivo></infInut></retInutNFe>'))->outcome);
    }

    private function envelope(string $body): string
    {
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeResultMsg>'.str_replace('<ret', '<ret', $body).'</nfeResultMsg></soap:Body></soap:Envelope>';
    }
}
