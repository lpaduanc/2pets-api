<?php

namespace App\Providers;

use App\Contracts\AddressSuggestionProvider;
use App\Contracts\FiscalProviderGateway;
use App\Contracts\GeocodingProvider;
use App\Contracts\PaymentGatewayInterface;
use App\Events\MedicalRecordFinalized;
use App\Events\ReviewCreated;
use App\Listeners\Notifications\DispatchFallbackPushNotification;
use App\Listeners\SendAppointmentDepositNotification;
use App\Listeners\SendAppointmentNotification;
use App\Listeners\SendReviewInviteNotification;
use App\Listeners\SendReviewNotification;
use App\Models\Appointment;
use App\Models\Breed;
use App\Models\DietaryRestriction;
use App\Models\FoodAllergy;
use App\Models\FoodBrand;
use App\Models\ImmunizationProduct;
use App\Models\OrganizationMember;
use App\Models\Pathology;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use App\Observers\AppointmentSupplyConsumptionObserver;
use App\Observers\Organization\OrganizationMemberRoleReconciliationObserver;
use App\Observers\Organization\TeamSpecialtyMembershipObserver;
use App\Observers\Organization\TeamSpecialtyProfessionalObserver;
use App\Observers\ProfessionalSpecialtyObserver;
use App\Observers\ReferenceData\ReferenceDataCacheObserver;
use App\Observers\Search\ProfessionalSearchCacheObserver;
use App\Services\Crm\Automation\BirthdayTriggerResolver;
use App\Services\Crm\Automation\DewormingDueTriggerResolver;
use App\Services\Crm\Automation\InactiveClientTriggerResolver;
use App\Services\Crm\Automation\MessageAutomationTriggerResolverRegistry;
use App\Services\Crm\Automation\PostAppointmentFollowupTriggerResolver;
use App\Services\Crm\Automation\VaccineTriggerResolver;
use App\Services\Location\Providers\ChainAddressSuggestionProvider;
use App\Services\Location\Providers\ChainGeocodingProvider;
use App\Services\Location\Providers\GoogleGeocodingProvider;
use App\Services\Location\Providers\GooglePlacesSuggestionProvider;
use App\Services\Location\Providers\NominatimGeocodingProvider;
use App\Services\Location\Providers\PhotonSuggestionProvider;
use App\Services\Payment\MercadoPagoService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Nome do serviço de mapas em `services.geocoding.providers` → implementação. A ordem de
     * prioridade NÃO vem daqui: vem da config.
     *
     * @var array<string, class-string<GeocodingProvider>>
     */
    private const GEOCODING_PROVIDERS = [
        'google' => GoogleGeocodingProvider::class,
        'nominatim' => NominatimGeocodingProvider::class,
    ];

    /**
     * Mesmo papel de `GEOCODING_PROVIDERS`, para o autocomplete de endereço — e na mesma config:
     * `google` liga os dois. Nominatim fica de fora de propósito (política proíbe autocomplete).
     *
     * @var array<string, class-string<AddressSuggestionProvider>>
     */
    private const ADDRESS_SUGGESTION_PROVIDERS = [
        'google' => GooglePlacesSuggestionProvider::class,
        'photon' => PhotonSuggestionProvider::class,
    ];

    /**
     * Modelos de dado de referencia com cache de 24h (Fase 9 do plano de
     * otimizacao) — cada um ganha seu proprio contador de versao via
     * `ReferenceDataCacheObserver`, indexado por `getTable()`.
     *
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const REFERENCE_DATA_MODELS = [
        Pathology::class,
        // `ImmunizationProduct` substitui `VaccineCatalog` como fonte do endpoint público de
        // catálogo de vacinas (contrato docs/gap-simplesvet/contratos/13-contrato-api.md) —
        // `VaccineCatalog` é legado (@deprecated), não é mais lido por `MasterDataController`,
        // então não precisa mais de observer aqui. Não observamos `ImmunizationProductSpecies`
        // pelo mesmo motivo que `VaccineCatalog` nunca teve CRUD: o catálogo global
        // (`organization_id = null`) só muda por seeder/migration, nunca por API — o observer
        // genérico bumpa a versão pela TABELA do model salvo (`getTable()`), então observar o
        // pivô bumparia a chave de `immunization_product_species`, não a de `immunization_products`
        // que este cache realmente usa.
        ImmunizationProduct::class,
        FoodBrand::class,
        Specialty::class,
        FoodAllergy::class,
        DietaryRestriction::class,
        Breed::class,
    ];

    /**
     * Register any application services.
     *
     * `PaymentGatewayInterface` não tinha bind nenhum registrado (achado desta sessão,
     * confirmado por `app(PaymentService::class)` explodindo com `BindingResolutionException`)
     * — `PaymentController`/`WebhookController` já estavam inalcançáveis em qualquer ambiente
     * antes desta correção, e `InvoiceController` (contrato
     * docs/atendimento-veterinario/09-faturamento-do-atendimento.md) passou a depender do
     * mesmo `PaymentService`. Mercado Pago é a única implementação hoje (`StripeService`
     * existe, mas não implementa este contrato).
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, MercadoPagoService::class);

        // docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md: driver padrão do
        // ambiente é o fake/log — sem credencial configurada, a emissão "funciona" (grava com
        // status simulado, loga o payload). Trocar para um provedor real é só mudar este bind.
        $this->app->bind(FiscalProviderGateway::class, \App\Services\Fiscal\LogFiscalProviderGateway::class);

        // Geocodificação atrás de contrato: os provedores ATIVOS em `services.geocoding.providers`,
        // na ordem da config, viram uma cadeia com fallback. Provedor novo = classe implementando
        // `GeocodingProvider` + uma entrada em `GEOCODING_PROVIDERS` e na config. O autocomplete
        // de endereço segue o mesmo molde, com `ADDRESS_SUGGESTION_PROVIDERS`.
        $this->app->bind(GeocodingProvider::class, fn (): GeocodingProvider => new ChainGeocodingProvider(
            $this->enabledMapProviders(self::GEOCODING_PROVIDERS)
        ));
        $this->app->bind(AddressSuggestionProvider::class, fn (): AddressSuggestionProvider => new ChainAddressSuggestionProvider(
            $this->enabledMapProviders(self::ADDRESS_SUGGESTION_PROVIDERS)
        ));

        // docs/gap-simplesvet/specs/17-crm-mensageria-spec.md: um resolvedor por gatilho de
        // automação (OCP) — novo gatilho = nova classe implementando
        // `MessageAutomationTriggerResolver` + uma linha aqui, nunca um `match` crescendo em
        // `AutomationRunner`.
        $this->app->singleton(MessageAutomationTriggerResolverRegistry::class, fn ($app) => new MessageAutomationTriggerResolverRegistry([
            $app->make(VaccineTriggerResolver::class),
            $app->make(DewormingDueTriggerResolver::class),
            $app->make(BirthdayTriggerResolver::class),
            $app->make(PostAppointmentFollowupTriggerResolver::class),
            $app->make(InactiveClientTriggerResolver::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(SendAppointmentNotification::class);
        Event::subscribe(SendAppointmentDepositNotification::class);
        Event::listen(ReviewCreated::class, SendReviewNotification::class);
        Event::listen(MedicalRecordFinalized::class, SendReviewInviteNotification::class);

        // Fase 4 do fluxo de agendamento: push para TODA notificação já existente
        // (`App\Notifications\*`) sem editar nenhuma delas — ver o docblock da classe.
        Event::listen(NotificationSent::class, DispatchFallbackPushNotification::class);

        $this->registerSearchCacheObservers();
        $this->registerTeamSpecialtyObservers();
        $this->registerReferenceDataCacheObservers();
        $this->registerRateLimiters();

        // Item 22 — invariante do model: todo `OrganizationMember` criado/alterado
        // reconcilia o papel Spatie do usuário a partir do cargo, sem depender de cada
        // write path lembrar de chamar `UserRoleReconciler` manualmente (ver docblock do
        // observer).
        OrganizationMember::observe(OrganizationMemberRoleReconciliationObserver::class);

        // PLANO 1.3 — baixa dos insumos do serviço quando o agendamento é concluído.
        Appointment::observe(AppointmentSupplyConsumptionObserver::class);
    }

    /**
     * Limite da busca pública, por IP.
     *
     * 60/min, e não os 30/min que a rota usava. O motivo é o comportamento real da tela: a
     * busca do app é incremental (o usuário digita o termo e mexe em três ou quatro filtros
     * seguidos) e uma sessão legítima estourava 30 requisições em um minuto — nenhum debounce
     * razoável resolve isso sem matar a sensação de busca ao vivo.
     *
     * Por que não mais que 60: throttle por minuto NÃO é o que protege contra raspagem. São
     * ~2.250 páginas de resultado com `per_page=12`; a 30/min uma raspagem leva 75 minutos, a
     * 60/min leva 37 — nenhum dos dois é barreira para quem tem paciência, então subir o
     * número não "abre" nada que já não estivesse aberto. O que limita de verdade é custo de
     * servidor: uma busca com termo custa de 0,5 s a 4 s de Postgres, e 60 dessas por minuto
     * vindas de um único IP já é carga real. O cache de resultado (60 s, por célula
     * geográfica) absorve a repetição, mas não termos distintos.
     *
     * Fica registrado para o security-specialist: este endpoint não tem NENHUM controle
     * antirraspagem além deste throttle. Se proteger o catálogo virar requisito, a resposta é
     * outro mecanismo (exigir conta para paginação profunda, por exemplo), não um número
     * menor aqui.
     *
     * ── `reverse-geocode`: 30/min por IP ──────────────────────────────────────────────
     * Metade da busca, e por um motivo diferente: cada chamada pode virar uma requisição
     * PAGA ao Google Maps Platform (ou consumir a cota de 1 req/s do Nominatim público).
     * Aqui o throttle não protege servidor, protege fatura e a cota do provedor gratuito.
     *
     * Era 10/min quando o uso era UMA chamada por sessão (a modal de confirmação da
     * geolocalização). O seletor de local em mapa do app do tutor mudou isso: o pino fica
     * fixo no centro e o app pede o endereço a cada parada do arrasto. Mesmo com debounce de
     * ~700 ms e coordenada arredondada a 5 casas no front, ajustar o ponto com calma passa
     * de 10 paradas num minuto — e o tutor que está nesse fluxo muitas vezes está numa
     * emergência (viajando com o pet, IP/VPN posicionando errado), o pior lugar para um 429.
     *
     * 30/min ainda impede que a rota vire proxy gratuito do Geocoding API: varrer uma cidade
     * em grade de 100 m exige dezenas de milhares de pontos, o que leva dias por IP. O cache
     * de 30 dias por coordenada absorve a repetição legítima sem gastar cota nenhuma.
     *
     * ── `address-search`: 60/min por IP ───────────────────────────────────────────────
     * Autocomplete do seletor de mapa: uma chamada por pausa na digitação, então uma busca
     * legítima gasta várias em poucos segundos — o mesmo teto da busca pública. O custo por
     * chamada (Places Text Search pago, Photon público de boa-fé) é contido pelo mínimo de 3
     * caracteres, pelo cache de 1 dia por texto e pelo limite de 8 itens.
     *
     * ── `postal-code`: 20/min por IP ──────────────────────────────────────────────────
     * Mesma natureza do reverse geocoding (CEP novo = geocoding pago), com folga maior
     * porque é caminho de erro: quem cai no fallback de CEP pode errar a digitação algumas
     * vezes. CEP repetido não gasta cota — ViaCEP e geocoding cacheiam por 30 dias.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('public-search', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('reverse-geocode', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('address-search', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('postal-code', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
    }

    /**
     * Os serviços de mapas ATIVOS em `services.geocoding.providers`, na ordem da config, que
     * tenham implementação no mapa recebido — ligar/desligar é config, não código.
     *
     * @template TProvider of object
     *
     * @param  array<string, class-string<TProvider>>  $implementations
     * @return list<TProvider>
     */
    private function enabledMapProviders(array $implementations): array
    {
        return collect(config('services.geocoding.providers', []))
            ->filter(fn (array $settings, string $name): bool => ($settings['enabled'] ?? false) && isset($implementations[$name]))
            ->keys()
            ->map(fn (string $name): object => $this->app->make($implementations[$name]))
            ->values()
            ->all();
    }

    /**
     * Cache do resultado de busca de profissionais (Fase 9): invalidado por
     * contador de versao quando qualquer um destes tres models muda.
     */
    private function registerSearchCacheObservers(): void
    {
        User::observe(ProfessionalSearchCacheObserver::class);
        Professional::observe(ProfessionalSearchCacheObserver::class);
        Professional::observe(ProfessionalSpecialtyObserver::class);
        Service::observe(ProfessionalSearchCacheObserver::class);
    }

    /**
     * Fase 7 do fluxo de agendamento — mantém `professionals.team_specialties`
     * (agregado de busca da equipe) em sincronia com `organization_members`/
     * `professionals.specialties`. Ver `TeamSpecialtyAggregator`.
     */
    private function registerTeamSpecialtyObservers(): void
    {
        OrganizationMember::observe(TeamSpecialtyMembershipObserver::class);
        Professional::observe(TeamSpecialtyProfessionalObserver::class);
    }

    private function registerReferenceDataCacheObservers(): void
    {
        foreach (self::REFERENCE_DATA_MODELS as $referenceDataModel) {
            $referenceDataModel::observe(ReferenceDataCacheObserver::class);
        }
    }
}
