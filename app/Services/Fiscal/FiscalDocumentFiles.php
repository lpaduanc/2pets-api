<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * XMLs e DANFEs no disco PRIVADO — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §8. Guarda legal de 5 anos (CTN art. 173): nada aqui apaga arquivo. O caminho é montado por nós
 * (dono/ambiente/ano/mês/chave) — nunca vem do cliente — e nunca sai em resposta; o download passa
 * por `FiscalDocumentFileController` com escopo e policy.
 */
final class FiscalDocumentFiles
{
    public const SIGNED_XML = 'nfe-assinada.xml';

    public const AUTHORIZED_XML = 'procNFe.xml';

    public const CANCELLATION_XML = 'procEventoCancNFe.xml';

    public const PDF = 'danfe.pdf';

    public function put(FiscalDocument $document, string $accessKey, string $suffix, string $contents): string
    {
        $path = sprintf('%s/%s-%s', $this->folder($document), $accessKey, $suffix);
        $this->disk()->put($path, $contents);

        return $path;
    }

    public function putInvalidation(FiscalNumberInvalidation $invalidation, string $contents): string
    {
        $path = sprintf('fiscal/%s/%s/inutilizacao/%d-procInutNFe.xml', self::owner($invalidation->organization_id, $invalidation->professional_id), $invalidation->environment?->value ?? 'homologation', $invalidation->id);
        $this->disk()->put($path, $contents);

        return $path;
    }

    public function read(?string $path): ?string
    {
        if ($path === null || ! $this->disk()->exists($path)) {
            return null;
        }

        return $this->disk()->get($path);
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $this->disk()->exists($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('fiscal.sefaz.disk'));
    }

    private function folder(FiscalDocument $document): string
    {
        $issuedAt = $document->created_at ?? now();

        return sprintf('fiscal/%s/%s/%s', self::owner($document->organization_id, $document->professional_id), $document->environment?->value ?? 'homologation', $issuedAt->format('Y/m'));
    }

    private static function owner(?int $organizationId, ?int $professionalId): string
    {
        return $organizationId !== null ? "org-{$organizationId}" : "prof-{$professionalId}";
    }
}
