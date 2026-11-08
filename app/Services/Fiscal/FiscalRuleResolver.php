<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalRuleContext;
use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\DataTransferObjects\Fiscal\ResolvedTaxProfile;
use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\TaxRegime;
use App\Models\TaxProfile;

/**
 * Perfil efetivo + regime do emitente + CFOP da faixa → `FiscalTaxRule` de cada produto — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.2–§3.4. Cada falta vira uma linha em
 * `FiscalIssueProblems` (o chamador recusa tudo de uma vez).
 */
final class FiscalRuleResolver
{
    public function __construct(private readonly TaxProfileResolver $profiles) {}

    /**
     * @param  array<array-key, array{product_id: int, description: string}>  $products
     * @return array<array-key, FiscalTaxRule|null>
     */
    public function resolve(FiscalRuleContext $context, array $products, FiscalIssueProblems $problems): array
    {
        self::assertRegime($context->regime, $problems);
        $resolved = $this->profiles->forProducts($context->owner, array_column($products, 'product_id'));

        return array_map(
            fn (array $product): ?FiscalTaxRule => $this->rule($resolved[$product['product_id']] ?? null, $product['description'], $context, $problems),
            $products,
        );
    }

    public static function assertRegime(?TaxRegime $regime, FiscalIssueProblems $problems): void
    {
        if ($regime === null) {
            $problems->add('Informe o regime tributário da empresa em Configurações fiscais.');
        }
    }

    /**
     * CFOP da faixa num perfil já escolhido (devolução: o perfil congelado no item original).
     *
     * @param  array{0: FiscalCfopOperation, 1: FiscalCfopScope}  $slot
     */
    public function cfopFor(?TaxProfile $profile, string $description, array $slot, FiscalIssueProblems $problems): ?string
    {
        if ($profile === null) {
            $problems->add(sprintf('"%s" sem perfil tributário no documento original.', $description));

            return null;
        }

        $cfop = $profile->cfopFor($slot[0], $slot[1]);

        if ($cfop === null) {
            $problems->add(self::missingCfop($profile, $slot));
        }

        return $cfop;
    }

    private function rule(?ResolvedTaxProfile $resolved, string $description, FiscalRuleContext $context, FiscalIssueProblems $problems): ?FiscalTaxRule
    {
        $regime = $context->regime;

        if ($resolved === null) {
            $problems->add(sprintf('"%s" sem perfil tributário: defina no produto, no grupo ou marque um perfil padrão.', $description));

            return null;
        }

        $profile = $resolved->profile;
        $this->assertCodeForRegime($profile, $regime, $problems);
        $cfop = $this->cfopFor($profile, $description, $context->slot(), $problems);

        return FiscalTaxRule::fromProfile($profile, $regime, $cfop, $resolved->source);
    }

    private function assertCodeForRegime(TaxProfile $profile, ?TaxRegime $regime, FiscalIssueProblems $problems): void
    {
        if ($regime === null) {
            return;
        }

        $code = $regime->usesCsosn() ? $profile->icms_csosn : $profile->icms_cst;

        if ($code === null) {
            $problems->add(sprintf('Perfil "%s" sem %s, exigido no regime %s.', $profile->name, $regime->usesCsosn() ? 'CSOSN' : 'CST do ICMS', $regime->label()));
        }
    }

    /** @param  array{0: FiscalCfopOperation, 1: FiscalCfopScope}  $slot */
    private static function missingCfop(TaxProfile $profile, array $slot): string
    {
        $where = $slot[1] === FiscalCfopScope::INTRASTATE ? 'dentro do estado' : 'fora do estado';

        return sprintf('Perfil "%s" sem CFOP para %s (%s).', $profile->name, mb_strtolower($slot[0]->label()), $where);
    }
}
