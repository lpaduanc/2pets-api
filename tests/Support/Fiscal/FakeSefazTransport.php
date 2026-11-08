<?php

namespace Tests\Support\Fiscal;

use App\Services\Fiscal\Sefaz\SefazTransportFactory;
use Illuminate\Contracts\Foundation\Application;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Soap\SoapBase;
use NFePHP\Common\Soap\SoapInterface;

/**
 * Dublê do transporte SOAP da SEFAZ: a suíte NUNCA fala com a SEFAZ real. Responde como a SEFAZ
 * (envelope SOAP 1.2 + retEnviNFe/retConsSitNFe/retConsReciNFe/retEnvEvento/retInutNFe) a partir
 * do próprio pedido — chave e DigestValue do XML assinado viram o `protNFe`, como na SEFAZ, então
 * o `Complements::toAuthorize` do sped-nfe monta o procNFe de verdade.
 *
 *     $sefaz = FakeSefazTransport::bind($this->app)
 *         ->script('authorization', '302', new SoapException('timeout'));
 *
 * Operações: `authorization` (enviNFe), `query` (consSitNFe), `receipt` (consReciNFe), `event`
 * (envEvento), `invalidation` (inutNFe). Sem roteiro: autoriza/registra/homologa.
 */
final class FakeSefazTransport extends SoapBase implements SefazTransportFactory, SoapInterface
{
    private const OPERATIONS = ['<enviNFe' => 'authorization', '<consSitNFe' => 'query', '<consReciNFe' => 'receipt', '<envEvento' => 'event', '<inutNFe' => 'invalidation'];

    private const MESSAGES = [
        '100' => 'Autorizado o uso da NF-e', '102' => 'Inutilizacao de numero homologado', '103' => 'Lote recebido com sucesso',
        '104' => 'Lote processado', '108' => 'Servico Paralisado Momentaneamente (curto prazo)', '135' => 'Evento registrado e vinculado a NF-e',
        '204' => 'Rejeicao: Duplicidade de NF-e', '225' => 'Rejeicao: Falha no Schema XML da NFe', '302' => 'Uso Denegado: Irregularidade fiscal do destinatario',
        '501' => 'Rejeicao: Prazo de cancelamento superior ao previsto na Legislacao', '539' => 'Rejeicao: Duplicidade de NF-e com diferenca na Chave de Acesso',
    ];

    /** @var array<string, list<string|\Throwable>> */
    private array $scripts = [];

    /** @var list<array{operation: string, request: string}> */
    public array $requests = [];

    /** @var array<string, string> chave => DigestValue do último XML enviado */
    private array $digests = [];

    private int $protocolSequence = 0;

    public static function bind(Application $app): self
    {
        $fake = new self;
        $app->instance(SefazTransportFactory::class, $fake);

        return $fake;
    }

    public function make(Certificate $certificate): SoapInterface
    {
        $this->loadCertificate($certificate);

        return $this;
    }

    public function script(string $operation, string|\Throwable ...$responses): self
    {
        $this->scripts[$operation] = [...($this->scripts[$operation] ?? []), ...$responses];

        return $this;
    }

    /** @return list<string> pedidos de uma operação (corpo da mensagem) */
    public function requestsFor(string $operation): array
    {
        return array_values(array_map(fn (array $entry): string => $entry['request'], array_filter($this->requests, fn (array $entry): bool => $entry['operation'] === $operation)));
    }

