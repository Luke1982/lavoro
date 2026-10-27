<?php

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPlanGroup;
use App\Models\UserUnavailability;
use App\Support\Demo\Avatars;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The company and its people: one person per role, mechanics in plan groups,
 * a face on every account and a holiday in the planning.
 */
final class TeamSeeder
{
    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        $team = $this->context->data('team');

        Company::updateOrCreate(['is_main' => true], $team['company']);

        $groups = collect($team['plan_groups'])->map(fn (string $color, string $name) => UserPlanGroup::create([
            'name' => $name,
            'color' => $color,
            'sort_order' => array_search($name, array_keys($team['plan_groups']), true),
        ]));

        $password = Hash::make($team['password']);

        foreach ($team['people'] as $person) {
            $user = $this->person($person, $password);

            $user->roles()->sync(Role::whereIn('name', $person['roles'])->pluck('id'));
            $user->planGroups()->sync(collect($person['groups'] ?? [])->map(fn (string $name) => $groups[$name]->id));

            $this->avatar($user, $person);

            $this->context->users[$person['email']] = $user;

            if (!empty($person['mechanic'])) {
                $this->context->mechanics[] = $user;
            }
        }

        foreach ($team['absence'] as $absence) {
            $this->absence($absence);
        }
    }

    /**
     * The provisioner already made the first account -- the login the demo
     * lands on -- so that one is taken over rather than made twice.
     */
    private function person(array $person, string $password): User
    {
        $attributes = [
            'name' => $person['name'],
            'password' => $password,
            'seat_type' => $person['seat'],
            'plannable' => !empty($person['mechanic']),
        ];

        $existing = User::where('email', $person['email'])->first();

        if ($existing) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return User::forceCreate(['email' => $person['email'], ...$attributes]);
    }

    /**
     * A real photo when one was put in place, the drawing otherwise. The
     * directory holds exactly one file: the app serves whatever is in it.
     */
    private function avatar(User $user, array $person): void
    {
        $directory = "users/{$user->id}/avatar";
        $local = strtok($person['email'], '@');
        $photo = collect(['jpg', 'jpeg', 'png', 'webp'])
            ->map(fn (string $extension) => base_path("database/seeders/data/demo/photos/users/{$local}.{$extension}"))
            ->first(fn (string $path) => is_file($path));

        Storage::disk('public')->deleteDirectory($directory);

        $photo
            ? Storage::disk('public')->put("{$directory}/" . basename($photo), File::get($photo))
            : Storage::disk('public')->put("{$directory}/avatar.svg", Avatars::render($person['look']));
    }

    private function absence(array $absence): void
    {
        $user = $this->context->users[$absence['email']]
            ?? throw new RuntimeException("Absence for unknown demo user {$absence['email']}");

        if (isset($absence['weekly_on'])) {
            UserUnavailability::forceCreate([
                'user_id' => $user->id,
                'type' => 'recurring',
                'label' => $absence['label'],
                'day_of_week' => $absence['weekly_on'],
                'start_time' => $absence['from'],
                'end_time' => $absence['to'],
                'repeat' => 'weekly',
                'reference_date' => $this->context->day(0)->toDateString(),
            ]);

            return;
        }

        UserUnavailability::forceCreate([
            'user_id' => $user->id,
            'type' => 'holiday',
            'label' => $absence['label'],
            'date' => $this->context->day($absence['from_day'])->toDateString(),
            'end_date' => $this->context->day($absence['to_day'])->toDateString(),
        ]);
    }
}
