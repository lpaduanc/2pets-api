<?php

namespace App\Services\Insights\Widgets;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Quem pede, a organização principal dele, hoje e os parâmetros já validados do widget. */
final class DashboardWidgetContext
{
    /** @param  array<string, mixed>  $params */
    public function __construct(
        public readonly User $user,
        public readonly ?Organization $organization,
        public readonly CarbonImmutable $today,
        public readonly array $params,
    ) {}

    public function int(string $key): int
    {
        return (int) $this->params[$key];
    }
}
