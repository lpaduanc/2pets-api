<?php

namespace App\Services\Fiscal\Sefaz;

use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use NFePHP\Common\Exception\ValidatorException;

/**
 * Fronteira de exceções de toda chamada ao sped-nfe que fala com a SEFAZ — spec docs/gap-petflow/
 * specs/fase4-transmissao-propria-nfe-nfce.md §7.3. XSD inválido é rejeição local
 * (`NfeXmlInvalid`); QUALQUER outra falha (timeout, SOAP fault, TLS, HTTP ≠ 200, webservice da UF
 * não encontrado) vira `FiscalProviderUnavailable` — o documento fica pendente e a máquina de
 * retentativa/contingência assume. Perder um documento por exceção não tratada é pior que retentar.
 */
final class SefazCalls
{
    private const MESSAGE_MAX_LENGTH = 300;

    /**
     * @param  callable(): string  $call
     *
     * @throws FiscalProviderUnavailable
     * @throws NfeXmlInvalid
     */
    public static function guard(callable $call): string
    {
        try {
            return $call();
        } catch (ValidatorException $invalid) {
            throw NfeXmlInvalid::because([$invalid->getMessage()]);
        } catch (FiscalProviderUnavailable|NfeXmlInvalid $known) {
            throw $known;
        } catch (\Throwable $failure) {
            throw new FiscalProviderUnavailable('SEFAZ: '.mb_substr($failure->getMessage(), 0, self::MESSAGE_MAX_LENGTH), previous: $failure);
        }
    }
}
