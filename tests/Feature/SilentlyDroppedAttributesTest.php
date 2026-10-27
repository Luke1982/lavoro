<?php

namespace Tests\Feature;

use App\Http\Requests\UserStoreRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * An attribute that is validated but is not in $fillable is silently dropped by
 * create(). The form looks like it works, the message is green, and the value is
 * gone. That is how seat_type disappeared: everyone became office staff and the
 * field seats never filled up.
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
     * Every field the create form validates has to be storable as well. Without
     * this check the next forgotten field is invisible again.
     */
    public function test_every_validated_user_field_is_fillable_or_handled(): void
    {
        /** rules() asks the logged in user for permissions, so there has to be one. */
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
