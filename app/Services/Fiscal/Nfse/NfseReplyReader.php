<?php

namespace App\Services\Fiscal\Nfse;

/**
 * Classifica a resposta da Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6:
 *
 * - 2xx com a NFS-e (ou o evento) ⇒ aceito;
 * - lista de erros com E0014 ⇒ duplicidade (a DPS já gerou NFS-e: a resposta anterior se perdeu);
 * - lista de erros ⇒ recusa; 400/422 com JSON sem lista reconhecível ⇒ recusa com o corpo (enviar
 *   de novo o mesmo XML não muda nada);
 * - qualquer outra coisa (401/403 de certificado, 404 na emissão, 2xx sem o XML) ⇒ indisponível.
 */
final class NfseReplyReader
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const DUPLICATE = 'duplicate';

    public const UNAVAILABLE = 'unavailable';

    /** Série + número + município + CNPJ já geraram NFS-e (Anexo I, E0014). */
    private const DUPLICATE_DPS = 'E0014';

    private const CLIENT_ERRORS = [400, 422];

    private const REASON_MAX_LENGTH = 480;

    public function authorization(NfseReply $reply): string
    {
        return $this->classify($reply, $reply->nfseXml() !== null);
    }

    public function event(NfseReply $reply): string
    {
        return $this->classify($reply, $reply->isSuccess());
    }

    /** "E0000 - descrição (complemento)" de cada erro, separados por "; ". */
    public function reason(NfseReply $reply): string
    {
        $errors = array_map(
            fn (array $error): string => trim(sprintf('%s - %s%s', $error['code'], $error['description'], $error['complement'] === '' ? '' : " ({$error['complement']})"), ' -'),
            $reply->errors(),
        );
        $reason = $errors === [] ? sprintf('HTTP %d: %s', $reply->status, json_encode($reply->body, JSON_UNESCAPED_UNICODE)) : implode('; ', $errors);

        return mb_substr($reason, 0, self::REASON_MAX_LENGTH);
    }

    private function classify(NfseReply $reply, bool $accepted): string
    {
        $codes = array_column($reply->errors(), 'code');

        return match (true) {
            $reply->isSuccess() && $accepted => self::ACCEPTED,
            in_array(self::DUPLICATE_DPS, $codes, true) => self::DUPLICATE,
            $codes !== [] => self::REJECTED,
            in_array($reply->status, self::CLIENT_ERRORS, true) => self::REJECTED,
            default => self::UNAVAILABLE,
        };
    }
}
