<?php

namespace App\DataTransferObjects;

use Illuminate\Support\Collection;

/**
 * First class collection of pet health summaries: owns the cross-pet totals the
 * dashboard header shows, so no caller has to re-derive them.
 *
 * @extends Collection<int, PetHealthSummary>
 */
class PetHealthSummaries extends Collection
{
    public function overdueCount(): int
    {
        return (int) $this->sum(fn (PetHealthSummary $summary) => $summary->overdueCount());
    }

    public function dueSoonCount(): int
    {
        return (int) $this->sum(fn (PetHealthSummary $summary) => $summary->dueSoonCount());
    }
}
