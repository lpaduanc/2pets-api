<?php

namespace Tests\Support\Fiscal;

use App\Enums\NfseLayout;
use App\Services\Fiscal\Nfse\NfseSchemaValidator;
use App\Services\Fiscal\Nfse\NfseXmlWriter;
use App\Services\Fiscal\Nfse\Transport\NfseHttpExchange;
use App\Services\Fiscal\Nfse\Transport\NfseNacionalTransport;
use App\Support\Money\Money;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Foundation\Application;
use NFePHP\Common\Certificate;
use RuntimeException;

/**
 * Dublê da API da Sefin Nacional: a suíte NUNCA fala com a API real. Responde como a Sefin a partir
 * do pedido — descompacta a DPS, confere no XSD do leiaute e gera a NFS-e (chave de 50 dígitos,
 * nNFSe sequencial, ISS de 2%) com a própria DPS dentro, como a Sefin faz.
 *
 *     $sefin = FakeNfseNacionalTransport::bind($this->app)
 *         ->script('emission', 'lost', 'E0300', 'http:503', new RuntimeException('timeout'));
 *
 * Operações: `emission` (POST /nfse), `dps` (GET /dps/{id}), `nfse` (GET /nfse/{chave}), `event`
 * (POST /nfse/{chave}/eventos). Roteiro: `accept`, `lost` (gera a NFS-e e perde a resposta),
 * `E0000` (recusa com esse código), `http:NNN`, ou uma exceção. Sem roteiro: aceita; DPS já usada
 * volta E0014, como na Sefin.
 */
final class FakeNfseNacionalTransport implements NfseNacionalTransport
{
    /** @var array<string, list<string|\Throwable>> */
    private array $scripts = [];

    /** @var list<array{operation: string, request: NfseHttpExchange, xml: string|null}> */
    public array $requests = [];

    /** @var array<string, string> Id da DPS => chave da NFS-e gerada */
    private array $keysByDps = [];

    /** @var array<string, string> chave => XML da NFS-e */
    private array $nfses = [];

    private int $sequence = 0;

    public static function bind(Application $app): self
    {
        $fake = new self;
        $app->instance(NfseNacionalTransport::class, $fake);

        return $fake;
    }

    public function script(string $operation, string|\Throwable ...$responses): self
    {
        $this->scripts[$operation] = [...($this->scripts[$operation] ?? []), ...$responses];

        return $this;
    }

    /** @return list<string> XML enviado (descompactado) numa operação */
    public function xmlFor(string $operation): array
    {
        return array_values(array_map(fn (array $entry): string => (string) $entry['xml'], array_filter($this->requests, fn (array $entry): bool => $entry['operation'] === $operation)));
    }

    /** @return list<NfseHttpExchange> */
    public function requestsFor(string $operation): array
    {
        return array_values(array_map(fn (array $entry): NfseHttpExchange => $entry['request'], array_filter($this->requests, fn (array $entry): bool => $entry['operation'] === $operation)));
    }

    public function send(NfseHttpExchange $request, Certificate $certificate): NfseHttpExchange
    {
        $operation = self::operation($request);
        $xml = self::unpack($request->json);
        $this->requests[] = ['operation' => $operation, 'request' => $request, 'xml' => $xml];
        $this->scripts[$operation] ??= [];
        $scripted = array_shift($this->scripts[$operation]) ?? 'accept';

        if ($scripted instanceof \Throwable) {
            throw $scripted;
        }

        return $this->answer($request, $operation, $scripted, $xml);
    }

    private function answer(NfseHttpExchange $request, string $operation, string $scripted, ?string $xml): NfseHttpExchange
    {
        return match (true) {
            str_starts_with($scripted, 'http:') => $request->answered((int) substr($scripted, 5), '<html>Service Unavailable</html>'),
            str_starts_with($scripted, 'E') => self::rejection($request, $scripted),
            $operation === 'emission' => $this->emit($request, (string) $xml, $scripted === 'lost'),
            $operation === 'dps' => $this->findDps($request),
            $operation === 'nfse' => $this->findNfse($request),
            default => $request->answered(201, (string) json_encode(['eventoXmlGZipB64' => self::pack(self::event((string) $xml))])),
        };
    }

    private function emit(NfseHttpExchange $request, string $dps, bool $lose): NfseHttpExchange
    {
        $layout = str_contains($dps, '<finNFSe>') ? NfseLayout::REFORM_20261020 : NfseLayout::PRODUCTION_20260209;
        app(NfseSchemaValidator::class)->validate($dps, $layout, NfseSchemaValidator::DPS);
        $dpsId = self::match('/Id="(DPS\d{42})"/', $dps);

        if (isset($this->keysByDps[$dpsId])) {
            return self::rejection($request, 'E0014');
        }

        [$key, $nfse] = $this->generate($dps);
        $this->keysByDps[$dpsId] = $key;
        $this->nfses[$key] = $nfse;

        if ($lose) {
            throw new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds');
        }

        return $request->answered(201, (string) json_encode([
            'tipoAmbiente' => 2, 'versaoAplicativo' => 'SefinNacional_fake', 'dataHoraProcessamento' => now()->toIso8601String(),
            'idDps' => $dpsId, 'chaveAcesso' => $key, 'nfseXmlGZipB64' => self::pack($nfse), 'alertas' => null,
        ]));
    }

    private function findDps(NfseHttpExchange $request): NfseHttpExchange
    {
        $key = $this->keysByDps[basename($request->url)] ?? null;

        return $key === null ? $request->answered(404, '') : $request->answered(200, (string) json_encode(['idDps' => basename($request->url), 'chaveAcesso' => $key]));
    }