    public static function timeoutFault(): SoapException
    {
        return SoapException::soapFault('Operation timed out after 20001 milliseconds [https://homologacao.nfe.fazenda.sp.gov.br]', 28);
    }

    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null)
    {
        $name = $this->operationOf((string) $request);
        $this->requests[] = ['operation' => $name, 'request' => (string) $request];
        $this->scripts[$name] ??= [];
        $next = array_shift($this->scripts[$name]);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $this->envelope($this->{$name}((string) $request, $next));
    }

    private function operationOf(string $request): string
    {
        foreach (self::OPERATIONS as $marker => $name) {
            if (str_contains($request, $marker)) {
                return $name;
            }
        }

        throw new \RuntimeException('Pedido SEFAZ não reconhecido pelo dublê.');
    }

    private function authorization(string $request, ?string $status): string
    {
        preg_match('/Id="NFe(\d{44})"/', $request, $key);
        preg_match('/<DigestValue>([^<]+)<\/DigestValue>/', $request, $digest);
        $this->digests[$key[1]] = $digest[1];

        return $this->batch('retEnviNFe', $key[1], $status ?? '100');
    }

    private function query(string $request, ?string $status): string
    {
        preg_match('/<chNFe>(\d{44})<\/chNFe>/', $request, $key);

        return $this->batch('retConsSitNFe', $key[1], $status ?? '100', topLevelIsDocument: true);
    }

    private function receipt(string $request, ?string $status): string
    {
        return $this->batch('retConsReciNFe', (string) array_key_last($this->digests), $status ?? '100');
    }

    /** Status de lote (103/105/108/204) volta sem protNFe; status de documento volta dentro do protNFe. */
    private function batch(string $root, string $key, string $status, bool $topLevelIsDocument = false): string
    {
        $head = '<tpAmb>2</tpAmb><verAplic>SP_NFE_PL009_V4</verAplic>';

        if (in_array($status, ['103', '105', '108', '109', '204'], true)) {
            $receipt = $status === '103' ? '<infRec><nRec>351000000000777</nRec><tMed>1</tMed></infRec>' : '';

            return sprintf('<%1$s xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">%2$s<cStat>%3$s</cStat><xMotivo>%4$s</xMotivo><cUF>35</cUF>%5$s</%1$s>', $root, $head, $status, self::MESSAGES[$status] ?? 'Status', $receipt);
        }

        $top = $topLevelIsDocument ? $status : '104';

        return sprintf('<%1$s xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">%2$s<cStat>%3$s</cStat><xMotivo>%4$s</xMotivo><cUF>35</cUF>%5$s</%1$s>', $root, $head, $top, self::MESSAGES[$top] ?? 'Status', $this->protocolGroup($key, $status));
    }

    private function protocolGroup(string $key, string $status): string
    {
        $granted = in_array($status, ['100', '150', '120', '110', '301', '302', '303'], true);
        $proof = $granted ? sprintf('<nProt>%s</nProt><digVal>%s</digVal>', $this->nextProtocol(), $this->digests[$key] ?? '') : '';

        return sprintf('<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>SP_NFE_PL009_V4</verAplic><chNFe>%s</chNFe><dhRecbto>2026-11-08T10:00:00-03:00</dhRecbto>%s<cStat>%s</cStat><xMotivo>%s</xMotivo></infProt></protNFe>', $key, $proof, $status, self::MESSAGES[$status] ?? 'Rejeicao');
    }

    private function event(string $request, ?string $status): string
    {
        $status ??= '135';
        preg_match('/<chNFe>(\d{44})<\/chNFe>/', $request, $key);
        preg_match('/<idLote>(\d+)<\/idLote>/', $request, $batch);
        $proof = $status === '135' ? sprintf('<dhRegEvento>2026-11-08T10:05:00-03:00</dhRegEvento><nProt>%s</nProt>', $this->nextProtocol()) : '';
        $inner = sprintf('<retEvento versao="1.00"><infEvento><tpAmb>2</tpAmb><verAplic>SP_EVENTOS_PL_100</verAplic><cOrgao>35</cOrgao><cStat>%s</cStat><xMotivo>%s</xMotivo><chNFe>%s</chNFe><tpEvento>110111</tpEvento><xEvento>Cancelamento registrado</xEvento><nSeqEvento>1</nSeqEvento>%s</infEvento></retEvento>', $status, self::MESSAGES[$status] ?? 'Rejeicao', $key[1], $proof);

        return sprintf('<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>%s</idLote><tpAmb>2</tpAmb><verAplic>SP_EVENTOS_PL_100</verAplic><cOrgao>35</cOrgao><cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo>%s</retEnvEvento>', $batch[1], $inner);
    }

    /** Ecoa os campos do pedido: o `Complements` confere cada um antes de montar o procInutNFe. */
    private function invalidation(string $request, ?string $status): string
    {
        $status ??= '102';
        $fields = '';

        foreach (['tpAmb', 'cUF', 'ano', 'CNPJ', 'mod', 'serie', 'nNFIni', 'nNFFin'] as $tag) {
            preg_match("/<{$tag}>([^<]*)<\/{$tag}>/", $request, $match);
            $fields .= "<{$tag}>{$match[1]}</{$tag}>";
        }

        $proof = $status === '102' ? sprintf('<dhRecbto>2026-11-08T10:10:00-03:00</dhRecbto><nProt>%s</nProt>', $this->nextProtocol()) : '';

        return sprintf('<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><infInut><tpAmb>2</tpAmb><verAplic>SP_NFE_PL009_V4</verAplic><cStat>%s</cStat><xMotivo>%s</xMotivo>%s%s</infInut></retInutNFe>', $status, self::MESSAGES[$status] ?? 'Rejeicao', $fields, $proof);
    }

    private function nextProtocol(): string
    {
        return sprintf('1352600000%05d', ++$this->protocolSequence);
    }

    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeResultMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl">'.$body.'</nfeResultMsg></soap:Body></soap:Envelope>';
    }
}
