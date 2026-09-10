@php
    /**
     * Navigation principale (§9).
     *
     * Chaque entrée déclare la permission qui la conditionne : le menu ne
     * montre que ce que l'utilisateur peut réellement atteindre. Le
     * masquage est un confort — la route reste protégée côté serveur.
     */
    $navigation = [
        ['route' => 'dashboard',            'label' => 'Tableau de bord',  'permission' => null,                  'icon' => 'home'],
        ['route' => 'patients.index',       'label' => 'Patients',         'permission' => 'patients.view',       'icon' => 'users'],
        ['route' => 'consultations.index',  'label' => 'Consultations',    'permission' => 'consultations.view',  'icon' => 'stethoscope'],
        ['route' => 'appointments.index',   'label' => 'Rendez-vous',      'permission' => 'appointments.view',   'icon' => 'calendar'],
        ['route' => 'laboratory.index',     'label' => 'Laboratoire',      'permission' => 'laboratory.view',     'icon' => 'flask'],
        ['route' => 'imaging.index',        'label' => 'Imagerie',         'permission' => 'imaging.view',        'icon' => 'scan'],
        ['route' => 'hospitalizations.index','label' => 'Hospitalisation', 'permission' => 'hospitalizations.view','icon' => 'bed'],
        ['route' => 'prescriptions.index',  'label' => 'Ordonnances',      'permission' => 'prescriptions.view',  'icon' => 'pill'],
        ['route' => 'documents.index',      'label' => 'Documents',        'permission' => 'documents.view',      'icon' => 'document'],
        ['route' => 'sms.index',            'label' => 'SMS',              'permission' => 'sms.view',            'icon' => 'chat'],
        ['route' => 'users.index',          'label' => 'Utilisateurs',     'permission' => 'users.manage',        'icon' => 'shield'],
        ['route' => 'audit.index',          'label' => 'Audit',            'permission' => 'audit.view',          'icon' => 'clipboard'],
        ['route' => 'settings.index',       'label' => 'Paramètres',       'permission' => null,                  'icon' => 'cog'],
    ];
@endphp

<div class="flex h-20 items-center gap-3 border-b border-ink-200 px-5">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
        <img src="{{ asset('assets/logo_kdme.png') }}" alt="" class="h-14 w-14 rounded-lg object-contain">
        <span class="text-lg font-semibold tracking-tight text-ink-900">Keneya-DME</span>
    </a>
    <button type="button" class="ml-auto rounded-lg p-2 text-ink-500 hover:bg-ink-100 lg:hidden"
            @click="sidebarOpen = false" aria-label="Fermer la navigation">
        <x-icon name="close" class="h-5 w-5"/>
    </button>
</div>

<nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
    @foreach ($navigation as $item)
        @continue($item['permission'] && ! auth()->user()->can($item['permission']))
        @php $active = request()->routeIs(Str::before($item['route'], '.').'.*') || request()->routeIs($item['route']); @endphp
        <a href="{{ route($item['route']) }}"
           class="{{ $active ? 'k-nav-link-active' : 'k-nav-link' }}"
           @if ($active) aria-current="page" @endif>
            <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0"/>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>

<div class="border-t border-ink-200 p-3">
    <div class="rounded-lg bg-ink-50 px-3 py-2.5">
        <p class="text-xs font-medium text-ink-500">Établissement</p>
        <p class="mt-0.5 text-sm font-semibold text-ink-800">{{ config('keneya.facility.name') }}</p>
    </div>
</div>
