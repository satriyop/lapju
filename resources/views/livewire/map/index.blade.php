<?php

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $selectedKodimId = null;

    public ?int $selectedKoramilId = null;

    public string $statusFilter = 'all';

    public ?int $selectedProjectId = null;

    // Performance optimization: Cache frequently accessed data
    private $cachedProjects = null;

    private $cachedOfficeLevels = null;

    private $cachedLeafTasks = [];

    private $cachedBatchProgress = null;

    /**
     * Get cached office levels (changes infrequently - cache for 1 hour)
     */
    private function getCachedOfficeLevels(): object
    {
        if ($this->cachedOfficeLevels === null) {
            $this->cachedOfficeLevels = cache()->remember('map_office_levels', 3600, function () {
                $levels = OfficeLevel::all()->keyBy('level');

                return (object) [
                    'kodim' => $levels->get(3),
                    'koramil' => $levels->get(4),
                ];
            });
        }

        return $this->cachedOfficeLevels;
    }

    /**
     * Clear caches when filters change
     */
    private function clearCaches(): void
    {
        $this->cachedProjects = null;
        $this->cachedLeafTasks = [];
        $this->cachedBatchProgress = null;
    }

    /**
     * Get cached leaf tasks for a project using nested set columns
     */
    private function getCachedLeafTasks(int $projectId)
    {
        if (! isset($this->cachedLeafTasks[$projectId])) {
            // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1
            $this->cachedLeafTasks[$projectId] = Task::select('id', 'project_id', 'weight')
                ->where('project_id', $projectId)
                ->whereRaw('_rgt = _lft + 1')
                ->get();
        }

        return $this->cachedLeafTasks[$projectId];
    }

    /**
     * Batch load all leaf tasks for multiple projects in ONE query
     */
    private function batchLoadAllLeafTasks(array $projectIds): void
    {
        if (empty($projectIds)) {
            return;
        }

        $missingProjectIds = array_diff($projectIds, array_keys($this->cachedLeafTasks));

        if (empty($missingProjectIds)) {
            return;
        }

        // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1
        $allLeafTasks = Task::select('id', 'project_id', 'weight')
            ->whereIn('project_id', $missingProjectIds)
            ->whereRaw('_rgt = _lft + 1')
            ->get()
            ->groupBy('project_id');

        foreach ($allLeafTasks as $projectId => $tasks) {
            $this->cachedLeafTasks[$projectId] = $tasks;
        }
    }

    /**
     * Batch load latest task progress for multiple projects
     * OPTIMIZED: Uses joinSub to avoid correlated subqueries
     */
    private function batchLoadTaskProgress(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        // Get latest progress date for each task in each project
        $latestDates = DB::table('task_progress')
            ->select('task_id', 'project_id', DB::raw('MAX(progress_date) as max_date'))
            ->whereIn('project_id', $projectIds)
            ->groupBy('task_id', 'project_id');

        // Join to get the actual progress values at those dates
        $latestProgress = DB::table('task_progress as tp1')
            ->select('tp1.task_id', 'tp1.project_id', 'tp1.percentage', 'tp1.progress_date')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('tp1.task_id', '=', 'latest.task_id')
                    ->on('tp1.project_id', '=', 'latest.project_id')
                    ->on('tp1.progress_date', '=', 'latest.max_date');
            })
            ->get()
            ->keyBy(fn ($item) => "{$item->project_id}_{$item->task_id}");

        return $latestProgress->all();
    }

    /**
     * Get cached batch progress for all projects
     */
    private function getCachedBatchProgress(array $projectIds): array
    {
        if ($this->cachedBatchProgress === null) {
            $this->cachedBatchProgress = $this->batchLoadTaskProgress($projectIds);
        }

        return $this->cachedBatchProgress;
    }

    /**
     * Calculate weighted progress for a project using pre-loaded data
     */
    private function calculateProjectProgress(int $projectId, array $preloadedProgress): float
    {
        $leafTasks = $this->getCachedLeafTasks($projectId);

        if ($leafTasks->isEmpty()) {
            return 0;
        }

        $totalWeight = $leafTasks->sum('weight');

        if ($totalWeight == 0) {
            return 0;
        }

        $totalWeightedProgress = 0;

        foreach ($leafTasks as $task) {
            $progressKey = "{$projectId}_{$task->id}";
            $latestProgress = $preloadedProgress[$progressKey] ?? null;

            if ($latestProgress) {
                $taskPercentage = min((float) $latestProgress->percentage, 100);
                $totalWeightedProgress += ($taskPercentage * $task->weight) / $totalWeight;
            }
        }

        return round($totalWeightedProgress, 1);
    }

    /**
     * Get filtered projects with caching
     */
    private function getFilteredProjects()
    {
        if ($this->cachedProjects !== null) {
            return $this->cachedProjects;
        }

        $currentUser = auth()->user();

        // Build projects query - removed 'tasks' from eager loading (not needed for map)
        $projectsQuery = Project::query()
            ->with(['location', 'office.parent', 'partner']);

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

        $this->cachedProjects = $projectsQuery->get();

        return $this->cachedProjects;
    }

    public function with(): array
    {
        $levels = $this->getCachedOfficeLevels();

        // OPTIMIZED: Use direct level_id lookup instead of whereHas subquery
        $kodims = $levels->kodim
            ? Office::select('id', 'name')
                ->where('level_id', $levels->kodim->id)
                ->orderBy('name')
                ->get()
            : collect();

        // OPTIMIZED: Use direct level_id lookup
        $koramils = $levels->koramil
            ? Office::select('id', 'name')
                ->where('level_id', $levels->koramil->id)
                ->when($this->selectedKodimId, fn ($q) => $q->where('parent_id', $this->selectedKodimId))
                ->orderBy('name')
                ->get()
            : collect();

        // Get filtered projects
        $projects = $this->getFilteredProjects();
        $projectIds = $projects->pluck('id')->toArray();

        // PRE-LOAD ALL DATA IN BATCH (eliminates N+1 queries)
        $this->batchLoadAllLeafTasks($projectIds);
        $preloadedProgress = $this->getCachedBatchProgress($projectIds);

        // Calculate progress using pre-loaded data (no additional queries!)
        foreach ($projects as $project) {
            $project->calculated_progress = $this->calculateProjectProgress($project->id, $preloadedProgress);
        }

        // Get locations with coordinates for the map
        $locationsWithCoords = Location::select('id', 'latitude', 'longitude')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        // Selected project details
        $selectedProject = $this->selectedProjectId
            ? Project::with(['location', 'office.parent', 'partner', 'users'])->find($this->selectedProjectId)
            : null;

        if ($selectedProject) {
            // Use the same pre-loaded progress data for consistency
            $selectedProject->calculated_progress = $this->calculateProjectProgress(
                $selectedProject->id,
                $preloadedProgress
            );
        }

        // Summary stats (all calculated from already-loaded data)
        $totalProjects = $projects->count();
        $activeProjects = $projects->where('status', 'active')->count();
        $completedProjects = $projects->where('status', 'completed')->count();
        $avgProgress = $projects->avg('calculated_progress');

        // Prepare map data for JavaScript
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
            'avgProgress' => round($avgProgress ?? 0, 1),
            'mapData' => $mapData,
        ];
    }

    public function selectProject(?int $projectId): void
    {
        $this->selectedProjectId = $projectId;
    }

    public function updatedSelectedKodimId(): void
    {
        $this->clearCaches();
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoramilId(): void
    {
        $this->clearCaches();
        $this->selectedProjectId = null;
    }

    public function updatedStatusFilter(): void
    {
        $this->clearCaches();
        $this->selectedProjectId = null;
    }
}; ?>

