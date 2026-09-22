<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `App\Models\StaffTimeOff` esperava a tabela default do Eloquent (`staff_time_offs`,
 * plural), mas ela nasceu no singular (`staff_time_off`,
 * `2025_12_27_214000_create_staff_tables.php`) — não era tabela ausente, era colisão de
 * nome. Corrigido com `Schema::rename()` (não recriação), mesmo padrão já usado para
 * `staff` → `organization_members`. Este teste toca a tabela renomeada DE VERDADE, sem
 * mock: cria, lê e apaga um registro via o model.
 */
class StaffTimeOffTableRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_time_off_can_be_created_read_and_deleted_against_the_renamed_table(): void
    {
        $owner = User::factory()->professional()->create(['user_type' => 'clinic']);
        $organization = Organization::factory()->create();
        $member = OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        $timeOff = StaffTimeOff::create([
            'staff_id' => $member->id,
            'type' => 'vacation',
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeek()->addDays(5),
            'reason' => 'Férias de teste',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('staff_time_offs', [
            'id' => $timeOff->id,
            'staff_id' => $member->id,
            'status' => 'pending',
        ]);

        $fetched = StaffTimeOff::find($timeOff->id);
        $this->assertNotNull($fetched);
        $this->assertSame(6, $fetched->getTotalDays());

        $fetched->approve($owner);
        $this->assertSame('approved', $fetched->fresh()->status);
        $this->assertSame($owner->id, $fetched->fresh()->approved_by);

        $fetched->delete();
        $this->assertDatabaseMissing('staff_time_offs', ['id' => $timeOff->id]);
    }
}
