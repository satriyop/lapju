<?php

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?string $reportDate = null;

    // Cascading office filters
    public ?int $selectedKodamId = null;

    public ?int $selectedKoremId = null;

    public ?int $selectedKodimId = null;

    // Lock filters for Managers
    public bool $filtersLocked = false;

    // Photo selection state (session only)
    public array $selectedBeforePhotos = []; // [project_id => photo_id]

    public array $selectedCurrentPhotos = []; // [project_id => photo_id]

    public ?int $photoModalProjectId = null;

    public string $photoModalType = ''; // 'before' or 'current'

    // Cache
    private ?object $cachedOfficeLevels = null;

    public function mount(): void
    {
        $this->reportDate = now()->format('Y-m-d');

        $currentUser = auth()->user();

        // Reporters don't use office filters
        if ($currentUser->hasRole('Reporter')) {
            return;
        }

        // Managers at Kodim level - set defaults and lock
        if ($currentUser->office_id) {
            $userOffice = Office::with('level', 'parent.parent')->find($currentUser->office_id);

            if ($userOffice && $userOffice->level->level === 3) {
                $this->selectedKodimId = $userOffice->id;

                if ($userOffice->parent) {
                    $this->selectedKoremId = $userOffice->parent->id;

                    if ($userOffice->parent->parent) {
                        $this->selectedKodamId = $userOffice->parent->parent->id;
                    }
                }

                $this->filtersLocked = true;
            }
        }

        // Default filters
        if (! $this->selectedKodamId) {
            $kodamIV = Office::whereHas('level', fn ($q) => $q->where('level', 1))
                ->where('name', 'like', '%Kodam IV%')
                ->first();

            $korem074 = Office::whereHas('level', fn ($q) => $q->where('level', 2))
                ->where('name', 'like', '%074%')
                ->first();

            if ($kodamIV) {
                $this->selectedKodamId = $kodamIV->id;
            }

            if ($korem074) {
                $this->selectedKoremId = $korem074->id;
            }
        }
    }

    public function updatedSelectedKodamId(): void
    {
        if ($this->filtersLocked) {
            return;
        }

        $this->selectedKoremId = null;
        $this->selectedKodimId = null;
    }

    public function updatedSelectedKoremId(): void
    {
        if ($this->filtersLocked) {
            return;
        }

        $this->selectedKodimId = null;
    }

    public function openPhotoModal(int $projectId, string $type): void
    {
        $this->photoModalProjectId = $projectId;
        $this->photoModalType = $type;
    }

    public function selectPhoto(int $projectId, string $type, int $photoId): void
    {
        if ($type === 'before') {
            $this->selectedBeforePhotos[$projectId] = $photoId;
        } else {
            $this->selectedCurrentPhotos[$projectId] = $photoId;
        }
        $this->photoModalProjectId = null;
    }

    public function closePhotoModal(): void
    {
        $this->photoModalProjectId = null;
        $this->photoModalType = '';
    }

    private function getOfficeLevels(): object
    {
        if ($this->cachedOfficeLevels === null) {
            $levels = OfficeLevel::all();

            $this->cachedOfficeLevels = (object) [
                'kodam' => $levels->firstWhere('level', 1),
                'korem' => $levels->firstWhere('level', 2),
                'kodim' => $levels->firstWhere('level', 3),
                'koramil' => $levels->firstWhere('level', 4),
            ];
        }

        return $this->cachedOfficeLevels;
    }

    /**
     * Get report data - projects with progress info
     */
    private function getReportData(): array
    {
        $currentUser = Auth::user();
        $reportDate = Carbon::parse($this->reportDate);

        // Build project query based on filters
        $query = Project::with([
            'location',
            'office.parent',
            'tasks' => fn ($q) => $q->whereNull('parent_id'),
        ]);

        // Apply office filters
        if ($this->selectedKodimId) {
            $koramils = Office::where('parent_id', $this->selectedKodimId)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKoremId) {
            $kodims = Office::where('parent_id', $this->selectedKoremId)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKodamId) {
            $korems = Office::where('parent_id', $this->selectedKodamId)->pluck('id');
            $kodims = Office::whereIn('parent_id', $korems)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        }

        // For reporters, only show their projects
        if ($currentUser->hasRole('Reporter')) {
            $query = $currentUser->projects()->with([
                'location',
                'office.parent',
                'tasks' => fn ($q) => $q->whereNull('parent_id'),
            ]);
        }

        $projects = $query->orderBy('name')->get();

        // Prepare report data
        $reportData = [];
        $projectIds = $projects->pluck('id')->toArray();

        if (empty($projectIds)) {
            return [];
        }

        // Get all leaf task IDs for these projects
        $leafTasksByProject = Task::whereIn('project_id', $projectIds)
            ->whereRaw('_lft = _rgt - 1')
            ->get()
            ->groupBy('project_id');

        // Get latest progress for each project (total to date) and before report date (for "yesterday")
        $latestProgressByProject = [];
        $beforeReportProgressByProject = [];
        foreach ($projectIds as $projectId) {
            // Latest progress up to and including report date (for TOTAL column)
            $latestProgress = TaskProgress::where('project_id', $projectId)
                ->where('progress_date', '<=', $reportDate->format('Y-m-d'))
                ->orderBy('progress_date', 'desc')
                ->get()
                ->unique('task_id');
            $latestProgressByProject[$projectId] = $latestProgress;

            // Latest progress BEFORE report date (for KMRN/Yesterday column)
            $beforeProgress = TaskProgress::where('project_id', $projectId)
                ->where('progress_date', '<', $reportDate->format('Y-m-d'))
                ->orderBy('progress_date', 'desc')
                ->get()
                ->unique('task_id');
            $beforeReportProgressByProject[$projectId] = $beforeProgress;
        }

        // Get photos for each project with root task relationship
        $photosByProject = ProgressPhoto::whereIn('project_id', $projectIds)
            ->with('rootTask')
            ->orderBy('photo_date', 'desc')
            ->get()
            ->groupBy('project_id');

        foreach ($projects as $index => $project) {
            $leafTasks = $leafTasksByProject[$project->id] ?? collect();
            $leafTaskIds = $leafTasks->pluck('id')->toArray();
            $totalWeight = $leafTasks->sum('weight') ?: 1;

            // Calculate total progress to date (TOTAL column)
            $latestProgress = $latestProgressByProject[$project->id] ?? collect();
            $totalProgress = 0;
            foreach ($latestProgress as $progress) {
                if (in_array($progress->task_id, $leafTaskIds)) {
                    $task = $leafTasks->firstWhere('id', $progress->task_id);
                    if ($task) {
                        $totalProgress += ($progress->percentage * $task->weight) / $totalWeight;
                    }
                }
            }

            // Calculate yesterday's progress (KMRN column) - latest progress BEFORE report date
            $beforeReportProgress = $beforeReportProgressByProject[$project->id] ?? collect();
            $yesterdayTotal = 0;
            foreach ($beforeReportProgress as $progress) {
                if (in_array($progress->task_id, $leafTaskIds)) {
                    $task = $leafTasks->firstWhere('id', $progress->task_id);
                    if ($task) {
                        $yesterdayTotal += ($progress->percentage * $task->weight) / $totalWeight;
                    }
                }
            }

            // Calculate today's change (HR INI column) - difference between total and yesterday
            $todayChange = $totalProgress - $yesterdayTotal;

            // Get photos - filter by report date
            $projectPhotos = $photosByProject[$project->id] ?? collect();

            // SEBELUM: All photos from latest date BEFORE report date
            $beforePhotos = $projectPhotos
                ->filter(fn ($p) => $p->photo_date->format('Y-m-d') < $reportDate->format('Y-m-d'))
                ->groupBy(fn ($p) => $p->photo_date->format('Y-m-d'))
                ->sortKeysDesc()
                ->first() ?? collect();

            // SAAT INI: All photos exactly ON report date
            $currentPhotos = $projectPhotos
                ->filter(fn ($p) => $p->photo_date->format('Y-m-d') === $reportDate->format('Y-m-d'));

            // Get latest activity description from most recent progress entry
            $latestActivity = $latestProgress->first()?->notes ?? '-';

            $reportData[] = [
                'index' => $index + 1,
                'project' => $project,
                'satuan' => $project->office?->parent?->name ?? '-',
                'address' => $this->formatAddress($project),
                'coordinates' => $this->formatCoordinates($project),
                'yesterday_progress' => $yesterdayTotal,
                'today_change' => $todayChange,
                'total_progress' => $totalProgress,
                'before_photos' => $beforePhotos,
                'current_photos' => $currentPhotos,
                'activity' => $latestActivity,
            ];
        }

        return $reportData;
    }

    private function formatAddress(Project $project): string
    {
        $parts = [];

        if ($project->location?->village_name) {
            $parts[] = 'Ds '.$project->location->village_name;
        }

        if ($project->location?->district_name) {
            $parts[] = 'Kec. '.$project->location->district_name;
        }

        if ($project->location?->city_name) {
            $parts[] = $project->location->city_name;
        }

        return implode(' ', $parts) ?: '-';
    }

    private function formatCoordinates(Project $project): string
    {
        if ($project->location?->latitude && $project->location?->longitude) {
            return $project->location->latitude.', '.$project->location->longitude;
        }

        return '';
    }

    /**
     * Get header info based on selected filters
     */
    private function getHeaderInfo(): array
    {
        $kodamName = 'KOMANDO DAERAH MILITER IV/DIPONEGORO';
        $koremName = 'KOMANDO RESOR MILITER 074/WARASTRATAMA';
        $reportTitle = 'DATA PENYERAPAN DAN PEMBANGUNAN FISIK SERTA PROGRES KDKMP';
        $reportSubtitle = 'KOREM 074/WARASTRATAMA TAHUN '.now()->year;

        if ($this->selectedKodamId) {
            $kodam = Office::find($this->selectedKodamId);
            if ($kodam) {
                $kodamName = strtoupper($kodam->name);
            }
        }

        if ($this->selectedKoremId) {
            $korem = Office::find($this->selectedKoremId);
            if ($korem) {
                $koremName = strtoupper($korem->name);
                $reportSubtitle = strtoupper($korem->name).' TAHUN '.now()->year;
            }
        }

        return [
            'kodam' => $kodamName,
            'korem' => $koremName,
            'title' => $reportTitle,
            'subtitle' => $reportSubtitle,
        ];
    }

    public function with(): array
    {
        $levels = $this->getOfficeLevels();

        $kodams = $levels->kodam
            ? Office::select('id', 'name')->where('level_id', $levels->kodam->id)->orderBy('name')->get()
            : collect();

        $korems = $levels->korem && $this->selectedKodamId
            ? Office::select('id', 'name')->where('level_id', $levels->korem->id)->where('parent_id', $this->selectedKodamId)->orderBy('name')->get()
            : collect();

        $kodims = $levels->kodim && $this->selectedKoremId
            ? Office::select('id', 'name')->where('level_id', $levels->kodim->id)->where('parent_id', $this->selectedKoremId)->orderBy('name')->get()
            : collect();

        return [
            'kodams' => $kodams,
            'korems' => $korems,
            'kodims' => $kodims,
            'reportData' => $this->getReportData(),
            'headerInfo' => $this->getHeaderInfo(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    {{-- Page Header --}}
    <div class="flex items-center justify-between">
        {{-- <div>
            <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __('View project progress and documentation report') }}
            </p>
        </div> --}}
        {{-- @if(count($reportData) > 0)
            <flux:button wire:click="exportExcel" variant="primary" icon="arrow-down-tray">
                Export Excel
            </flux:button>
        @endif --}}
    </div>

    {{-- Report Header Info --}}
    <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-start justify-between">
            <div class="flex-1 text-center">
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $headerInfo['kodam'] }}</p>
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $headerInfo['korem'] }}</p>
                <h2 class="mt-3 text-lg font-bold text-neutral-900 dark:text-neutral-100">{{ $headerInfo['title'] }}</h2>
                <p class="text-sm font-semibold text-neutral-600 dark:text-neutral-400">{{ $headerInfo['subtitle'] }}</p>
            </div>
            @if(count($reportData) > 0)
                <flux:button wire:click="exportExcel" variant="primary" icon="arrow-down-tray">
                    Export Excel
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Filters --}}
    @if(!auth()->user()->hasRole('Reporter'))
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Kodam') }}
                </label>
                <flux:select wire:model.live="selectedKodamId" :disabled="$filtersLocked">
                    <option value="">{{ __('All Kodam') }}</option>
                    @foreach($kodams as $kodam)
                        <option value="{{ $kodam->id }}">{{ $kodam->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Korem') }}
                </label>
                <flux:select wire:model.live="selectedKoremId" :disabled="$filtersLocked || !$selectedKodamId">
                    <option value="">{{ $selectedKodamId ? __('Select Korem...') : __('Select Kodam first') }}</option>
                    @foreach($korems as $korem)
                        <option value="{{ $korem->id }}">{{ $korem->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Kodim') }}
                </label>
                <flux:select wire:model.live="selectedKodimId" :disabled="$filtersLocked || !$selectedKoremId">
                    <option value="">{{ $selectedKoremId ? __('All Kodim') : __('Select Korem first') }}</option>
                    @foreach($kodims as $kodim)
                        <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Report Date') }}
                </label>
                <flux:input type="date" wire:model.live="reportDate" />
            </div>
        </div>
    @else
        {{-- Reporter: Only date filter --}}
        <div class="max-w-xs">
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('Report Date') }}
            </label>
            <flux:input type="date" wire:model.live="reportDate" />
        </div>
    @endif

    {{-- Report Table --}}
    @if(count($reportData) > 0)
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full">
                <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <th rowspan="2" class="px-3 py-3 text-center text-sm font-semibold text-neutral-900 dark:text-neutral-100">NO URUT</th>
                        <th rowspan="2" class="px-3 py-3 text-center text-sm font-semibold text-neutral-900 dark:text-neutral-100">BAG</th>
                        <th rowspan="2" class="px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">SATUAN</th>
                        <th rowspan="2" class="min-w-[200px] px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">ALAMAT DAN KOORDINAT</th>
                        <th colspan="3" class="border-b border-neutral-200 px-4 py-2 text-center text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:text-neutral-100">PROGRES (PROSENTASE)</th>
                        <th colspan="2" class="border-b border-neutral-200 px-4 py-2 text-center text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:text-neutral-100">DOKUMENTASI</th>
                        <th rowspan="2" class="min-w-[150px] px-4 py-3 text-left text-sm font-semibold text-neutral-900 dark:text-neutral-100">KETERANGAN</th>
                    </tr>
                    <tr class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <th class="px-3 py-2 text-center text-xs font-medium text-neutral-600 dark:text-neutral-400">KMRN</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-neutral-600 dark:text-neutral-400">HR INI</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-neutral-600 dark:text-neutral-400">TOTAL</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-neutral-600 dark:text-neutral-400">SEBELUM</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-neutral-600 dark:text-neutral-400">SAAT INI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @php
                        $currentKodim = null;
                        $kodimRowNumber = 0;
                    @endphp

                    @foreach($reportData as $data)
                        @php
                            $projectKodim = $data['project']->office?->parent?->name ?? 'Unknown';
                            $isNewKodim = $currentKodim !== $projectKodim;
                            if ($isNewKodim) {
                                $currentKodim = $projectKodim;
                                $kodimRowNumber = 0;
                            }
                            $kodimRowNumber++;
                        @endphp

                        {{-- Kodim Section Header --}}
                        @if($isNewKodim)
                            <tr class="bg-neutral-100 dark:bg-neutral-800">
                                <td colspan="10" class="px-4 py-2">
                                    <span class="text-sm font-bold text-neutral-900 dark:text-neutral-100">
                                        {{ $currentKodim }}
                                    </span>
                                </td>
                            </tr>
                        @endif

                        {{-- Data Row --}}
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-3 py-3 text-center text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $data['index'] }}
                            </td>
                            <td class="px-3 py-3 text-center text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $kodimRowNumber }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $data['project']->office?->name ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="text-neutral-900 dark:text-neutral-100">{{ $data['address'] }}</div>
                                @if($data['coordinates'])
                                    <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $data['coordinates'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center text-sm text-neutral-700 dark:text-neutral-300">
                                {{ number_format($data['yesterday_progress'], 2) }}%
                            </td>
                            <td class="px-3 py-3 text-center text-sm">
                                @if($data['today_change'] > 0)
                                    <span class="text-green-600 dark:text-green-400">+{{ number_format($data['today_change'], 2) }}%</span>
                                @elseif($data['today_change'] < 0)
                                    <span class="text-red-600 dark:text-red-400">{{ number_format($data['today_change'], 2) }}%</span>
                                @else
                                    <span class="text-neutral-500 dark:text-neutral-400">0.00%</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                @php
                                    $progressClass = $data['total_progress'] >= 100 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' :
                                        ($data['total_progress'] >= 75 ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' :
                                        ($data['total_progress'] >= 50 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300' :
                                        ($data['total_progress'] >= 25 ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300')));
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $progressClass }}">
                                    {{ number_format($data['total_progress'], 2) }}%
                                </span>
                            </td>
                            {{-- SEBELUM Photo Cell --}}
                            <td class="px-3 py-3 text-center">
                                @if($data['before_photos']->isNotEmpty())
                                    @php
                                        $selectedId = $selectedBeforePhotos[$data['project']->id] ?? null;
                                        $displayPhoto = $selectedId
                                            ? $data['before_photos']->firstWhere('id', $selectedId)
                                            : $data['before_photos']->first();
                                        $photoCount = $data['before_photos']->count();
                                    @endphp
                                    <button
                                        wire:click="openPhotoModal({{ $data['project']->id }}, 'before')"
                                        class="relative mx-auto block cursor-pointer"
                                        title="Click to select photo"
                                    >
                                        <div class="h-14 w-18 overflow-hidden rounded border border-neutral-200 dark:border-neutral-600">
                                            <img
                                                src="{{ Storage::url($displayPhoto->file_path) }}"
                                                alt="Before"
                                                class="h-full w-full object-cover"
                                                loading="lazy"
                                            >
                                        </div>
                                        @if($photoCount > 1)
                                            <span class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-blue-500 text-xs font-semibold text-white shadow">
                                                {{ $photoCount }}
                                            </span>
                                        @endif
                                    </button>
                                @else
                                    <div class="mx-auto flex h-14 w-18 items-center justify-center rounded border border-dashed border-neutral-300 bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800">
                                        <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            {{-- SAAT INI Photo Cell --}}
                            <td class="px-3 py-3 text-center">
                                @if($data['current_photos']->isNotEmpty())
                                    @php
                                        $selectedId = $selectedCurrentPhotos[$data['project']->id] ?? null;
                                        $displayPhoto = $selectedId
                                            ? $data['current_photos']->firstWhere('id', $selectedId)
                                            : $data['current_photos']->first();
                                        $photoCount = $data['current_photos']->count();
                                    @endphp
                                    <button
                                        wire:click="openPhotoModal({{ $data['project']->id }}, 'current')"
                                        class="relative mx-auto block cursor-pointer"
                                        title="Click to select photo"
                                    >
                                        <div class="h-14 w-18 overflow-hidden rounded border border-neutral-200 dark:border-neutral-600">
                                            <img
                                                src="{{ Storage::url($displayPhoto->file_path) }}"
                                                alt="Current"
                                                class="h-full w-full object-cover"
                                                loading="lazy"
                                            >
                                        </div>
                                        @if($photoCount > 1)
                                            <span class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-blue-500 text-xs font-semibold text-white shadow">
                                                {{ $photoCount }}
                                            </span>
                                        @endif
                                    </button>
                                @else
                                    <div class="mx-auto flex h-14 w-18 items-center justify-center rounded border border-dashed border-neutral-300 bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800">
                                        <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                {{ Str::limit($data['activity'], 80) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Legend --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Keterangan Progres</h3>
            <div class="mt-3 flex flex-wrap gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-green-100 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-300">100%</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">Selesai</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-blue-100 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-300">75%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">75-99%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-yellow-100 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300">50%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">50-74%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-orange-100 text-xs font-medium text-orange-800 dark:bg-orange-900 dark:text-orange-300">25%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">25-49%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-300">0%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">0-24%</span>
                </div>
            </div>
        </div>
    @else
        {{-- Empty State --}}
        <div class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-300 bg-neutral-50 py-12 dark:border-neutral-700 dark:bg-neutral-800/50">
            <svg class="h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-4 text-lg font-medium text-neutral-600 dark:text-neutral-400">
                {{ __('No Data Available') }}
            </p>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-500">
                {{ __('Select filters above to view project progress report') }}
            </p>
        </div>
    @endif

    {{-- Photo Selection Modal --}}
    @if($photoModalProjectId)
        <flux:modal wire:model.live="photoModalProjectId" class="max-w-2xl">
            @php
                $modalData = collect($reportData)->firstWhere(fn($d) => $d['project']->id === $photoModalProjectId);
                $photos = $photoModalType === 'before'
                    ? ($modalData['before_photos'] ?? collect())
                    : ($modalData['current_photos'] ?? collect());
            @endphp

            <div class="space-y-4">
                <flux:heading size="lg">
                    Pilih Foto ({{ $photoModalType === 'before' ? 'SEBELUM' : 'SAAT INI' }})
                </flux:heading>

                @if($modalData)
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        {{ $modalData['project']->name ?? 'Project' }}
                    </p>
                @endif

                @if($photos->isNotEmpty())
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach($photos as $photo)
                            <button
                                wire:click="selectPhoto({{ $photoModalProjectId }}, '{{ $photoModalType }}', {{ $photo->id }})"
                                class="group relative overflow-hidden rounded-lg border-2 border-transparent transition hover:border-blue-500 focus:border-blue-500 focus:outline-none"
                            >
                                <div class="aspect-square overflow-hidden bg-neutral-100 dark:bg-neutral-800">
                                    <img
                                        src="{{ Storage::url($photo->file_path) }}"
                                        alt="Photo"
                                        class="h-full w-full object-cover transition group-hover:scale-105"
                                        loading="lazy"
                                    >
                                </div>
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2">
                                    <p class="truncate text-xs font-medium text-white">
                                        {{ $photo->rootTask->name ?? 'No Task' }}
                                    </p>
                                    <p class="text-xs text-neutral-300">
                                        {{ $photo->photo_date->format('d M Y') }}
                                    </p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center text-neutral-500">
                        Tidak ada foto tersedia
                    </div>
                @endif

                <div class="flex justify-end pt-4">
                    <flux:button wire:click="closePhotoModal" variant="ghost">
                        Tutup
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
