<?php

namespace App\Services\Fiscal\Sefaz;

use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use Illuminate\Support\Facades\File;

/**
 * Âncoras de confiança para VERIFICAR o servidor TLS das SEFAZ — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.5 (ameaça T9).
 *
 * Os webservices usam ora cadeia ICP-Brasil (SVRS, SP, PR, BA, MS, MT, PE, AM…), ora CA pública
 * (AN/SVAN com Let's Encrypt/GlobalSign, MG com Sectigo, PA), e isso muda com o tempo. Por isso o
 * bundle é a SOMA do trust store do sistema (`fiscal.sefaz.system_ca_bundle`) com as ACs da
 * ICP-Brasil versionadas no repositório (`fiscal.sefaz.ca_bundle`). A soma é gravada numa pasta
 * privada com o sha256 do conteúdo no nome: muda o bundle (deploy ou atualização do sistema),
 * muda o arquivo — nada de cache velho.
 *
 * Falha FECHADA: bundle ausente/ilegível nunca vira envio sem verificação; nada sai e a
 * prontidão mostra `sefaz_ca_bundle`.
 */
final class SefazServerTrust
{
    public const MISSING_CA_BUNDLE = 'sefaz_ca_bundle';

    private const PEM_MARKER = '-----BEGIN CERTIFICATE-----';

    private const FOLDER_MODE = 0700;

    /** Código de prontidão quando alguma das partes do bundle não pode ser lida. */
    public function problem(): ?string
    {
        return $this->unreadableSource() === null ? null : self::MISSING_CA_BUNDLE;
    }

    /**
     * Caminho do bundle combinado para o `CURLOPT_CAINFO`.
     *
     * @throws FiscalProviderUnavailable
     */
    public function bundlePath(): string
    {
        $unreadable = $this->unreadableSource();

        if ($unreadable !== null) {
            throw new FiscalProviderUnavailable("Bundle de CAs da SEFAZ ({$unreadable}) não encontrado ou sem certificados: nada foi enviado.");
        }

        return $this->combined(implode("\n", array_map(
            fn (string $path): string => trim((string) file_get_contents($path)),
            $this->sources(),
        ))."\n");
    }

    private function combined(string $contents): string
    {
        $folder = (string) config('fiscal.sefaz.trust_path');
        $path = sprintf('%s/sefaz-trust-%s.pem', rtrim($folder, '/'), hash('sha256', $contents));

        if (is_file($path)) {
            return $path;
        }

        File::ensureDirectoryExists($folder, self::FOLDER_MODE);
        // Grava num temporário e renomeia: outro processo nunca lê um bundle pela metade.
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        file_put_contents($temporary, $contents);
        rename($temporary, $path);

        return $path;
    }

    /** Chave de configuração da primeira parte ilegível (ou sem certificado), ou nulo. */
    private function unreadableSource(): ?string
    {
        foreach ($this->sources() as $key => $path) {
            if (! self::hasCertificates($path)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * ICP-Brasil é obrigatório; o trust store do sistema só fica de fora se a configuração vier
     * vazia de propósito (aí as UFs com CA pública falham na verificação — fechado, nunca aberto).
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $system = trim((string) config('fiscal.sefaz.system_ca_bundle'));
        $icpBrasil = ['fiscal.sefaz.ca_bundle' => trim((string) config('fiscal.sefaz.ca_bundle'))];

        return $system === '' ? $icpBrasil : ['fiscal.sefaz.system_ca_bundle' => $system] + $icpBrasil;
    }

    private static function hasCertificates(string $path): bool
    {
        return is_file($path) && is_readable($path) && str_contains((string) file_get_contents($path), self::PEM_MARKER);
    }
}
