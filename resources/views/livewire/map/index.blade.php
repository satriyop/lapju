<?php

use App\Models\Location;
use App\Models\Office;
use App\Models\Project;
use App\Models\TaskProgress;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $selectedKodimId = null;

    public ?int $selectedKoramilId = null;

    public string $statusFilter = 'all';

    public ?int $selectedProjectId = null;

    public function with(): array
    {
        $currentUser = auth()->user();

        // Get Kodims (level 3)
        $kodims = Office::whereHas('level', fn ($q) => $q->where('level', 3))
            ->orderBy('name')
            ->get();

        // Get Koramils filtered by selected Kodim
        $koramils = Office::whereHas('level', fn ($q) => $q->where('level', 4))
            ->when($this->selectedKodimId, fn ($q) => $q->where('parent_id', $this->selectedKodimId))
            ->orderBy('name')
            ->get();

        // Build projects query
        $projectsQuery = Project::query()
            ->with(['location', 'office.parent', 'partner', 'tasks']);

        // Filter by Kodim (parent of Koramil)
        if ($this->selectedKodimId) {
            $projectsQuery->whereHas('office', fn ($q) => $q->where('parent_id', $this->selectedKodimId));
        }

        // Filter by Koramil
        if ($this->selectedKoramilId) {
            $projectsQuery->where('office_id', $this->selectedKoramilId);
        }

        // Filter by status
        if ($this->statusFilter !== 'all') {
            $projectsQuery->where('status', $this->statusFilter);
        }

        // Role-based filtering
        if ($currentUser->hasRole('Reporter')) {
            $projectsQuery->whereHas('users', fn ($q) => $q->where('users.id', $currentUser->id));
        } elseif ($currentUser->hasRole('Kodim Admin') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                $projectsQuery->whereHas('office', fn ($q) => $q->where('parent_id', $currentUser->office_id));
            }
        } elseif ($currentUser->hasRole('Koramil Admin') && $currentUser->office_id) {
            $projectsQuery->where(function ($q) use ($currentUser) {
                $q->where('office_id', $currentUser->office_id)
                    ->orWhereHas('users', fn ($uq) => $uq->where('office_id', $currentUser->office_id));
            });
        }

        $projects = $projectsQuery->get();

        // Calculate progress for each project
        foreach ($projects as $project) {
            $latestDate = TaskProgress::where('project_id', $project->id)->max('progress_date');
            if ($latestDate) {
                $project->calculated_progress = round(
                    TaskProgress::where('project_id', $project->id)
                        ->where('progress_date', $latestDate)
                        ->avg('percentage') ?? 0,
                    1
                );
            } else {
                $project->calculated_progress = 0;
            }
        }

        // Get locations with coordinates for the map
        $locationsWithCoords = Location::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        // Selected project details
        $selectedProject = $this->selectedProjectId
            ? Project::with(['location', 'office.parent', 'partner', 'users', 'tasks'])->find($this->selectedProjectId)
            : null;

        if ($selectedProject) {
            $latestDate = TaskProgress::where('project_id', $selectedProject->id)->max('progress_date');
            $selectedProject->calculated_progress = $latestDate
                ? round(TaskProgress::where('project_id', $selectedProject->id)->where('progress_date', $latestDate)->avg('percentage') ?? 0, 1)
                : 0;
        }

        // Summary stats
        $totalProjects = $projects->count();
        $activeProjects = $projects->where('status', 'active')->count();
        $completedProjects = $projects->where('status', 'completed')->count();
        $avgProgress = $projects->avg('calculated_progress');

        // Prepare map data for JavaScript (only projects with valid coordinates)
        $mapData = $projects
            ->filter(fn ($p) => $p->location && $p->location->latitude && $p->location->longitude)
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'progress' => $p->calculated_progress,
                'lat' => (float) $p->location->latitude,
                'lng' => (float) $p->location->longitude,
                'village' => $p->location->village_name ?? '',
                'district' => $p->location->district_name ?? '',
            ])->values()->toArray();

        return [
            'kodims' => $kodims,
            'koramils' => $koramils,
            'projects' => $projects,
            'locationsWithCoords' => $locationsWithCoords,
            'selectedProject' => $selectedProject,
            'totalProjects' => $totalProjects,
            'activeProjects' => $activeProjects,
            'completedProjects' => $completedProjects,
            'avgProgress' => round($avgProgress, 1),
            'mapData' => $mapData,
        ];
    }

    public function selectProject(?int $projectId): void
    {
        $this->selectedProjectId = $projectId;
    }

    public function updatedSelectedKodimId(): void
    {
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoramilId(): void
    {
        $this->selectedProjectId = null;
    }

    public function updatedStatusFilter(): void
    {
        $this->selectedProjectId = null;
    }
}; ?>

