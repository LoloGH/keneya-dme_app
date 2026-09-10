@php
    /**
     * Barre supérieure (§7) : recherche globale, notifications, profil.
     * Le compteur de notifications est calculé une fois par requête.
     */
    $unreadCount = \Illuminate\Support\Facades\DB::table('notifications')
        ->where('notifiable_type', auth()->user()->getMorphClass())
        ->where('notifiable_id', auth()->id())
        ->whereNull('read_at')
        ->count();
@endphp

<header class="k-no-print sticky top-0 z-20 flex h-20 items-center gap-3 border-b border-ink-200 bg-white px-4 sm:px-6 lg:px-8">
    <button type="button" class="rounded-lg p-2 text-ink-600 hover:bg-ink-100 lg:hidden"
            @click="sidebarOpen = true" aria-label="Ouvrir la navigation">
        <x-icon name="menu"/>
    </button>

    {{-- Recherche globale (§34) --}}
    <form action="{{ route('search') }}" method="GET" class="min-w-0 flex-1 max-w-xl" role="search"
          x-data="globalSearch">
        <label for="recherche-globale" class="sr-only">Recherche globale</label>
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 h-4.5 w-4.5 -translate-y-1/2 text-ink-400"/>
            <input id="recherche-globale" type="search" name="q" x-model="term"
                   value="{{ request('q') }}"
                   @input="submitDebounced($event)"
                   placeholder="Rechercher un patient, une ordonnance, un examen…"
                   class="k-input pl-10" autocomplete="off">
        </div>
    </form>

    <div class="ml-auto flex items-center gap-1.5">
        <a href="{{ route('notifications.index') }}"
           class="relative rounded-lg p-2 text-ink-600 hover:bg-ink-100"
           aria-label="Notifications{{ $unreadCount ? " ({$unreadCount} non lues)" : '' }}">
            <x-icon name="bell"/>
            @if ($unreadCount)
                <span class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full
                             bg-red-600 px-1 text-[10px] font-semibold text-white">
                    {{ min($unreadCount, 99) }}
                </span>
            @endif
        </a>

        {{-- Menu profil --}}
        <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-ink-100"
                    :aria-expanded="open" aria-haspopup="true">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-clinic-600 text-sm font-semibold text-white">
                    {{ auth()->user()->initials() }}
                </span>
                <span class="hidden text-left sm:block">
                    <span class="block text-sm font-medium text-ink-900">{{ auth()->user()->displayName() }}</span>
                    <span class="block text-xs text-ink-500">
                        {{ \App\Support\Rbac::allRoleLabels()[auth()->user()->getRoleNames()->first()] ?? 'Utilisateur' }}
                    </span>
                </span>
            </button>

            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute right-0 z-30 mt-2 w-64 rounded-xl border border-ink-200 bg-white p-2 shadow-lg">
                <div class="border-b border-ink-100 px-3 py-2">
                    <p class="text-sm font-medium text-ink-900">{{ auth()->user()->displayName() }}</p>
                    <p class="truncate text-xs text-ink-500">{{ auth()->user()->email }}</p>
                    @if (auth()->user()->service)
                        <p class="mt-1 text-xs text-ink-500">{{ auth()->user()->service->name }}</p>
                    @endif
                </div>
                <a href="{{ route('settings.index') }}" class="k-nav-link mt-1">
                    <x-icon name="cog" class="h-4.5 w-4.5"/> Paramètres
                </a>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="k-nav-link w-full text-left text-red-600 hover:bg-red-50">
                        <x-icon name="logout" class="h-4.5 w-4.5"/> Se déconnecter
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
