<?php

namespace App\Services\Fiscal\Nfse;

use DOMDocument;
use DOMElement;

/**
 * Escrita de XML no namespace da NFS-e nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5. Sem prefixo de namespace (a Sefin recusa
 * prefixo: E1228) e com texto sempre escapado pelo DOM (nunca concatenação de string).
 */
final class NfseXmlWriter
{
    public const NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';

    private DOMDocument $document;

    public function __construct()
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->preserveWhiteSpace = false;
        $this->document->formatOutput = false;
    }

    /** @param  array<string, string>  $attributes */
    public function root(string $name, array $attributes): DOMElement
    {
        $root = $this->document->createElementNS(self::NAMESPACE, $name);
        $this->document->appendChild($root);
        $this->attributes($root, $attributes);

        return $root;
    }

    /**
     * Filho com texto; valor nulo ou vazio = elemento omitido (campo opcional do leiaute).
     *
     * @param  array<string, string>  $attributes
     */
    public function add(DOMElement $parent, string $name, ?string $value, array $attributes = []): ?DOMElement
    {
        if ($value === null || $value === '') {
            return null;
        }

        $element = $this->group($parent, $name, $attributes);
        $element->appendChild($this->document->createTextNode($value));

        return $element;
    }

    /**
     * Grupo (elemento sem texto próprio), sempre presente.
     *
     * @param  array<string, string>  $attributes
     */
    public function group(DOMElement $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $this->document->createElementNS(self::NAMESPACE, $name);
        $parent->appendChild($element);
        $this->attributes($element, $attributes);

        return $element;
    }

    public function xml(): string
    {
        return (string) $this->document->saveXML($this->document->documentElement);
    }

    /**
     * Texto livre para o XML: sem caractere de controle, espaços colapsados e cortado no limite do
     * leiaute (multibyte). Vazio vira nulo — o elemento opcional some.
     */
    public static function text(?string $value, int $maxLength): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value)));

        return $clean === '' ? null : mb_substr($clean, 0, $maxLength);
    }

    /** @param  array<string, string>  $attributes */
    private function attributes(DOMElement $element, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }
}
