<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Kullanıcı yönetimi. Route'larda `can:manage-users` (yalnızca Admin) ile korunur.
 * Kullanıcılar silinmez, pasife alınır.
 */
class UserController extends Controller
{
    private const SORTABLE = ['name', 'username', 'role', 'last_login_at', 'created_at'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', new Enum(Role::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, function ($query, $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(username) LIKE ?', [$like]));
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy($filters['sort'] ?? 'name', $filters['dir'] ?? 'asc')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', ['users' => $users, 'filters' => $filters, 'roles' => Role::options()]);
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['is_active' => true]), 'roles' => Role::options()]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', new Enum(Role::class)],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data, $audit) {
            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
            ]);
            $user->role = Role::from($data['role']);
            $user->is_active = true;
            $user->save();

            $audit->log(AuditService::CREATED, $user, new: $user->only(['name', 'username', 'email', 'role', 'is_active']), httpStatus: 302);

            return $user;
        });

        return redirect()->route('users.index')->with('status', "{$user->username} kullanıcısı oluşturuldu.");
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user, 'roles' => Role::options()]);
    }

    public function update(Request $request, User $user, AuditService $audit): RedirectResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', new Enum(Role::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $newRole = Role::from($data['role']);
        $newActive = (bool) $data['is_active'];

        $this->guardAgainstLockout($request->user(), $user, $newRole, $newActive);

        DB::transaction(function () use ($user, $data, $newRole, $newActive, $audit) {
            $oldRole = $user->role;
            $oldActive = $user->is_active;

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
            ]);
            $original = $user->getOriginal();
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = $data['password'];
            }
            $user->role = $newRole;
            $user->is_active = $newActive;
            $user->save();

            [$old, $new] = AuditService::diff($original, $user);
            unset($old['role'], $new['role'], $old['is_active'], $new['is_active']);

            if ($old !== []) {
                $audit->log(AuditService::UPDATED, $user, $old, $new, httpStatus: 302);
            }
            if ($oldRole !== $newRole) {
                $audit->log(AuditService::ROLE_CHANGED, $user, ['role' => $oldRole], ['role' => $newRole], httpStatus: 302);
            }
            if ($oldActive !== $newActive) {
                $audit->log($newActive ? AuditService::ACTIVATED : AuditService::DEACTIVATED, $user, ['is_active' => $oldActive], ['is_active' => $newActive], httpStatus: 302);
            }
            if ($passwordChanged) {
                $audit->log(AuditService::PASSWORD_CHANGED, $user, httpStatus: 302);
            }
        });

        return redirect()->route('users.index')->with('status', "{$user->username} kullanıcısı güncellendi.");
    }

    /**
     * Admin kendi yetkisini düşüremez/kendini pasife alamaz ve sistemde en az bir aktif Admin kalmalıdır.
     */
    private function guardAgainstLockout(User $actor, User $target, Role $newRole, bool $newActive): void
    {
        $losesAdmin = $target->isAdmin() && $target->is_active && ($newRole !== Role::Admin || ! $newActive);

        if (! $losesAdmin) {
            return;
        }

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'role' => 'Kendi Admin yetkinizi kaldıramaz veya kendinizi pasife alamazsınız.',
            ]);
        }

        $otherActiveAdmins = User::where('role', Role::Admin)->where('is_active', true)->whereKeyNot($target->id)->count();
        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'role' => 'Sistemde en az bir aktif Admin kalmalıdır.',
            ]);
        }
    }
}
