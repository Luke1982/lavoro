<?php

namespace Tests\Feature;

use App\Http\Requests\UserStoreRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Een attribuut dat wél gevalideerd wordt maar niet in $fillable staat, wordt
 * door create() stil weggelaten. Het formulier lijkt te werken, de melding is
 * groen, en de waarde is weg. Zo verdween seat_type: iedereen werd
 * binnendienst en de buitendienstplekken raakten nooit vol.
 */
class SilentlyDroppedAttributesTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_seat_type_survives_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Monteur',
            'email' => 'monteur-' . uniqid() . '@example.com',
            'password' => 'geheim',
            'seat_type' => 'field',
        ]);

        $this->assertSame('field', $user->fresh()->seat_type);
    }

    /**
     * Elk veld dat het aanmaakformulier valideert moet ook echt opgeslagen
     * kunnen worden. Zonder deze controle is het volgende vergeten veld weer
     * onzichtbaar.
     */
    public function test_every_validated_user_field_is_fillable_or_handled(): void
    {
        /** rules() vraagt de ingelogde gebruiker om rechten, dus die moet er zijn. */
        $admin = $this->admin();

        $request = new UserStoreRequest;
        $request->setUserResolver(fn () => $admin);

        $rules = array_keys($request->rules());

        /** Deze gaan bewust langs create() heen. */
        $handled_apart = ['avatar', 'role_ids', 'password_confirmation'];

        $fillable = (new User)->getFillable();

        foreach ($rules as $field) {
            $field = explode('.', $field)[0];

            if (in_array($field, $handled_apart, true) || !Schema::hasColumn('users', $field)) {
                continue;
            }

            $this->assertContains(
                $field,
                $fillable,
                "'{$field}' wordt gevalideerd maar staat niet in User::\$fillable, dus create() gooit het weg.",
            );
        }
    }
}
