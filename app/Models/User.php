<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Professionnel de santé ou agent administratif.
 *
 * Correspondance FHIR visée : Practitioner (§44).
 *
 * Phase 2 (§60) : ce modèle est destiné à être remplacé par le modèle
 * utilisateur de Keneya Workflow. Le code applicatif n'accède donc jamais
 * à ses colonnes autrement que par les accesseurs et relations publiques
 * déclarés ici.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'matricule', 'name', 'first_name', 'last_name', 'title', 'speciality',
        'email', 'phone', 'password', 'service_id', 'is_active',
        'is_on_duty', 'on_duty_since',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_on_duty' => 'boolean',
            'on_duty_since' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function weeklySchedules(): HasMany
    {
        return $this->hasMany(UserWeeklySchedule::class);
    }

    public function dutyPeriods(): HasMany
    {
        return $this->hasMany(UserDutyPeriod::class);
    }

    /**
     * Le compte est-il, d'après son horaire hebdomadaire type, censé être
     * en poste à l'instant présent ? Purement indicatif : contrairement à
     * is_on_duty, cet horaire ne conditionne aucun accès ni aucune
     * visibilité — il aide seulement à repérer qui devrait être présent.
     */
    public function isScheduledNow(): bool
    {
        $today = $this->weeklySchedules->firstWhere('weekday', now()->dayOfWeekIso);

        if (! $today || $today->isRestDay()) {
            return false;
        }

        $now = now()->format('H:i:s');

        return $now >= $today->starts_at && $now < $today->ends_at;
    }

    /**
     * De garde : un compte désactivé ne l'est jamais, quel que soit le
     * drapeau — il n'a plus accès à l'application.
     */
    public function isOnDuty(): bool
    {
        return $this->is_active && $this->is_on_duty;
    }

    public function prescribedCareOrders(): HasMany
    {
        return $this->hasMany(CareOrder::class, 'prescriber_id');
    }

    public function assignedCareOrders(): HasMany
    {
        return $this->hasMany(CareOrder::class, 'assigned_nurse_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'doctor_id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'doctor_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'attending_doctor_id');
    }

    /**
     * Nom d'affichage complet, titre professionnel inclus.
     */
    public function displayName(): string
    {
        $parts = array_filter([
            $this->title,
            $this->first_name ?: null,
            $this->last_name ?: null,
        ]);

        return $parts === [] ? (string) $this->name : implode(' ', $parts);
    }

    public function initials(): string
    {
        $source = $this->first_name && $this->last_name
            ? $this->first_name.' '.$this->last_name
            : (string) $this->name;

        $initials = collect(preg_split('/\s+/', trim($source)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Un compte désactivé conserve son historique mais ne peut plus agir.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }
}
