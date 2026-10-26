<?php

namespace App\Enums;

/**
 * `catalog_products.image_status` — onde o item está na busca de foto por EAN
 * (`FetchCatalogProductImage`). `NOT_FOUND` não é erro: o EAN não tem foto na fonte e o admin
 * precisa subir a imagem; `FAILED` é falha técnica e volta a ser tentada.
 */
enum CatalogImageStatus: string
{
    case PENDING = 'pending';
    case FOUND = 'found';
    case NOT_FOUND = 'not_found';
    case FAILED = 'failed';
    case UPLOADED = 'uploaded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function retryable(): array
    {
        return [self::PENDING->value, self::FAILED->value];
    }
}
