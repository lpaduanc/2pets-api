<?php

namespace App\DataTransferObjects;

use Stringable;

/**
 * Base dos documentos brasileiros gravados no 2pets (CPF, CNPJ).
 *
 * Regra de projeto: documento é SEMPRE persistido como string limpa, só dígitos, sem ponto,
 * traço ou barra — e toda busca normaliza a entrada antes de consultar. O motivo é
 * indexabilidade: guardar máscara obrigaria a query a aplicar `regexp_replace()` sobre a
 * coluna (ou `LIKE`), o que descarta o índice e vira varredura sequencial na tabela inteira
 * de usuários. Guardar limpo mantém o lookup em igualdade pura sobre índice B-tree.
 *
 * A normalização é aplicada em duas camadas independentes, de propósito:
 *   1. Mutator do model (`User`, `Professional`, `Company`) — garante o INVARIANTE de escrita,
 *      mesmo que algum caminho esqueça de tratar a entrada.
 *   2. `prepareForValidation()` dos Form Requests — garante que `digits:N` e as checagens de
 *      unicidade comparem o mesmo formato que está no banco. Sem isso, um CPF mascarado passa
 *      pelo `unique` (não acha o valor formatado) e só estoura no índice, virando 500.
 *
 * Não valida dígito verificador: isso é responsabilidade de `CpfValidationService`, que já
 * existe e é usado nos fluxos de cadastro. Aqui a responsabilidade é só formato.
 */
abstract readonly class DocumentNumber implements Stringable
{
    /** Quantidade de dígitos do documento já normalizado. */
    public const DIGIT_COUNT = 0;

    final protected function __construct(public string $digits) {}

    /**
     * Remove a máscara sem validar o comprimento — a validação de tamanho fica no Form
     * Request, para que "CPF com 10 dígitos" produza erro de tamanho e não de campo ausente.
     */
    final public static function stripMask(?string $raw): string
    {
        return (string) preg_replace('/\D/', '', (string) $raw);
    }

    /** Devolve null quando a entrada não tem exatamente `DIGIT_COUNT` dígitos. */
    final public static function tryParse(?string $raw): ?static
    {
        $digits = static::stripMask($raw);

        return strlen($digits) === static::DIGIT_COUNT ? new static($digits) : null;
    }

    /**
     * Normaliza para gravação: string limpa, ou null quando a entrada é vazia/nula.
     * Entrada com quantidade errada de dígitos é devolvida limpa mesmo assim — descartar
     * silenciosamente perderia dado; quem valida tamanho é o Form Request.
     */
    final public static function normalizeForStorage(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = static::stripMask($raw);

        return $digits === '' ? null : $digits;
    }

    final public function __toString(): string
    {
        return $this->digits;
    }
}
