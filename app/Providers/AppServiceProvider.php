<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Appointment;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\Hospitalization;
use App\Models\ImagingOrder;
use App\Models\ImagingReport;
use App\Models\LabOrder;
use App\Models\LabResult;
use App\Models\MedicalDocument;
use App\Models\NursingNote;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\VitalSign;
use App\Policies\AppointmentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\DiagnosisPolicy;
use App\Policies\HospitalizationPolicy;
use App\Policies\ImagingOrderPolicy;
use App\Policies\LabOrderPolicy;
use App\Policies\MedicalDocumentPolicy;
use App\Models\CareOrder;
use App\Policies\CareOrderPolicy;
use App\Policies\NursingNotePolicy;
use App\Policies\PatientPolicy;
use App\Policies\PrescriptionPolicy;
use App\Policies\SmsMessagePolicy;
use App\Policies\UserPolicy;
use App\Policies\VitalSignPolicy;
use App\Services\Sms\SmsGatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Correspondance explicite modèle → policy.
     *
     * La découverte automatique de Laravel suffirait pour la plupart des
     * modèles, mais certains partagent volontairement la policy de leur
     * agrégat (un résultat d'analyse suit sa demande, un compte rendu suit
     * son examen). L'énumération explicite évite qu'un modèle se retrouve
     * sans policy à la suite d'un renommage.
     *
     * @var array<class-string<Model>, class-string>
     */
    private const POLICIES = [
        Patient::class => PatientPolicy::class,
        Consultation::class => ConsultationPolicy::class,
        VitalSign::class => VitalSignPolicy::class,
        Diagnosis::class => DiagnosisPolicy::class,
        Prescription::class => PrescriptionPolicy::class,
        LabOrder::class => LabOrderPolicy::class,
        LabResult::class => LabOrderPolicy::class,
        ImagingOrder::class => ImagingOrderPolicy::class,
        ImagingReport::class => ImagingOrderPolicy::class,
        Hospitalization::class => HospitalizationPolicy::class,
        NursingNote::class => NursingNotePolicy::class,
        CareOrder::class => CareOrderPolicy::class,
        Appointment::class => AppointmentPolicy::class,
        MedicalDocument::class => MedicalDocumentPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        SmsMessage::class => SmsMessagePolicy::class,
        User::class => UserPolicy::class,
    ];

    public function register(): void
    {
        // Le gestionnaire de passerelles SMS est partagé : il met en cache
        // la passerelle résolue et permet aux tests d'en substituer une.
        $this->app->singleton(SmsGatewayManager::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->configureFacilitySettings();
    }

    /**
     * Coordonnées de l'établissement et préfixes des identifiants métier :
     * modifiables depuis l'écran Paramètres (App\Models\AppSetting), mais
     * lus partout ailleurs via config('keneya.*') comme avant. La table
     * peut ne pas exister encore (première installation, avant migrate) :
     * dans ce cas, on garde silencieusement les valeurs par défaut du
     * fichier de configuration.
     */
    private function configureFacilitySettings(): void
    {
        try {
            if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('app_settings')) {
                return;
            }

            $overrides = AppSetting::map();
        } catch (\Throwable) {
            return;
        }

        if ($overrides === []) {
            return;
        }

        foreach (['name', 'address', 'phone', 'email'] as $field) {
            if (! empty($overrides["facility.{$field}"])) {
                config(["keneya.facility.{$field}" => $overrides["facility.{$field}"]]);
            }
        }

        foreach (array_keys(config('keneya.identifiers.prefixes')) as $key) {
            if (! empty($overrides["identifiers.prefixes.{$key}"])) {
                config(["keneya.identifiers.prefixes.{$key}" => $overrides["identifiers.prefixes.{$key}"]]);
            }
        }
    }

    /**
     * Garde-fous de développement : les relations non chargées et les
     * attributions de masse silencieuses deviennent des erreurs, ce qui
     * fait remonter les requêtes N+1 pendant les tests plutôt qu'en
     * production (§58).
     */
    private function configureModels(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::unguard(false);
    }

    /**
     * Politique de mot de passe (§41) : longueur minimale, complexité et
     * refus des mots de passe présents dans les fuites connues.
     */
    private function configurePasswords(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            return $this->app->isProduction()
                ? $rule->symbols()->uncompromised()
                : $rule;
        });

        Validator::excludeUnvalidatedArrayKeys();
    }

    /**
     * Limitation de débit (§41) : protège la connexion contre le
     * bourrage d'identifiants et l'API contre l'usage abusif.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