<div class="flex h-full w-full flex-col" x-data="mapController()">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=IBM+Plex+Mono:wght@400;500&display=swap');

        .map-page {
            font-family: 'DM Sans', sans-serif;
        }

        .mono {
            font-family: 'IBM Plex Mono', monospace;
        }

        /* Leaflet light theme customization */
        .leaflet-container {
            background: #f8fafc !important;
            font-family: 'DM Sans', sans-serif;
        }

        .leaflet-control-zoom {
            border: none !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06) !important;
            border-radius: 8px !important;
            overflow: hidden;
        }

        .leaflet-control-zoom a {
            background: #ffffff !important;
            color: #374151 !important;
            border: none !important;
            border-bottom: 1px solid #e5e7eb !important;
            width: 32px !important;
            height: 32px !important;
            line-height: 32px !important;
            font-family: 'IBM Plex Mono', monospace !important;
            font-size: 16px !important;
        }

        .leaflet-control-zoom a:last-child {
            border-bottom: none !important;
        }

        .leaflet-control-zoom a:hover {
            background: #f3f4f6 !important;
            color: #111827 !important;
        }

        /* Custom popup styling - light theme */
        .leaflet-popup-content-wrapper {
            background: #ffffff !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 12px !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
            padding: 0 !important;
        }

        .leaflet-popup-tip {
            background: #ffffff !important;
            border: 1px solid #e5e7eb !important;
            box-shadow: none !important;
        }

        .leaflet-popup-content {
            margin: 0 !important;
            color: #1f2937 !important;
            font-family: 'DM Sans', sans-serif !important;
        }

        .leaflet-popup-close-button {
            color: #6b7280 !important;
            font-size: 20px !important;
            padding: 8px !important;
        }

        .leaflet-popup-close-button:hover {
            color: #111827 !important;
        }

        /* Smooth scrollbar */
        .smooth-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .smooth-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .smooth-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 10px;
        }

        .smooth-scroll::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        /* Project card hover */
        .project-card {
            transition: all 0.15s ease;
        }

        .project-card:hover {
            background: #f9fafb;
            transform: translateX(2px);
        }

        .project-card.active {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
        }

        /* Stat pill */
        .stat-pill {
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
    </style>

    <div class="map-page flex h-full flex-1 flex-col bg-neutral-50">
        {{-- Header --}}
        <div class="border-b border-neutral-200 bg-white px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50">
                        <svg class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-lg font-semibold text-neutral-900">Peta Wilayah</h1>
                        <p class="text-sm text-neutral-500">Distribusi Pembangunan Koperasi Merah Putih</p>
                    </div>
                </div>

                {{-- Stats Pills --}}
                <div class="flex items-center gap-3">
                    <div class="stat-pill flex items-center gap-2 rounded-full border border-neutral-200 px-4 py-2">
                        <span class="text-sm text-neutral-500">Total</span>
                        <span class="mono text-sm font-semibold text-neutral-900">{{ $totalProjects }}</span>
                    </div>
                    <div class="stat-pill flex items-center gap-2 rounded-full border border-green-200 bg-green-50 px-4 py-2">
                        <span class="h-2 w-2 rounded-full bg-green-500"></span>
                        <span class="text-sm text-green-700">{{ $activeProjects }} Active</span>
                    </div>
                    <div class="stat-pill flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-4 py-2">
                        <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                        <span class="text-sm text-blue-700">{{ $completedProjects }} Done</span>
                    </div>
                    <div class="stat-pill flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2">
                        <span class="mono text-sm font-semibold text-amber-700">{{ $avgProgress }}%</span>
                        <span class="text-sm text-amber-600">avg</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Main Content --}}
        <div class="flex flex-1 overflow-hidden">
            {{-- Left Sidebar --}}
            <div class="flex w-80 flex-col border-r border-neutral-200 bg-white">
                {{-- Filters --}}
                <div class="space-y-3 border-b border-neutral-100 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-neutral-400">Filters</span>
                        @if($selectedKodimId || $selectedKoramilId || $statusFilter !== 'all')
                            <button
                                wire:click="$set('selectedKodimId', null); $set('selectedKoramilId', null); $set('statusFilter', 'all')"
                                class="text-xs text-blue-600 hover:text-blue-700"
                            >
                                Reset
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <select
                                wire:model.live="selectedKodimId"
                                class="w-full rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500"
                            >
                                <option value="">Seluruh Kodim</option>
                                @foreach($kodims as $kodim)
                                    <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <select
                                wire:model.live="selectedKoramilId"
                                class="w-full rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
                                @if(!$selectedKodimId) disabled @endif
                            >
                                <option value="">Seluruh Koramil</option>
                                @foreach($koramils as $koramil)
                                    <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <select
                            wire:model.live="statusFilter"
                            class="w-full rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="all">Semua Status</option>
                            <option value="planning">Planning</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="on_hold">On Hold</option>
                        </select>
                    </div>
                </div>

                {{-- Project List --}}
                <div class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
                        <span class="text-sm font-medium text-neutral-700">Projects</span>
                        <span class="mono rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ $projects->count() }}</span>
                    </div>

                    <div class="smooth-scroll flex-1 overflow-y-auto">
                        @forelse($projects as $project)
                            <div
                                wire:key="project-{{ $project->id }}"
                                wire:click="selectProject({{ $project->id }})"
                                class="project-card cursor-pointer border-b border-neutral-50 px-4 py-3 {{ $selectedProjectId === $project->id ? 'active' : '' }}"
                                @click="focusProject({{ $project->id }}, {{ $project->location->latitude ?? 'null' }}, {{ $project->location->longitude ?? 'null' }})"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-neutral-900">{{ $project->name }}</p>
                                        <p class="mt-0.5 flex items-center gap-1 text-xs text-neutral-500">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            </svg>
                                            {{ $project->location->village_name ?? 'No location' }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                            @if($project->status === 'active') bg-green-100 text-green-700
                                            @elseif($project->status === 'completed') bg-blue-100 text-blue-700
                                            @elseif($project->status === 'planning') bg-neutral-100 text-neutral-600
                                            @else bg-amber-100 text-amber-700
                                            @endif
                                        ">
                                            {{ ucfirst($project->status) }}
                                        </span>
                                        <span class="mono text-xs text-neutral-400">{{ $project->calculated_progress }}%</span>
                                    </div>
                                </div>

                                {{-- Progress bar --}}
                                <div class="mt-2 h-1 overflow-hidden rounded-full bg-neutral-100">
                                    <div
                                        class="h-full rounded-full transition-all duration-300
                                            @if($project->calculated_progress >= 100) bg-blue-500
                                            @elseif($project->calculated_progress >= 75) bg-green-500
                                            @elseif($project->calculated_progress >= 50) bg-amber-500
                                            @else bg-neutral-300
                                            @endif
                                        "
                                        style="width: {{ min($project->calculated_progress, 100) }}%"
                                    ></div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center p-8 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-neutral-100">
                                    <svg class="h-6 w-6 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                </div>
                                <p class="mt-3 text-sm font-medium text-neutral-600">Tidak Ditemukan Project</p>
                                <p class="text-xs text-neutral-400">Coba Ubah Filter</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Map Area --}}
            <div class="relative flex-1 overflow-hidden" wire:ignore>
                <div id="map" class="h-[calc(100vh-140px)] w-full"></div>

                {{-- Coordinates --}}
                <div class="absolute bottom-4 left-4 z-[1000] rounded-lg border border-neutral-200 bg-white/95 px-3 py-2 shadow-sm backdrop-blur-sm">
                    <div class="mono flex items-center gap-3 text-xs">
                        <span class="text-neutral-400">LAT <span x-text="cursorLat" class="font-medium text-neutral-700">-7.5500</span></span>
                        <span class="text-neutral-300">|</span>
                        <span class="text-neutral-400">LNG <span x-text="cursorLng" class="font-medium text-neutral-700">110.8243</span></span>
                    </div>
                </div>

                {{-- Legend --}}
                <div class="absolute bottom-4 right-4 z-[1000] rounded-lg border border-neutral-200 bg-white/95 p-3 shadow-sm backdrop-blur-sm">
                    <div class="mb-2 text-xs font-medium text-neutral-500">Status</div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                            <span class="text-xs text-neutral-600">Active</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                            <span class="text-xs text-neutral-600">Completed</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-neutral-400"></span>
                            <span class="text-xs text-neutral-600">Planning</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                            <span class="text-xs text-neutral-600">On Hold</span>
                        </div>
                    </div>
                </div>

                {{-- No coordinates notice --}}
                @if($locationsWithCoords->isEmpty())
                    <div class="absolute inset-0 z-[1000] flex items-center justify-center bg-white/90 backdrop-blur-sm">
                        <div class="max-w-sm rounded-2xl border border-neutral-200 bg-white p-8 text-center shadow-lg">
                            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                                <svg class="h-7 w-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-neutral-900">No Coordinates Available</h3>
                            <p class="mt-2 text-sm text-neutral-500">Add latitude and longitude to locations in the admin panel to display them on the map.</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right Sidebar - Project Details --}}
            @if($selectedProject)
                <div class="w-80 border-l border-neutral-200 bg-white">
                    <div class="flex h-full flex-col">
                        {{-- Header --}}
                        <div class="border-b border-neutral-100 p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($selectedProject->status === 'active') bg-green-100 text-green-700
                                        @elseif($selectedProject->status === 'completed') bg-blue-100 text-blue-700
                                        @elseif($selectedProject->status === 'planning') bg-neutral-100 text-neutral-600
                                        @else bg-amber-100 text-amber-700
                                        @endif
                                    ">
                                        {{ ucfirst($selectedProject->status) }}
                                    </span>
                                    <h2 class="mt-2 text-base font-semibold text-neutral-900">{{ $selectedProject->name }}</h2>
                                </div>
                                <button
                                    wire:click="selectProject(null)"
                                    class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Progress --}}
                        <div class="border-b border-neutral-100 p-6">
                            <div class="flex items-center justify-center">
                                <div class="relative h-28 w-28">
                                    <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                                        <circle cx="50" cy="50" r="42" fill="none" stroke="#f3f4f6" stroke-width="8" />
                                        <circle
                                            cx="50" cy="50" r="42" fill="none"
                                            stroke="@if($selectedProject->calculated_progress >= 100) #3b82f6 @elseif($selectedProject->calculated_progress >= 75) #22c55e @elseif($selectedProject->calculated_progress >= 50) #f59e0b @else #9ca3af @endif"
                                            stroke-width="8"
                                            stroke-linecap="round"
                                            stroke-dasharray="{{ $selectedProject->calculated_progress * 2.64 }} 264"
                                            class="transition-all duration-700"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="mono text-2xl font-bold text-neutral-900">{{ $selectedProject->calculated_progress }}%</span>
                                        <span class="text-xs text-neutral-500">Progress</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Details --}}
                        <div class="smooth-scroll flex-1 overflow-y-auto p-4">
                            <div class="space-y-3">
                                <div class="rounded-xl bg-neutral-50 p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-neutral-400">Location</p>
                                    <p class="mt-1 text-sm font-medium text-neutral-900">{{ $selectedProject->location->village_name ?? '-' }}</p>
                                    <p class="text-xs text-neutral-500">{{ $selectedProject->location->district_name ?? '' }}, {{ $selectedProject->location->city_name ?? '' }}</p>
                                    @if($selectedProject->location->latitude && $selectedProject->location->longitude)
                                        <p class="mono mt-1.5 text-xs text-neutral-400">{{ $selectedProject->location->latitude }}, {{ $selectedProject->location->longitude }}</p>
                                    @endif
                                </div>

                                <div class="rounded-xl bg-neutral-50 p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-neutral-400">Office</p>
                                    <p class="mt-1 text-sm font-medium text-neutral-900">{{ $selectedProject->office->name ?? '-' }}</p>
                                    @if($selectedProject->office?->parent)
                                        <p class="text-xs text-neutral-500">{{ $selectedProject->office->parent->name }}</p>
                                    @endif
                                </div>

                                <div class="rounded-xl bg-neutral-50 p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-neutral-400">Partner</p>
                                    <p class="mt-1 text-sm font-medium text-neutral-900">{{ $selectedProject->partner->name ?? '-' }}</p>
                                </div>

                                <div class="rounded-xl bg-neutral-50 p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-neutral-400">Timeline</p>
                                    <div class="mt-1 flex items-center justify-between">
                                        <div>
                                            <p class="text-xs text-neutral-500">Start</p>
                                            <p class="text-sm font-medium text-neutral-900">{{ $selectedProject->start_date?->format('M d, Y') ?? '-' }}</p>
                                        </div>
                                        <svg class="h-4 w-4 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                        </svg>
                                        <div class="text-right">
                                            <p class="text-xs text-neutral-500">End</p>
                                            <p class="text-sm font-medium text-neutral-900">{{ $selectedProject->end_date?->format('M d, Y') ?? '-' }}</p>
                                        </div>
                                    </div>
                                </div>

                                @if($selectedProject->users->isNotEmpty())
                                    <div class="rounded-xl bg-neutral-50 p-3">
                                        <p class="text-xs font-medium uppercase tracking-wider text-neutral-400">Team</p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach($selectedProject->users as $user)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-1 text-xs text-neutral-700 shadow-sm">
                                                    <span class="flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 text-[10px] font-medium text-blue-700">
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

                        {{-- Action --}}
                        <div class="border-t border-neutral-100 p-4">
                            <a
                                href="{{ route('progress.index', ['project' => $selectedProject->id]) }}"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700"
                                wire:navigate
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                View Progress
                            </a>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Map Data Bridge --}}
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
                        this.$watch('currentProjects', (newProjects) => {
                            if (this.mapInitialized && this.map) {
                                this.updateMarkers(newProjects);
                            }
                        });

                        window.addEventListener('map-data-updated', (e) => {
                            this.currentProjects = e.detail.projects;
                            if (this.mapInitialized && this.map) {
                                this.updateMarkers(e.detail.projects);
                            }
                        });

                        // Leaflet is bundled via Vite, init map directly
                        this.$nextTick(() => this.initMap());
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

                        if (mapContainer._leaflet_id) {
                            console.log('Map already initialized');
                            return;
                        }

                        // Initialize with light theme - OpenStreetMap standard
                        this.map = L.map('map', {
                            zoomControl: true,
                            attributionControl: false,
                            zoomAnimation: true,
                            markerZoomAnimation: false,
                            fadeAnimation: true
                        }).setView([-7.5500, 110.8243], 10);

                        // Light map tiles - CartoDB Positron (clean, light theme)
                        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                            maxZoom: 19,
                        }).addTo(this.map);

                        // Force recalculate size
                        setTimeout(() => {
                            if (this.map) {
                                this.map.invalidateSize();
                            }
                        }, 100);

                        // Track cursor
                        this.map.on('mousemove', (e) => {
                            this.cursorLat = e.latlng.lat.toFixed(4);
                            this.cursorLng = e.latlng.lng.toFixed(4);
                        });

                        this.mapInitialized = true;
                        this.updateMarkers(this.currentProjects);

                        console.log('Map initialized');
                    },

                    updateMarkers(projects) {
                        if (!this.map) return;

                        // Wait if map is currently zooming to prevent animation errors
                        if (this.map._animatingZoom) {
                            this.map.once('zoomend', () => this.updateMarkers(projects));
                            return;
                        }

                        // Clear existing
                        this.markers.forEach(m => {
                            try {
                                if (this.map && this.map.hasLayer(m.marker)) {
                                    this.map.removeLayer(m.marker);
                                }
                            } catch (e) {
                                // Ignore errors during marker removal
                            }
                        });
                        this.markers = [];

                        // Add markers
                        projects.forEach(project => {
                            if (project.lat && project.lng) {
                                const color = this.getStatusColor(project.status);
                                const marker = L.circleMarker([project.lat, project.lng], {
                                    radius: 8,
                                    fillColor: color,
                                    color: '#ffffff',
                                    weight: 2,
                                    opacity: 1,
                                    fillOpacity: 0.9
                                }).addTo(this.map);

                                const popupContent = `
                                    <div class="p-3 min-w-[220px]">
                                        <p class="font-semibold text-neutral-900 text-sm">${project.name}</p>
                                        <p class="text-neutral-500 text-xs mt-0.5">${project.village}, ${project.district}</p>
                                        <div class="flex items-center justify-between mt-3">
                                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background: ${color}20; color: ${color};">
                                                ${project.status.charAt(0).toUpperCase() + project.status.slice(1)}
                                            </span>
                                            <span class="text-xs text-neutral-600 font-mono font-medium">${project.progress}%</span>
                                        </div>
                                        <div class="mt-2 h-1.5 bg-neutral-100 rounded-full overflow-hidden">
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
                            'planning': '#9ca3af',
                            'on_hold': '#f59e0b'
                        };
                        return colors[status] || '#9ca3af';
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
