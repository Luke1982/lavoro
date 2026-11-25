<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\UserPasswordChanged;
use App\Http\Requests\UserDeleteRequest;
use App\Http\Requests\UserRestoreRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\AssistantFilter;
use App\Models\Central\Package;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAvatarService;
use Illuminate\Database\Eloquent\Collection;

class UserController extends Controller
{
    /** The newest entries the profile shows; the assistant still searches all of them. */
    private const HISTORY_SHOWN = 100;

    public function index()
    {
        abort_unless(auth()->user()->can('viewAny', User::class), 403);
        $users = User::query()->tap(AssistantFilter::from(request()))->get();

        /** The assistant counts the active users only, so its filter shows those and no deleted ones. */
        $deleted_users = auth()->user()->can('viewTrashed', User::class) && !AssistantFilter::isAsked(request())
            ? User::onlyTrashed()->get()
            : collect();

        return inertia('Users/IndexPage', [
            'users' => $users,
            'deletedUsers' => $deleted_users,
        ]);
    }

    public function create()
    {
        abort_unless(auth()->user()->can('create', User::class), 403);

        return inertia('Users/EditPage', [
            'user' => null,
            'seesHomeAddress' => true,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
            'occupiesSeat' => true,
        ]);
    }

    public function edit(User $user)
    {
        abort_unless(auth()->user()->can('view', $user), 403);
        $user->load('roles:id,name');
        $sees_home_address = auth()->user()->can('seeHomeAddress', $user);
        if ($sees_home_address) {
            $user->makeVisible(User::HOME_ADDRESS);
        }

        return inertia('Users/EditPage', [
            'user' => $user,
            'seesHomeAddress' => $sees_home_address,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
            /** A super admin occupies no seat; they should not see that choice. */
            'occupiesSeat' => !$user->isSuperAdmin(),
            'unavailabilities' => $user->unavailabilities()
                ->orderBy('type')
                ->orderBy('day_of_week')
                ->orderBy('date')
                ->get(),
            'activities' => $this->timeline($user),
            'historyShown' => self::HISTORY_SHOWN,
        ]);
    }

    /**
     * How many seats there are per kind and how many are still free. The form
     * shows it; the validation guards it.
     */
    private function seats(): array
    {
        if (!tenancy()->initialized) {
            return [];
        }

        $tenant = tenancy()->tenant;
        $package = Package::on('central')->where('key', $tenant->package_key)->first();

        return collect([
            'field' => ['label' => User::seatTypeLabel('field'), 'limit' => (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats],
            'office' => ['label' => User::seatTypeLabel('office'), 'limit' => (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats],
        ])->map(fn ($seat, $type) => $seat + ['used' => User::occupyingSeat($type)->count()])->all();
    }

    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        $role_ids = $data['role_ids'] ?? null;
        unset($data['avatar'], $data['role_ids']);
        $user = User::create($data);
        if ($role_ids !== null) {
            $user->syncRoles($role_ids);
        }

        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('users.index')->with('success', 'Gebruiker aangemaakt');
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $this->announcePasswordChange($user);
        if (array_key_exists('role_ids', $data)) {
            $user->syncRoles($data['role_ids']);
        }
        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('users.index')->with('success', 'Gebruiker bijgewerkt');
    }

    public function destroy(UserDeleteRequest $request, User $user)
    {
        $user->delete();

        return redirect()->route('users.index')->with('success', 'Gebruiker verwijderd');
    }

    public function restore(UserRestoreRequest $request, User $user)
    {
        $user->restore();

        return redirect()->route('users.index')->with('success', 'Gebruiker hersteld');
    }

    /**
     * Roles the current user may hand out; empty when they may not.
     */
    private function assignableRoles()
    {
        return auth()->user()?->can('assignRoles', User::class)
            ? Role::assignable()->orderBy('name')->get(['id', 'name'])
            : [];
    }

    /**
     * Edit the currently authenticated user's profile (non-admins allowed).
     */
    public function editSelf()
    {
        $user = request()->user();
        abort_unless($user, 403);
        $user->load('roles:id,name');
        $user->makeVisible(User::HOME_ADDRESS);

        return inertia('Users/EditPage', [
            'user' => $user,
            'seesHomeAddress' => true,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
            /** A super admin occupies no seat; they should not see that choice. */
            'occupiesSeat' => !$user->isSuperAdmin(),
            'unavailabilities' => $user->unavailabilities()
                ->orderBy('type')
                ->orderBy('day_of_week')
                ->orderBy('date')
                ->get(),
            'activities' => $this->timeline($user),
            'historyShown' => self::HISTORY_SHOWN,
        ]);
    }

    /**
     * Update the currently authenticated user's profile (non-admins allowed).
     */
    public function updateSelf(UserUpdateRequest $request)
    {
        $user = request()->user();
        abort_unless($user, 403);

        $data = $request->validated();
        unset($data['role_ids']);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        $this->announcePasswordChange($user);
        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('me.edit')->with('success', 'Profiel bijgewerkt');
    }

    /** What happened to this person's account, as far as whoever looks may read it. */
    private function timeline(User $user): Collection
    {
        return $user->activities()
            ->visibleTo(auth()->user())
            ->with(['user:id,name', 'fieldChanges'])
            ->orderByDesc('activityables.created_at')
            ->limit(self::HISTORY_SHOWN)
            ->get();
    }

    private function announcePasswordChange(User $user): void
    {
        if (!$user->wasChanged('password')) {
            return;
        }

        Signals::dispatch(new UserPasswordChanged(
            $user,
            $user->is(auth()->user()) ? UserPasswordChanged::BY_THEMSELVES : UserPasswordChanged::BY_SOMEONE_ELSE,
        ));
    }
}