<div class="flex h-full w-full flex-col" x-data="mapController()">
    {{-- Custom styles for the map interface --}}
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Outfit:wght@300;400;500;600;700&display=swap');

        .map-container {
            font-family: 'Outfit', sans-serif;
        }

        .mono-text {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Topographic pattern background */
        .topo-pattern {
            background-color: #0f172a;
            background-image:
                radial-gradient(ellipse at 20% 30%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 70%, rgba(59, 130, 246, 0.08) 0%, transparent 50%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M30 0c16.569 0 30 13.431 30 30 0 16.569-13.431 30-30 30C13.431 60 0 46.569 0 30 0 13.431 13.431 0 30 0zm0 4C15.64 4 4 15.64 4 30s11.64 26 26 26 26-11.64 26-26S44.36 4 30 4zm0 8c9.941 0 18 8.059 18 18s-8.059 18-18 18-18-8.059-18-18 8.059-18 18-18zm0 4c-7.732 0-14 6.268-14 14s6.268 14 14 14 14-6.268 14-14-6.268-14-14-14z' fill='%231e293b' fill-opacity='0.4' fill-rule='evenodd'/%3E%3C/svg%3E");
        }

        /* Custom map styling */
        .leaflet-container {
            background: #0f172a !important;
            font-family: 'Outfit', sans-serif;
        }

        .leaflet-tile-pane {
            filter: saturate(0.7) brightness(0.85) contrast(1.1);
        }

        .leaflet-control-zoom {
            border: none !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4) !important;
        }

        .leaflet-control-zoom a {
            background: #1e293b !important;
            color: #94a3b8 !important;
            border: 1px solid #334155 !important;
            font-family: 'JetBrains Mono', monospace !important;
        }

        .leaflet-control-zoom a:hover {
            background: #334155 !important;
            color: #f1f5f9 !important;
        }

        /* Custom marker pulse animation */
        @keyframes marker-pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }

        .marker-active {
            animation: marker-pulse 2s ease-in-out infinite;
        }

        /* Glowing border effect */
        .glow-border {
            box-shadow:
                0 0 0 1px rgba(34, 197, 94, 0.3),
                0 0 20px rgba(34, 197, 94, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .glow-border-blue {
            box-shadow:
                0 0 0 1px rgba(59, 130, 246, 0.3),
                0 0 20px rgba(59, 130, 246, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        /* Stats card styling */
        .stat-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.9) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(51, 65, 85, 0.5);
        }

        /* Project list item hover */
        .project-item {
            transition: all 0.2s ease;
        }

        .project-item:hover {
            background: rgba(51, 65, 85, 0.5);
            transform: translateX(4px);
        }

        .project-item.selected {
            background: rgba(34, 197, 94, 0.15);
            border-left: 3px solid #22c55e;
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #1e293b;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        /* Progress bar glow */
        .progress-glow {
            box-shadow: 0 0 10px currentColor;
        }

        /* Grid overlay pattern */
        .grid-overlay {
            background-image:
                linear-gradient(rgba(51, 65, 85, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(51, 65, 85, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }

        /* Custom popup styling */
        .leaflet-popup-content-wrapper {
            background: rgba(15, 23, 42, 0.95) !important;
            border: 1px solid #334155 !important;
            border-radius: 12px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5) !important;
        }

        .leaflet-popup-tip {
            background: rgba(15, 23, 42, 0.95) !important;
            border: 1px solid #334155 !important;
        }

        .leaflet-popup-content {
            margin: 0 !important;
            color: #f1f5f9 !important;
            font-family: 'Outfit', sans-serif !important;
        }
    </style>

    <div class="map-container flex h-full flex-1 flex-col topo-pattern">
        {{-- Header with title and stats --}}
        <div class="border-b border-slate-700/50 bg-slate-900/80 px-6 py-4 backdrop-blur-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/20 ring-1 ring-emerald-500/30">
                        <svg class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold tracking-tight text-white">Project Map</h1>
                        <p class="mono-text text-xs text-slate-400">GEOGRAPHIC DISTRIBUTION & STATUS</p>
                    </div>
                </div>

                {{-- Quick Stats --}}
                <div class="flex items-center gap-6">
                    <div class="stat-card rounded-lg px-4 py-2">
                        <div class="mono-text text-xs text-slate-400">TOTAL</div>
                        <div class="text-xl font-bold text-white">{{ $totalProjects }}</div>
                    </div>
                    <div class="stat-card rounded-lg px-4 py-2">
                        <div class="mono-text text-xs text-emerald-400">ACTIVE</div>
                        <div class="text-xl font-bold text-emerald-400">{{ $activeProjects }}</div>
                    </div>
                    <div class="stat-card rounded-lg px-4 py-2">
                        <div class="mono-text text-xs text-blue-400">COMPLETED</div>
                        <div class="text-xl font-bold text-blue-400">{{ $completedProjects }}</div>
                    </div>
                    <div class="stat-card rounded-lg px-4 py-2">
                        <div class="mono-text text-xs text-amber-400">AVG PROGRESS</div>
                        <div class="text-xl font-bold text-amber-400">{{ $avgProgress }}%</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Main content area --}}
        <div class="flex flex-1 overflow-hidden">
            {{-- Left sidebar - Filters & Project List --}}
            <div class="flex w-80 flex-col border-r border-slate-700/50 bg-slate-900/60 backdrop-blur-sm">
                {{-- Filters --}}
                <div class="space-y-4 border-b border-slate-700/50 p-4">
                    <div class="mono-text text-xs font-medium uppercase tracking-wider text-slate-400">Filters</div>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1.5 block text-xs text-slate-400">Kodim</label>
                            <select
                                wire:model.live="selectedKodimId"
                                class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                                <option value="">All Kodims</option>
                                @foreach($kodims as $kodim)
                                    <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs text-slate-400">Koramil</label>
                            <select
                                wire:model.live="selectedKoramilId"
                                class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                @if(!$selectedKodimId) disabled @endif
                            >
                                <option value="">All Koramils</option>
                                @foreach($koramils as $koramil)
                                    <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs text-slate-400">Status</label>
                            <select
                                wire:model.live="statusFilter"
                                class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                                <option value="all">All Status</option>
                                <option value="planning">Planning</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="on_hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Project List --}}
                <div class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-700/50 px-4 py-3">
                        <span class="mono-text text-xs font-medium uppercase tracking-wider text-slate-400">Projects</span>
                        <span class="mono-text rounded bg-slate-700 px-2 py-0.5 text-xs text-slate-300">{{ $projects->count() }}</span>
                    </div>

                    <div class="custom-scrollbar flex-1 overflow-y-auto">
                        @forelse($projects as $project)
                            <div
                                wire:key="project-list-{{ $project->id }}"
                                wire:click="selectProject({{ $project->id }})"
                                class="project-item cursor-pointer border-b border-slate-700/30 p-4 {{ $selectedProjectId === $project->id ? 'selected' : '' }}"
                                @click="focusProject({{ $project->id }}, {{ $project->location->latitude ?? 'null' }}, {{ $project->location->longitude ?? 'null' }})"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-white">{{ $project->name }}</div>
                                        <div class="mt-1 flex items-center gap-2 text-xs text-slate-400">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            </svg>
                                            {{ $project->location->village_name ?? 'No location' }}
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                            @if($project->status === 'active') bg-emerald-500/20 text-emerald-400 ring-1 ring-emerald-500/30
                                            @elseif($project->status === 'completed') bg-blue-500/20 text-blue-400 ring-1 ring-blue-500/30
                                            @elseif($project->status === 'planning') bg-slate-500/20 text-slate-400 ring-1 ring-slate-500/30
                                            @else bg-amber-500/20 text-amber-400 ring-1 ring-amber-500/30
                                            @endif
                                        ">
                                            {{ ucfirst($project->status) }}
                                        </span>
                                        <span class="mono-text text-xs text-slate-500">{{ $project->calculated_progress }}%</span>
                                    </div>
                                </div>

                                {{-- Progress bar --}}
                                <div class="mt-3 h-1 overflow-hidden rounded-full bg-slate-700">
                                    <div
                                        class="h-full rounded-full transition-all duration-500
                                            @if($project->calculated_progress >= 100) bg-blue-500
                                            @elseif($project->calculated_progress >= 75) bg-emerald-500
                                            @elseif($project->calculated_progress >= 50) bg-amber-500
                                            @else bg-slate-500
                                            @endif
                                        "
                                        style="width: {{ min($project->calculated_progress, 100) }}%"
                                    ></div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center p-8 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-800">
                                    <svg class="h-6 w-6 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                </div>
                                <p class="mt-3 text-sm text-slate-400">No projects found</p>
                                <p class="text-xs text-slate-500">Adjust filters to see projects</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Map Area --}}
            <div class="relative flex-1" wire:ignore>
                {{-- Map container --}}
                <div id="map" class="absolute inset-0 z-0" style="z-index: 1;"></div>

                {{-- Grid overlay --}}
                <div class="pointer-events-none absolute inset-0 z-10 grid-overlay opacity-30"></div>

                {{-- Coordinates display --}}
                <div class="absolute bottom-4 left-4 z-20 rounded-lg bg-slate-900/90 px-3 py-2 backdrop-blur-sm ring-1 ring-slate-700">
                    <div class="mono-text flex items-center gap-4 text-xs">
                        <span class="text-slate-400">LAT: <span x-text="cursorLat" class="text-emerald-400">-7.5500</span></span>
                        <span class="text-slate-400">LNG: <span x-text="cursorLng" class="text-blue-400">110.8243</span></span>
                    </div>
                </div>

                {{-- Legend --}}
                <div class="absolute bottom-4 right-4 z-20 rounded-lg bg-slate-900/90 p-3 backdrop-blur-sm ring-1 ring-slate-700">
                    <div class="mono-text mb-2 text-xs text-slate-400">LEGEND</div>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-emerald-500/30"></div>
                            <span class="text-xs text-slate-300">Active</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-blue-500 ring-2 ring-blue-500/30"></div>
                            <span class="text-xs text-slate-300">Completed</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-slate-500 ring-2 ring-slate-500/30"></div>
                            <span class="text-xs text-slate-300">Planning</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-amber-500 ring-2 ring-amber-500/30"></div>
                            <span class="text-xs text-slate-300">On Hold</span>
                        </div>
                    </div>
                </div>

                {{-- No coordinates notice --}}
                @if($locationsWithCoords->isEmpty())
                    <div class="absolute inset-0 z-30 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm">
                        <div class="max-w-md rounded-xl bg-slate-800 p-8 text-center ring-1 ring-slate-700">
                            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-500/20 ring-1 ring-amber-500/30">
                                <svg class="h-8 w-8 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-white">No Coordinates Available</h3>
                            <p class="mt-2 text-sm text-slate-400">Location coordinates have not been set yet. Add latitude and longitude to locations in the admin panel to display them on the map.</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right sidebar - Project Details --}}
            @if($selectedProject)
                <div class="w-96 border-l border-slate-700/50 bg-slate-900/60 backdrop-blur-sm">
                    <div class="flex h-full flex-col">
                        {{-- Project header --}}
                        <div class="border-b border-slate-700/50 p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($selectedProject->status === 'active') bg-emerald-500/20 text-emerald-400
                                        @elseif($selectedProject->status === 'completed') bg-blue-500/20 text-blue-400
                                        @elseif($selectedProject->status === 'planning') bg-slate-500/20 text-slate-400
                                        @else bg-amber-500/20 text-amber-400
                                        @endif
                                    ">
                                        {{ ucfirst($selectedProject->status) }}
                                    </span>
                                    <h2 class="mt-2 text-lg font-semibold text-white">{{ $selectedProject->name }}</h2>
                                </div>
                                <button
                                    wire:click="selectProject(null)"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-white"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Progress circle --}}
                        <div class="border-b border-slate-700/50 p-6">
                            <div class="flex items-center justify-center">
                                <div class="relative h-32 w-32">
                                    <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                                        <circle cx="50" cy="50" r="45" fill="none" stroke="#334155" stroke-width="8" />
                                        <circle
                                            cx="50" cy="50" r="45" fill="none"
                                            stroke="@if($selectedProject->calculated_progress >= 100) #3b82f6 @elseif($selectedProject->calculated_progress >= 75) #22c55e @elseif($selectedProject->calculated_progress >= 50) #f59e0b @else #64748b @endif"
                                            stroke-width="8"
                                            stroke-linecap="round"
                                            stroke-dasharray="{{ $selectedProject->calculated_progress * 2.83 }} 283"
                                            class="transition-all duration-1000"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="mono-text text-3xl font-bold text-white">{{ $selectedProject->calculated_progress }}%</span>
                                        <span class="text-xs text-slate-400">Progress</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Project details --}}
                        <div class="custom-scrollbar flex-1 overflow-y-auto p-4">
                            <div class="space-y-4">
                                {{-- Location --}}
                                <div class="rounded-lg bg-slate-800/50 p-3 ring-1 ring-slate-700/50">
                                    <div class="mono-text mb-2 text-xs text-slate-400">LOCATION</div>
                                    <div class="text-sm text-white">{{ $selectedProject->location->village_name ?? '-' }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ $selectedProject->location->district_name ?? '' }}, {{ $selectedProject->location->city_name ?? '' }}
                                    </div>
                                    @if($selectedProject->location->latitude && $selectedProject->location->longitude)
                                        <div class="mono-text mt-2 text-xs text-slate-500">
                                            {{ $selectedProject->location->latitude }}, {{ $selectedProject->location->longitude }}
                                        </div>
                                    @endif
                                </div>

                                {{-- Office --}}
                                <div class="rounded-lg bg-slate-800/50 p-3 ring-1 ring-slate-700/50">
                                    <div class="mono-text mb-2 text-xs text-slate-400">OFFICE</div>
                                    <div class="text-sm text-white">{{ $selectedProject->office->name ?? '-' }}</div>
                                    @if($selectedProject->office?->parent)
                                        <div class="text-xs text-slate-400">{{ $selectedProject->office->parent->name }}</div>
                                    @endif
                                </div>

                                {{-- Partner --}}
                                <div class="rounded-lg bg-slate-800/50 p-3 ring-1 ring-slate-700/50">
                                    <div class="mono-text mb-2 text-xs text-slate-400">PARTNER</div>
                                    <div class="text-sm text-white">{{ $selectedProject->partner->name ?? '-' }}</div>
                                </div>

                                {{-- Timeline --}}
                                <div class="rounded-lg bg-slate-800/50 p-3 ring-1 ring-slate-700/50">
                                    <div class="mono-text mb-2 text-xs text-slate-400">TIMELINE</div>
                                    <div class="flex items-center justify-between text-sm">
                                        <div>
                                            <div class="text-xs text-slate-400">Start</div>
                                            <div class="text-white">{{ $selectedProject->start_date?->format('M d, Y') ?? '-' }}</div>
                                        </div>
                                        <svg class="h-4 w-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                        </svg>
                                        <div class="text-right">
                                            <div class="text-xs text-slate-400">End</div>
                                            <div class="text-white">{{ $selectedProject->end_date?->format('M d, Y') ?? '-' }}</div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Team --}}
                                @if($selectedProject->users->isNotEmpty())
                                    <div class="rounded-lg bg-slate-800/50 p-3 ring-1 ring-slate-700/50">
                                        <div class="mono-text mb-2 text-xs text-slate-400">TEAM</div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($selectedProject->users as $user)
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-700 px-2.5 py-1 text-xs text-slate-200">
                                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-600 text-[10px]">
                                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                                    </span>
                                                    {{ $user->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="border-t border-slate-700/50 p-4">
                            <a
                                href="{{ route('progress.index', ['project' => $selectedProject->id]) }}"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-500"
                                wire:navigate
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                View Progress Details
                            </a>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Pass mapData to JavaScript --}}
        <div x-data x-init="$dispatch('map-data-updated', { projects: {{ Js::from($mapData) }} })"></div>

        <script>
            function mapController() {
                return {
                    map: null,
                    markers: [],
                    cursorLat: '-7.5500',
                    cursorLng: '110.8243',
                    mapInitialized: false,
                    currentProjects: @json($mapData),

                    init() {
                        // Listen for map data updates from Livewire
                        this.$watch('currentProjects', (newProjects) => {
                            if (this.mapInitialized && this.map) {
                                this.updateMarkers(newProjects);
                            }
                        });

                        // Listen for data updates via events
                        window.addEventListener('map-data-updated', (e) => {
                            this.currentProjects = e.detail.projects;
                            if (this.mapInitialized && this.map) {
                                this.updateMarkers(e.detail.projects);
                            }
                        });

                        // Load Leaflet and initialize map
                        this.loadLeafletAndInit();
                    },

                    loadLeafletAndInit() {
                        // Check if Leaflet is already loaded
                        if (typeof L !== 'undefined') {
                            this.$nextTick(() => this.initMap());
                            return;
                        }

                        // Load Leaflet JS (CSS is already in <head>)
                        const script = document.createElement('script');
                        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
                        script.crossOrigin = '';
                        script.onload = () => {
                            L.Icon.Default.imagePath = 'https://unpkg.com/leaflet@1.9.4/dist/images/';
                            this.$nextTick(() => this.initMap());
                        };
                        script.onerror = () => console.error('Failed to load Leaflet');
                        document.head.appendChild(script);
                    },

                    initMap() {
                        if (typeof L === 'undefined') {
                            console.error('Leaflet not loaded');
                            return;
                        }

                        const mapContainer = document.getElementById('map');
                        if (!mapContainer) {
                            console.error('Map container not found');
                            return;
                        }

                        // Check if map already exists on this container
                        if (mapContainer._leaflet_id) {
                            console.log('Map already initialized');
                            return;
                        }

                        // Initialize the map centered on Central Java
                        this.map = L.map('map', {
                            zoomControl: true,
                            attributionControl: false
                        }).setView([-7.5500, 110.8243], 10);

                        // Use CartoDB dark tiles
                        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                            maxZoom: 19,
                        }).addTo(this.map);

                        // Force map to recalculate size
                        setTimeout(() => {
                            if (this.map) {
                                this.map.invalidateSize();
                            }
                        }, 100);

                        // Track cursor position
                        this.map.on('mousemove', (e) => {
                            this.cursorLat = e.latlng.lat.toFixed(4);
                            this.cursorLng = e.latlng.lng.toFixed(4);
                        });

                        this.mapInitialized = true;

                        // Add initial markers
                        this.updateMarkers(this.currentProjects);

                        console.log('Map initialized successfully');
                    },

                    updateMarkers(projects) {
                        if (!this.map) return;

                        // Clear existing markers
                        this.markers.forEach(m => {
                            if (this.map.hasLayer(m.marker)) {
                                this.map.removeLayer(m.marker);
                            }
                        });
                        this.markers = [];

                        console.log('Updating markers for', projects.length, 'projects');

                        // Add new markers
                        projects.forEach(project => {
                            if (project.lat && project.lng) {
                                const color = this.getStatusColor(project.status);
                                const marker = L.circleMarker([project.lat, project.lng], {
                                    radius: 10,
                                    fillColor: color,
                                    color: color,
                                    weight: 2,
                                    opacity: 1,
                                    fillOpacity: 0.6
                                }).addTo(this.map);

                                const popupContent = `
                                    <div class="p-3 min-w-[200px]">
                                        <div class="font-semibold text-white text-sm mb-1">${project.name}</div>
                                        <div class="text-slate-400 text-xs mb-2">${project.village}, ${project.district}</div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs px-2 py-0.5 rounded-full" style="background: ${color}20; color: ${color}; border: 1px solid ${color}40;">
                                                ${project.status.charAt(0).toUpperCase() + project.status.slice(1)}
                                            </span>
                                            <span class="text-xs text-slate-300 font-mono">${project.progress}%</span>
                                        </div>
                                        <div class="mt-2 h-1 bg-slate-700 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full" style="width: ${Math.min(project.progress, 100)}%; background: ${color};"></div>
                                        </div>
                                    </div>
                                `;

                                marker.bindPopup(popupContent);

                                marker.on('click', () => {
                                    @this.selectProject(project.id);
                                });

                                this.markers.push({ id: project.id, marker: marker, lat: project.lat, lng: project.lng });
                            }
                        });
                    },

                    getStatusColor(status) {
                        const colors = {
                            'active': '#22c55e',
                            'completed': '#3b82f6',
                            'planning': '#64748b',
                            'on_hold': '#f59e0b'
                        };
                        return colors[status] || '#64748b';
                    },

                    focusProject(projectId, lat, lng) {
                        if (lat && lng && this.map) {
                            this.map.setView([lat, lng], 14, { animate: true });
                            const markerData = this.markers.find(m => m.id === projectId);
                            if (markerData) {
                                markerData.marker.openPopup();
                            }
                        }
                    }
                }
            }
        </script>
    </div>
</div>
