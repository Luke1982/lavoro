<?php

namespace Tests\Feature;

use App\Models\Central\Package;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De plaatsen uit het abonnement. Elke weg waarlangs er eentje bezet raakt
 * moet dezelfde grens tegenkomen -- aanmaken, wijzigen én terugzetten. Die
 * laatste ging langs geen enkel formulier en sloeg de controle dus over:
 * weggooien en terughalen bracht je over je abonnement heen.
 */
class SeatLimitsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function fillOfficeSeats(): int
    {
        $package = Package::on('central')->where('key', tenancy()->tenant->package_key)->first();
        $limit = (int) $package->office_seats + (int) tenancy()->tenant->extra_office_seats;

        while (User::occupyingSeat('office')->count() < $limit) {
            User::factory()->create(['seat_type' => 'office']);
        }

        return $limit;
    }

    public function test_restoring_a_user_is_refused_when_the_seats_are_full(): void
    {
        $admin = $this->userWithPermissions('user.restore', 'user.delete');
        $gone = User::factory()->create(['seat_type' => 'office']);
        $gone->delete();

        $this->fillOfficeSeats();

        $this->actingAs($admin)
            ->post('/users/' . $gone->id . '/restore')
            ->assertSessionHasErrors('seat_type');

        $this->assertSoftDeleted('users', ['id' => $gone->id]);
    }

    public function test_restoring_is_allowed_while_there_is_room(): void
    {
        $admin = $this->userWithPermissions('user.restore', 'user.delete');
        $gone = User::factory()->create(['seat_type' => 'office']);
        $gone->delete();

        $this->actingAs($admin)
            ->post('/users/' . $gone->id . '/restore')
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted('users', ['id' => $gone->id]);
    }

    public function test_a_deleted_user_frees_the_seat_again(): void
    {
        $user = User::factory()->create(['seat_type' => 'office']);
        $before = User::occupyingSeat('office')->count();

        $user->delete();

        $this->assertSame($before - 1, User::occupyingSeat('office')->count());
    }
}
