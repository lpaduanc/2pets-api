<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `subscription_usage` nasceu no singular (`2025_12_27_206000_create_subscription_tables`),
 * quebrando a convenção do projeto (`CLAUDE.md`: tabela sempre snake_case PLURAL) e, mais
 * grave, o próprio Eloquent: `SubscriptionUsage` (sem `$table` override) infere
 * `subscription_usages` pela pluralização padrão. `GET /subscriptions/current` (que carrega
 * `$subscription->usage()->get()`) sempre estourava `relation "subscription_usages" does not
 * exist` — uma das 6 falhas do baseline de testes (`SubscriptionTest`), e um 500 real em
 * produção para qualquer usuário com assinatura ativa.
 *
 * `Schema::rename` preserva dado, colunas, índice e FK — só o nome da tabela muda. Os nomes de
 * constraint continuam com o prefixo antigo (`subscription_usage_subscription_id_foreign`),
 * cosmético, sem efeito funcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_usage') && ! Schema::hasTable('subscription_usages')) {
            Schema::rename('subscription_usage', 'subscription_usages');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_usages') && ! Schema::hasTable('subscription_usage')) {
            Schema::rename('subscription_usages', 'subscription_usage');
        }
    }
};
