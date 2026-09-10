<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Models\UserDutyPeriod;
use App\Models\UserWeeklySchedule;
use App\Support\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

        $schedule = $this->validateSchedule($request);

        DB::transaction(function () use ($data, $schedule, $request): void {
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

            $this->applySchedule($user, $schedule, $request);
        });

        return redirect()->route('users.index')->with('success', 'Compte créé.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $user->load(['roles', 'weeklySchedules']);

        return view('users.edit', $this->formData() + [
            'user' => $user,
            'upcomingDutyPeriods' => $user->dutyPeriods()->upcoming()->orderBy('starts_at')->get(),
            'activeDutyPeriods' => $user->dutyPeriods()->currentlyActive()->orderBy('starts_at')->get(),
        ]);
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

        $schedule = $this->validateSchedule($request);

        DB::transaction(function () use ($user, $data, $isActive, $schedule, $request): void {
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

            $this->applySchedule($user, $schedule, $request);
        });

        return redirect()->route('users.index')->with('success', 'Compte mis à jour.');
    }

    /**
     * Valide l'horaire hebdomadaire type et les gardes planifiées à
     * l'avance, communs aux formulaires de création et de modification.
     *
     * @return array{schedule: array<int, array{starts_at: ?string, ends_at: ?string}>, duty_periods: list<array{starts_at: string, ends_at: string, notes: ?string}>}
     */
    private function validateSchedule(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'schedule' => ['nullable', 'array'],
            'schedule.*.is_off' => ['nullable', 'boolean'],
            'schedule.*.starts_at' => ['nullable', 'date_format:H:i'],
            'schedule.*.ends_at' => ['nullable', 'date_format:H:i'],
            'duty_periods' => ['nullable', 'array'],
            'duty_periods.*.starts_at' => ['required', 'date'],
            'duty_periods.*.ends_at' => ['required', 'date'],
            'duty_periods.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            foreach (UserWeeklySchedule::WEEKDAYS as $weekday => $label) {
                $row = $request->input("schedule.$weekday", []);

                if (($row['is_off'] ?? false) || (empty($row['starts_at']) && empty($row['ends_at']))) {
                    continue;
                }

                if (empty($row['starts_at']) || empty($row['ends_at'])) {
                    $validator->errors()->add("schedule.$weekday.starts_at", "$label : indiquez un début et une fin, ou cochez repos.");
                } elseif ($row['starts_at'] >= $row['ends_at']) {
                    $validator->errors()->add("schedule.$weekday.ends_at", "$label : la fin doit suivre le début.");
                }
            }

            foreach ($request->input('duty_periods', []) as $index => $period) {
                if (empty($period['starts_at']) || empty($period['ends_at'])) {
                    continue;
                }

                if ($period['starts_at'] >= $period['ends_at']) {
                    $validator->errors()->add("duty_periods.$index.ends_at", 'La fin doit suivre le début.');
                } elseif (\Illuminate\Support\Carbon::parse($period['ends_at'])->isPast()) {
                    $validator->errors()->add("duty_periods.$index.ends_at", 'Une garde planifiée ne peut pas déjà être terminée.');
                }
            }
        });

        return $validator->validate();
    }

    /**
     * Enregistre l'horaire hebdomadaire type et les gardes planifiées à
     * venir. Les gardes déjà en cours ou passées ne sont jamais touchées :
     * seules les périodes pas encore débutées sont remplacées.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function applySchedule(User $user, array $schedule, Request $request): void
    {
        foreach (UserWeeklySchedule::WEEKDAYS as $weekday => $label) {
            $row = $schedule['schedule'][$weekday] ?? [];
            $isOff = ($row['is_off'] ?? false) || empty($row['starts_at']) || empty($row['ends_at']);

            $user->weeklySchedules()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'starts_at' => $isOff ? null : $row['starts_at'],
                    'ends_at' => $isOff ? null : $row['ends_at'],
                ],
            );
        }

        $user->dutyPeriods()->upcoming()->delete();

        foreach ($schedule['duty_periods'] ?? [] as $period) {
            $user->dutyPeriods()->create([
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'notes' => $period['notes'] ?? null,
                'created_by_id' => $request->user()?->id,
            ]);
        }
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
            'weekdays' => UserWeeklySchedule::WEEKDAYS,
        ];
    }
}
