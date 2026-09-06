<?php

namespace Database\Seeders\Benchmark;

/**
 * Listas de nomes e sobrenomes PT-BR (com acentuacao) usadas pelo BenchmarkSeeder.
 *
 * A cardinalidade de trigramas importa para a Fase 5 do plano de otimizacao
 * (busca fuzzy): `user_00001` faz o trigrama `use` repetir em 200 mil linhas
 * e mente sobre a seletividade real do indice GIN. 40 nomes x 50 sobrenomes
 * = 2000 combinacoes ja bastam para uma distribuicao realista.
 */
final class NamePools
{
    /** @var list<string> */
    public const FIRST_NAMES = [
        'José', 'João', 'Antônio', 'Francisco', 'Carlos', 'Paulo', 'Pedro', 'Lucas',
        'Marcos', 'Luiz', 'Gabriel', 'Rafael', 'Daniel', 'Marcelo', 'Bruno', 'Eduardo',
        'Felipe', 'Rodrigo', 'Fernando', 'Gustavo', 'Maria', 'Ana', 'Francisca', 'Antônia',
        'Adriana', 'Juliana', 'Márcia', 'Fernanda', 'Patrícia', 'Aline', 'Sandra', 'Camila',
        'Amanda', 'Bruna', 'Jéssica', 'Letícia', 'Larissa', 'Vanessa', 'Cristina', 'Beatriz',
    ];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira',
        'Lima', 'Gomes', 'Ribeiro', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes',
        'Vieira', 'Barbosa', 'Rocha', 'Dias', 'Nascimento', 'Andrade', 'Moreira', 'Nunes',
        'Marques', 'Machado', 'Mendes', 'Freitas', 'Cardoso', 'Ramos', 'Gonçalves', 'Santana',
        'Teixeira', 'Araújo', 'Melo', 'Castro', 'Correia', 'Cavalcanti', 'Monteiro', 'Moura',
        'Cunha', 'Pinto', 'Reis', 'Batista', 'Farias', 'Azevedo', 'Campos', 'Duarte',
        'Sales', 'Brito',
    ];

    /** @var list<string> */
    public const BUSINESS_PREFIXES = [
        'Clínica Veterinária', 'Hospital Veterinário', 'Pet Center', 'Vet & Cia',
        'Instituto Veterinário', 'Petshop',
    ];

    /** Centroides de Grande SP (8) + Campinas como controle negativo (1). */
    public const CENTROID_LATITUDES = [
        -23.5505, -23.5613, -23.5629, -23.6560, -23.5400, -23.4538, -23.5329, -23.6639, -22.9099,
    ];

    public const CENTROID_LONGITUDES = [
        -46.6333, -46.6565, -46.6822, -46.7100, -46.5769, -46.5333, -46.7916, -46.5383, -47.0626,
    ];

    /**
     * Converte um array de strings PHP num literal `ARRAY[...]::text[]` do Postgres.
     * Uso interno de seeder com dados estaticos controlados pelo proprio codigo —
     * nao ha entrada de usuario aqui, mas o escape de aspas simples e mantido por
     * disciplina (nunca montar SQL a partir de string sem pensar em escape).
     *
     * @param  list<string>  $values
     */
    public static function toTextArrayLiteral(array $values): string
    {
        $escaped = array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values
        );

        return 'ARRAY['.implode(',', $escaped).']::text[]';
    }

    /**
     * @param  list<float>  $values
     */
    public static function toFloatArrayLiteral(array $values): string
    {
        $formatted = array_map(static fn (float $value): string => (string) $value, $values);

        return 'ARRAY['.implode(',', $formatted).']::double precision[]';
    }
}
