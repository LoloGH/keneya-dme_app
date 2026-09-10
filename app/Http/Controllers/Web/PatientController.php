<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\CareOrderController;
use App\Http\Requests\StorePatientRequest;
use App\Models\Allergy;
use App\Models\ChronicCondition;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Services\Documents\PdfGenerator;
use App\Services\Patients\MedicalTimeline;
use App\Support\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liste des patients (§11), création (§12) et dossier médical (§13-14).
 */
class PatientController extends Controller
{
    /**
     * Liste filtrable et paginée (§11).
     *
     * Les filtres sont appliqués en base sur colonnes indexées et la
     * pagination conserve la requête, afin que le tri d'une liste de
     * plusieurs milliers de dossiers reste instantané (§58).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Patient::class);

        $filters = $request->only(['q', 'sex', 'status', 'doctor', 'service', 'age_min', 'age_max', 'period']);

        $patients = Patient::query()
            ->with(['attendingDoctor:id,name,first_name,last_name,title'])
            ->withCount('consultations')
            ->addSelect([
                // Dernière consultation en sous-requête : évite de charger
                // toutes les consultations pour afficher une seule date.
                'last_consultation_at' => DB::table('consultations')
                    ->selectRaw('MAX(started_at)')
                    ->whereColumn('consultations.patient_id', 'patients.id'),
            ])
            ->search($filters['q'] ?? null)
            ->when($filters['sex'] ?? null, fn ($query, $sex) => $query->where('sex', $sex))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['doctor'] ?? null, fn ($query, $doctor) => $query->where('attending_doctor_id', $doctor))
            ->when($filters['age_min'] ?? null, fn ($query, $min) => $query
                ->whereDate('birth_date', '<=', now()->subYears((int) $min)))
            ->when($filters['age_max'] ?? null, fn ($query, $max) => $query
                ->whereDate('birth_date', '>=', now()->subYears((int) $max + 1)))
            ->when($filters['period'] ?? null, fn ($query, $period) => match ($period) {
                'today' => $query->whereDate('created_at', today()),
                'week' => $query->where('created_at', '>=', now()->subWeek()),
                'month' => $query->where('created_at', '>=', now()->subMonth()),
                default => $query,
            })
            ->orderByDesc('created_at')
            ->paginate(config('keneya.pagination.default'))
            ->withQueryString();

        return view('patients.index', [
            'patients' => $patients,
            'filters' => $filters,
            'doctors' => $this->doctors(),
            'services' => Service::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Patient::class);

        return view('patients.create', [
            'doctors' => $this->doctors(),
        ]);
    }

    public function store(StorePatientRequest $request): RedirectResponse
    {
        $patient = DB::transaction(function () use ($request): Patient {
            $patient = Patient::create($request->safe()->except([
                'emergency_contact', 'known_allergies', 'chronic_conditions',
            ]) + ['created_by' => $request->user()->id]);

            $this->attachInitialMedicalData($request, $patient);

            return $patient;
        });

        $message = 'Dossier '.$patient->patient_number.' créé.';

        return $request->input('action') === 'open'
            ? redirect()->route('patients.show', $patient)->with('success', $message)
            : redirect()->route('patients.index')->with('success', $message);
    }

    /**
     * Dossier médical électronique — écran principal du patient (§13-14).
     *
     * Les onglets sont rendus côté serveur : seul le contenu de l'onglet
     * demandé est chargé, ce qui évite de construire l'ensemble du dossier
     * à chaque affichage.
     */
    public function show(Request $request, Patient $patient, MedicalTimeline $timeline): View
    {
        $this->authorize('view', $patient);

        $tab = $request->string('tab')->toString() ?: 'resume';

        $patient->load([
            'attendingDoctor:id,name,first_name,last_name,title',
            'allergies',
            'chronicConditions',
            'emergencyContacts',
        ]);

        return view('patients.show', [
            'patient' => $patient,
            'tab' => $tab,
            'tabData' => $this->loadTab($request, $patient, $tab, $timeline),
        ]);
    }