    private function findNfse(NfseHttpExchange $request): NfseHttpExchange
    {
        $xml = $this->nfses[basename($request->url)] ?? null;

        return $xml === null ? self::rejection($request, 'E2000') : $request->answered(200, (string) json_encode(['chaveAcesso' => basename($request->url), 'nfseXmlGZipB64' => self::pack($xml)]));
    }

    /** @return array{0: string, 1: string} chave e XML da NFS-e */
    private function generate(string $dps): array
    {
        $this->sequence++;
        $city = self::match('/<cLocEmi>(\d{7})<\/cLocEmi>/', $dps);
        $cnpj = self::match('/<prest><CNPJ>(\d{14})<\/CNPJ>/', $dps);
        $key = $city.'22'.$cnpj.str_pad((string) $this->sequence, 13, '0', STR_PAD_LEFT).now()->format('ym').'123456789'.'7';
        $base = Money::of(self::match('/<vServ>([\d.]+)<\/vServ>/', $dps))->minus(preg_match('/<vDescIncond>([\d.]+)</', $dps, $m) ? $m[1] : '0');

        return [$key, self::nfse($key, $this->sequence, $dps, $base)];
    }

    private static function nfse(string $key, int $number, string $dps, Money $base): string
    {
        $writer = new NfseXmlWriter;
        $root = $writer->root('NFSe', ['versao' => '1.01']);
        $info = $writer->group($root, 'infNFSe', ['Id' => 'NFS'.$key]);
        $fields = ['xLocEmi' => 'São Paulo', 'xLocPrestacao' => 'São Paulo', 'nNFSe' => (string) $number, 'cLocIncid' => '3550308', 'xLocIncid' => 'São Paulo',
            'xTribNac' => 'Medicina veterinária e zootecnia.', 'verAplic' => 'SefinNacional_fake', 'ambGer' => '2', 'tpEmis' => '1', 'cStat' => '100',
            'dhProc' => now()->format('Y-m-d\TH:i:sP'), 'nDFSe' => (string) $number];
        array_walk($fields, fn (string $value, string $name) => $writer->add($info, $name, $value));
        $emitter = $writer->group($info, 'emit');
        $writer->add($emitter, 'CNPJ', substr($key, 9, 14));
        $writer->add($emitter, 'xNome', 'CLINICA VETERINARIA TESTE LTDA');
        $address = $writer->group($emitter, 'enderNac');
        array_map(fn (array $pair) => $writer->add($address, $pair[0], $pair[1]), [['xLgr', 'Rua Augusta'], ['nro', '100'], ['xBairro', 'Consolacao'], ['cMun', '3550308'], ['UF', 'SP'], ['CEP', '01305000']]);
        $values = $writer->group($info, 'valores');
        $writer->add($values, 'vBC', $base->toString());
        $writer->add($values, 'pAliqAplic', '2.00');
        $writer->add($values, 'vISSQN', $base->percentage('2')->toString());
        $writer->add($values, 'vLiq', $base->toString());

        return self::withDps($writer->xml(), $dps);
    }

    /** A NFS-e leva a DPS assinada inteira dentro do `infNFSe`. */
    private static function withDps(string $nfse, string $dps): string
    {
        $document = new DOMDocument;
        $document->loadXML($nfse);
        $dpsDocument = new DOMDocument;
        $dpsDocument->loadXML($dps);
        $info = (new DOMXPath($document))->query('//*[local-name()="infNFSe"]')->item(0);
        $info->appendChild($document->importNode($dpsDocument->documentElement, true));

        return (string) $document->saveXML($document->documentElement);
    }

    private static function event(string $request): string
    {
        $writer = new NfseXmlWriter;
        $root = $writer->root('evento', ['versao' => '1.01']);
        $info = $writer->group($root, 'infEvento', ['Id' => 'EVT'.self::match('/Id="PRE(\d{56})"/', $request).'001']);
        $writer->add($info, 'verAplic', 'SefinNacional_fake');
        $writer->add($info, 'nSeqEvento', '1');

        return $writer->xml();
    }

    private static function rejection(NfseHttpExchange $request, string $code): NfseHttpExchange
    {
        return $request->answered(400, (string) json_encode(['Erros' => [['Codigo' => $code, 'Descricao' => self::MESSAGES[$code] ?? 'Rejeição de teste', 'Complemento' => null]]]));
    }

    private const MESSAGES = [
        'E0014' => 'Conjunto de Série, Número, Código do Município Emissor e CNPJ/CPF informado nesta DPS já existe em uma NFS-e gerada a partir de uma DPS enviada anteriormente.',
        'E0300' => 'Código de tributação nacional inexistente.',
        'E0822' => 'O prazo para o cancelamento da NFS-e expirou, conforme parametrização do município emissor da NFS-e.',
    ];

    private static function operation(NfseHttpExchange $request): string
    {
        return match (true) {
            str_ends_with($request->url, '/eventos') => 'event',
            str_contains($request->url, '/dps/') => 'dps',
            $request->method === NfseHttpExchange::GET => 'nfse',
            default => 'emission',
        };
    }

    /** @param  array<string, string>|null  $json */
    private static function unpack(?array $json): ?string
    {
        $packed = $json === null ? null : (array_values($json)[0] ?? null);

        return $packed === null ? null : (string) gzdecode((string) base64_decode($packed));
    }

    private static function pack(string $xml): string
    {
        return base64_encode((string) gzencode($xml));
    }

    private static function match(string $pattern, string $subject): string
    {
        preg_match($pattern, $subject, $match);

        return $match[1] ?? throw new RuntimeException("Pedido sem o padrão {$pattern}");
    }
}
