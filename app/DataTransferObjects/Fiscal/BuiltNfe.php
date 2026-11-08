<?php

namespace App\DataTransferObjects\Fiscal;

/** XML da NF-e/NFC-e montado (ainda sem assinatura) e a chave de acesso que o sped-nfe calculou. */
final readonly class BuiltNfe
{
    public function __construct(public string $xml, public string $accessKey) {}
}