    public function edit(Patient $patient): View
    {
        $this->authorize('update', $patient);

        return view('patients.edit', [
            'patient' => $patient->load('emergencyContacts'),
            'doctors' => $this->doctors(),
        ]);
    }

    public function update(StorePatientRequest $request, Patient $patient): RedirectResponse
    {
        $patient->update($request->safe()->except([
            'emergency_contact', 'known_allergies', 'chronic_conditions',
        ]));

        return redirect()->route('patients.show', $patient)
            ->with('success', 'Dossier mis à jour.');
    }

    /**
     * Fiche patient au format PDF (§47).
     */
    public function summaryPdf(Patient $patient, PdfGenerator $pdf): Response
    {
        $this->authorize('view', $patient);

        return response($pdf->patientSummary($patient), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="fiche-'.$patient->patient_number.'.pdf"',
        ]);
    }

    /**
     * Export CSV de la liste filtrée (§11).
     *
     * L'export est diffusé en flux et par lots : la mémoire reste
     * constante quel que soit le nombre de dossiers.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Patient::class);

        $query = Patient::query()->search($request->string('q')->toString());

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'N° dossier', 'Nom', 'Prénom', 'Sexe', 'Date de naissance',
                'Téléphone', 'Groupe sanguin', 'Statut',
            ], ';');

            $query->orderBy('id')->chunk(500, function ($patients) use ($handle): void {
                foreach ($patients as $patient) {
                    fputcsv($handle, [
                        $patient->patient_number,
                        $patient->last_name,
                        $patient->first_name,
                        $patient->sexLabel(),
                        $patient->birth_date?->format('d/m/Y'),
                        $patient->phone,
                        $patient->blood_group,
                        $patient->status,
                    ], ';');
                }
            });

            fclose($handle);
        }, 'patients-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Charge uniquement les données de l'onglet demandé (§14).
     *
     * @return array<string, mixed>
     */
    private function loadTab(Request $request, Patient $patient, string $tab, MedicalTimeline $timeline): array
    {
        return match ($tab) {
            'consultations' => [
                'consultations' => $patient->consultations()
                    ->with(['doctor:id,name,first_name,last_name,title', 'service:id,name'])
                    ->withCount('diagnoses')
                    ->paginate(10, ['*'], 'page')->withQueryString(),
            ],
            'antecedents' => [
                'histories' => $patient->medicalHistories()->with('recorder:id,name,first_name,last_name,title')
                    ->get()->groupBy('category'),
            ],
            'allergies' => [
                'allergies' => $patient->allergies()->with('recorder:id,name,first_name,last_name,title')
                    ->orderByDesc('created_at')->get(),
            ],
            'medicaments' => [
                'medications' => $patient->medications()->with('prescriber:id,name,first_name,last_name,title')
                    ->orderByDesc('status')->orderByDesc('started_on')->get(),
            ],
            'ordonnances' => [
                'prescriptions' => $patient->prescriptions()
                    ->with(['doctor:id,name,first_name,last_name,title', 'items'])
                    ->paginate(10)->withQueryString(),
            ],
            'laboratoire' => [
                'labOrders' => $patient->labOrders()
                    ->with(['doctor:id,name,first_name,last_name,title', 'items.results'])
                    ->paginate(10)->withQueryString(),
            ],
            'imagerie' => [
                'imagingOrders' => $patient->imagingOrders()
                    ->with(['doctor:id,name,first_name,last_name,title', 'report'])
                    ->paginate(10)->withQueryString(),
            ],
            'hospitalisations' => [
                'hospitalizations' => $patient->hospitalizations()
                    ->with(['service:id,name', 'doctor:id,name,first_name,last_name,title'])
                    ->paginate(10)->withQueryString(),
            ],
            'soins' => [
                'nursingNotes' => $patient->nursingNotes()
                    ->with('nurse:id,name,first_name,last_name,title')
                    ->paginate(20)->withQueryString(),
                // La portée est appliquée en base : un soignant ne reçoit
                // jamais les soins d'un autre service, même en mémoire.
                'careOrders' => $request->user()->can('care_orders.view')
                    ? $patient->careOrders()
                        ->visibleTo($request->user())
                        ->with([
                            'prescriber:id,name,first_name,last_name,title',
                            'assignedNurse:id,name,first_name,last_name,title',
                            'completedBy:id,name,first_name,last_name,title',
                            'service:id,name',
                        ])
                        ->orderByRaw("CASE WHEN status = 'planned' THEN 0 ELSE 1 END")
                        ->orderBy('starts_at')
                        ->get()
                    : collect(),
                'assignableNurses' => $request->user()->can('care_orders.assign')
                    ? CareOrderController::assignableNurses($request->user())
                    : collect(),
                'openHospitalizations' => $patient->hospitalizations()
                    ->whereNull('discharged_at')
                    ->get(['id', 'hospitalization_number']),
            ],
            'rendez-vous' => [
                'appointments' => $patient->appointments()
                    ->with(['doctor:id,name,first_name,last_name,title', 'service:id,name'])
                    ->orderByDesc('scheduled_for')
                    ->paginate(10)->withQueryString(),
            ],
            'documents' => [
                'documents' => $patient->documents()
                    ->with('uploader:id,name,first_name,last_name,title')
                    ->paginate(12)->withQueryString(),
            ],
            'historique' => [
                'timeline' => $timeline->grouped(
                    $patient,
                    (array) $request->input('filters', []),
                    (int) $request->integer('limit', config('keneya.pagination.timeline')),
                ),
                'activeFilters' => (array) $request->input('filters', []),
                'limit' => (int) $request->integer('limit', config('keneya.pagination.timeline')),
            ],
            'audit' => [
                'auditLogs' => $patient->auditLogs()
                    ->with('causer:id,name,first_name,last_name,title')
                    ->paginate(25)->withQueryString(),
            ],
            default => [
                'lastConsultations' => $patient->consultations()
                    ->with(['doctor:id,name,first_name,last_name,title'])
                    ->limit(5)->get(),
                'latestVitals' => $patient->vitalSigns()->limit(1)->get()->first(),
                'vitalsHistory' => $patient->vitalSigns()->limit(12)->get()->reverse()->values(),
                'nextAppointment' => $patient->nextAppointment(),
                // Un même problème diagnostiqué à plusieurs consultations
                // ne doit apparaître qu'une fois dans le résumé : on garde
                // l'occurrence la plus récente de chaque libellé.
                'activeProblems' => $patient->diagnoses()
                    ->whereIn('status', ['confirmed', 'chronic'])
                    ->latest('diagnosed_on')->limit(30)->get()
                    ->unique('label')->take(6)->values(),
                'recentResults' => $patient->labResults()
                    ->with('item:id,exam_name')
                    ->latest('measured_at')->limit(6)->get(),
                'recentPrescriptions' => $patient->prescriptions()->with('items')->limit(3)->get(),
            ],
        };
    }

    /**
     * Crée les données médicales de premier niveau saisies au formulaire
     * de création (§12) : allergies connues et maladies chroniques.
     */
    private function attachInitialMedicalData(StorePatientRequest $request, Patient $patient): void
    {
        $contact = $request->input('emergency_contact', []);

        if (filled($contact['name'] ?? null) && filled($contact['phone'] ?? null)) {
            $patient->emergencyContacts()->create([
                'name' => $contact['name'],
                'relationship' => $contact['relationship'] ?? null,
                'phone' => $contact['phone'],
                'is_primary' => true,
            ]);
        }

        foreach ($this->splitList($request->input('known_allergies')) as $allergen) {
            Allergy::create([
                'patient_id' => $patient->id,
                'allergen' => $allergen,
                'severity' => 'unknown',
                'status' => 'active',
                'recorded_by' => $request->user()->id,
            ]);
        }

        foreach ($this->splitList($request->input('chronic_conditions')) as $label) {
            ChronicCondition::create([
                'patient_id' => $patient->id,
                'label' => $label,
                'status' => 'active',
                'recorded_by' => $request->user()->id,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function splitList(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Praticiens sélectionnables comme médecin traitant.
     */
    private function doctors()
    {
        return User::role(Rbac::ROLE_DOCTOR)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get(['id', 'name', 'first_name', 'last_name', 'title', 'speciality']);
    }
}
