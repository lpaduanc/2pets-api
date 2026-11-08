<?php

namespace App\Services\Fiscal\Sefaz;

/** Resposta da SEFAZ já classificada (`SefazReplyReader`). */
final readonly class SefazReply
{
    public function __construct(
        public SefazOutcome $outcome,
        public string $status,
        public string $message,
        public ?string $protocol = null,
        public ?string $receipt = null,
    ) {}

    /** "cStat - xMotivo", o que vai para `rejection_reason`/`last_error`. */
    public function reason(): string
    {
        return trim("{$this->status} - {$this->message}", ' -');
    }
}
