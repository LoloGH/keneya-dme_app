<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Administration des comptes et des rôles (§31).
 *
 * Un compte n'est jamais supprimé : il porte l'historique médical et les
 * signatures d'actes. La désactivation coupe l'accès tout en préservant
 * la traçabilité (§40).
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['roles:id,name', 'service:id,name'])
            ->when($request->string('role')->toString(), fn ($q, $role) => $q->role($role))
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('last_name', 'like', '%'.$term.'%')
                ->orWhere('first_name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('matricule', 'like', '%'.$term.'%')))
            ->orderBy('last_name')
            ->paginate(config('keneya.pagination.default'))
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filters' => $request->only(['q', 'role']),
            'roleLabels' => Rbac::allRoleLabels(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('users.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:20'],
            'speciality' => ['nullable', 'string', 'max:100'],
            'matricule' => ['nullable', 'string', 'max:50', 'unique:users,matricule'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'service_id' => ['nullable', 'exists:services,id'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(array_keys(Rbac::allRoleLabels()))],
        ], [], [
            'first_name' => 'prénom',
            'last_name' => 'nom',
            'email' => 'adresse e-mail',
            'password' => 'mot de passe',
            'role' => 'rôle',
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'title' => $data['title'] ?? null,
                'speciality' => $data['speciality'] ?? null,
                'matricule' => $data['matricule'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->syncRoles([$data['role']]);
        });

        return redirect()->route('users.index')->with('success', 'Compte créé.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('users.edit', $this->formData() + ['user' => $user->load('roles')]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:20'],
            'speciality' => ['nullable', 'string', 'max:100'],
            'matricule' => ['nullable', 'string', 'max:50', Rule::unique('users', 'matricule')->ignore($user->id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'service_id' => ['nullable', 'exists:services,id'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(array_keys(Rbac::allRoleLabels()))],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'first_name' => 'prénom',
            'last_name' => 'nom',
            'email' => 'adresse e-mail',
            'role' => 'rôle',
        ]);

        // Un administrateur ne peut pas se désactiver lui-même.
        $isActive = $request->boolean('is_active');

        if (! $isActive && ! $request->user()->can('deactivate', $user)) {
            return back()->withErrors([
                'is_active' => 'Vous ne pouvez pas désactiver votre propre compte.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $isActive): void {
            $user->update(array_filter([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'title' => $data['title'] ?? null,
                'speciality' => $data['speciality'] ?? null,
                'matricule' => $data['matricule'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'password' => $data['password'] ?? null,
            ], fn ($value) => $value !== null) + ['is_active' => $isActive]);

            $user->syncRoles([$data['role']]);
        });

        return redirect()->route('users.index')->with('success', 'Compte mis à jour.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'roles' => Role::orderBy('name')->get(),
            'roleLabels' => Rbac::allRoleLabels(),
            'services' => Service::where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
