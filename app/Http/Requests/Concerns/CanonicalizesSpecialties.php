<?php

namespace App\Http\Requests\Concerns;

use App\Services\Professional\SpecialtyCatalogIndex;
use Illuminate\Support\Facades\Log;

/**
 * Põe a lista de especialidades do payload na grafia canônica do catálogo ANTES da validação.
 *
 * Sem isto, `professionals.specialties` continuaria acumulando uma grafia por cliente —
 * medido num `PUT /api/profile` real: `["Cardiologia","clinica_geral","Diagnóstico por
 * Imagem","nefrologia urologia"]`, quatro conceitos do catálogo em quatro formatos, nenhum
 * deles igual a `specialties.name`. A pivô já nasce certa (é FK), mas a coluna é a que a
 * busca e três API Resources ainda leem.
 *
 * Mora no Form Request, e não num service, por dois motivos: é normalização de ENTRADA (o
 * mesmo lugar onde este projeto já tira a máscara do CPF e formata o CRMV), e é o único
 * ponto pelo qual os três caminhos de escrita passam antes de qualquer regra.
 *
 * ⚠️ Roda ANTES da validação, então recebe valor não confiável: item desconhecido ou não
 * textual passa intacto para `App\Rules\ValidSpecialty` rejeitar. Filtrar aqui seria
 * transformar payload inválido em payload válido.
 *
 * A ÚNICA exceção é a lista enumerada de `SpecialtyAliasCatalog::misfiledAliases()` — hoje
 * só `emergency`, que o formulário manda como especialidade e o backend representa como
 * serviço/diferencial. Esse valor é descartado com log em vez de rejeitado, porque recusar
 * o cadastro por uma opção que o próprio formulário oferece pune o usuário por um erro
 * nosso. Enumerado, e não genérico: qualquer outro desconhecido continua indo para a regra.
 */
trait CanonicalizesSpecialties
{
    protected function canonicalizeSpecialties(string $key = 'specialties'): void
    {
        $values = $this->input($key);

        if (! is_array($values)) {
            return;
        }

        $catalog = app(SpecialtyCatalogIndex::class);

        [$misfiled, $declared] = collect($values)->partition(
            static fn (mixed $value): bool => is_string($value) && $catalog->isMisfiled($value),
        );

        $this->logDiscarded($misfiled->values()->all(), $key);
        $this->merge($this->replacedAt($key, $catalog->canonicalize($declared->values()->all())));
    }

    /**
     * O descarte é silencioso para o cliente (200, sem o valor) — então precisa ser
     * ruidoso para nós. Sem este log não há como medir quantos profissionais marcaram
     * "Emergência 24h" no campo errado enquanto o formulário não é corrigido.
     *
     * @param  list<mixed>  $discarded
     */
    private function logDiscarded(array $discarded, string $key): void
    {
        if ($discarded === []) {
            return;
        }

        Log::info('Valor descartado da lista de especialidades: não é especialidade.', [
            'user_id' => $this->user()?->id,
            'field' => $key,
            'discarded' => $discarded,
        ]);
    }

    /**
     * `merge()` do Laravel é RASO: `merge(['professional.specialties' => ...])` cria uma
     * chave de topo com um ponto no nome em vez de mexer no bloco aninhado. Reconstruir o
     * ramo inteiro a partir da raiz é o que faz a notação de ponto funcionar de verdade —
     * e `professional.specialties` é exatamente o formato que `PUT /api/profile` usa.
     *
     * @param  list<mixed>  $value
     * @return array<string, mixed>
     */
    private function replacedAt(string $key, array $value): array
    {
        $root = strtok($key, '.');
        $branch = [$root => $this->input($root)];

        data_set($branch, $key, $value);

        return $branch;
    }
}
