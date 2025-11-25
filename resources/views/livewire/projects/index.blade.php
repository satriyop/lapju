<?php

use App\Models\Location;
use App\Models\Office;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;
use Livewire\WithPagination;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public ?int $partnerId = null;

    public ?int $kodimId = null;

    public ?int $koramilId = null;

    public ?int $locationId = null;

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $status = 'planning';

    public bool $showModal = false;

    public bool $isEditing = false;

    public Collection $availableKoramils;

    public Collection $availableLocations;

    public function mount(): void
    {
        $this->availableKoramils = collect();
        $this->availableLocations = collect();
    }

    public function isReporter(): bool
    {
        return auth()->user()->hasRole('Reporter');
    }

    /**
     * Check if current user can manage (edit/delete) a project.
     */
    public function canManageProject(Project $project): bool
    {
        $currentUser = auth()->user();

        // Admins can manage all projects
        if ($currentUser->isAdmin() || $currentUser->hasPermission('*')) {
            return true;
        }

        // Check if user has edit permission
        if (! $currentUser->hasPermission('edit_projects')) {
            return false;
        }

        // Must have office assigned
        if (! $currentUser->office_id || ! $project->office_id) {
            return false;
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        // Managers at Kodim level can manage projects in Koramils under their Kodim
        if ($currentOffice && $currentOffice->level->level === 3) {
            $projectOffice = Office::find($project->office_id);

            return $projectOffice && $projectOffice->parent_id === $currentUser->office_id;
        }

        // Koramil Admins can manage projects in their office or created by users in their office
        if ($currentOffice && $currentOffice->level->level === 4) {
            // Check if project is in their office
            if ($project->office_id === $currentUser->office_id) {
                return true;
            }

            // Check if project was created by a user in their office
            $hasUserInOffice = $project->users()
                ->where('office_id', $currentUser->office_id)
                ->exists();

            return $hasUserInOffice;
        }

        return false;
    }

    /**
     * Calculate actual progress percentage for a project based on latest task progress entries.
     */
    private function calculateActualProgress(int $projectId): float
    {
        // Get the most recent progress date for this project
        $latestDate = TaskProgress::where('project_id', $projectId)
            ->max('progress_date');

        if (! $latestDate) {
            return 0.0;
        }

        // Get average percentage for all tasks on the latest date
        $avgProgress = TaskProgress::where('project_id', $projectId)
            ->where('progress_date', $latestDate)
            ->avg('percentage');

        return round($avgProgress ?? 0, 2);
    }

    public function with(): array
    {
        $currentUser = auth()->user();
        $projectsQuery = Project::query()
            ->with(['partner', 'location', 'office.parent', 'office.level', 'users'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"));

        // Reporters can only see their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            $projectsQuery->whereHas('users', fn ($q) => $q->where('users.id', $currentUser->id));
        }
        // Kodim Admins can only see projects in Koramils under their Kodim
        elseif ($currentUser->hasRole('Kodim Admin') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                // Filter projects to only show those assigned to Koramils under this Kodim
                $projectsQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        }
        // Koramil Admins can see projects in their office or created by users in their office
        elseif ($currentUser->hasRole('Koramil Admin') && $currentUser->office_id) {
            $projectsQuery->where(function ($q) use ($currentUser) {
                // Projects assigned to their office
                $q->where('office_id', $currentUser->office_id)
                    // OR projects created by users in their office
                    ->orWhereHas('users', function ($userQuery) use ($currentUser) {
                        $userQuery->where('office_id', $currentUser->office_id);
                    });
            });
        }

        $projects = $projectsQuery->orderBy('name')->paginate(20);

        // Calculate actual progress for each project if user is a reporter
        if ($currentUser->hasRole('Reporter')) {
            foreach ($projects as $project) {
                $project->actual_progress = $this->calculateActualProgress($project->id);
            }
        }

        $partners = Partner::orderBy('name')->get();

        // Get Kodims
        $kodims = Office::whereHas('level', fn ($q) => $q->where('level', 3))
            ->orderBy('name')
            ->get();

        // Get Koramils filtered by selected Kodim
        $koramils = Office::whereHas('level', fn ($q) => $q->where('level', 4))
            ->when($this->kodimId, fn ($q) => $q->where('parent_id', $this->kodimId))
            ->orderBy('name')
            ->get();

        // Get Locations filtered by selected Koramil's coverage
        $locations = Location::query();
        if ($this->koramilId) {
            $koramil = Office::find($this->koramilId);
            if ($koramil) {
                if ($koramil->coverage_district) {
                    $locations->where('district_name', $koramil->coverage_district);
                } elseif ($koramil->coverage_city) {
                    $locations->where('city_name', $koramil->coverage_city);
                }
            }
        }
        $locations = $locations->orderBy('city_name')->orderBy('village_name')->get();

        return [
            'projects' => $projects,
            'partners' => $partners,
            'kodims' => $kodims,
            'koramils' => $koramils,
            'locations' => $locations,
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'partnerId', 'kodimId', 'koramilId', 'locationId', 'startDate', 'endDate', 'status']);
        $this->availableKoramils = collect();
        $this->availableLocations = collect();
        $this->status = 'planning';
        $this->startDate = Setting::get('project.default_start_date', '2025-11-01');
        $this->endDate = Setting::get('project.default_end_date', '2026-01-31');

        // Default to first partner (PT Agrinas)
        $firstPartner = Partner::orderBy('name')->first();
        if ($firstPartner) {
            $this->partnerId = $firstPartner->id;
        }

        // For reporters, default to their office
        $user = auth()->user();
        if ($user && $user->office) {
            $office = $user->office;

            // If user's office is a Koramil (level 4)
            if ($office->level && $office->level->level == 4) {
                $this->koramilId = $office->id;
                $this->kodimId = $office->parent_id;
            }
            // If user's office is a Kodim (level 3)
            elseif ($office->level && $office->level->level == 3) {
                $this->kodimId = $office->id;
            }
        }

        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->isEditing = true;

        $project = Project::with(['office.parent', 'location'])->findOrFail($id);

        // Check if current user can manage this project
        if (! $this->canManageProject($project)) {
            abort(403, 'You do not have permission to edit this project.');
        }
        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->partnerId = $project->partner_id;
        $this->locationId = $project->location_id;
        $this->startDate = $project->start_date?->format('Y-m-d');
        $this->endDate = $project->end_date?->format('Y-m-d');
        $this->status = $project->status;

        // Load office hierarchy IN THE CORRECT ORDER (Kodim → Koramil → Location)
        if ($project->office) {
            // Set Kodim FIRST (parent)
            if ($project->office->parent_id) {
                $this->kodimId = $project->office->parent_id;

                // Explicitly load filtered Koramils for this Kodim
                $this->availableKoramils = Office::whereHas('level', fn ($q) => $q->where('level', 4))
                    ->where('parent_id', $this->kodimId)
                    ->orderBy('name')
                    ->get();
            }

            // Then set Koramil (child)
            $this->koramilId = $project->office_id;

            // Explicitly load filtered Locations for this Koramil
            $koramil = Office::find($this->koramilId);
            if ($koramil) {
                $locationsQuery = Location::query();
                if ($koramil->coverage_district) {
                    $locationsQuery->where('district_name', $koramil->coverage_district);
                } elseif ($koramil->coverage_city) {
                    $locationsQuery->where('city_name', $koramil->coverage_city);
                }
                $this->availableLocations = $locationsQuery
                    ->orderBy('city_name')
                    ->orderBy('village_name')
                    ->get();
            }
        }

        $this->isEditing = false;
        $this->showModal = true;
    }

    public function updatedKodimId(): void
    {
        // Reporters cannot change office hierarchy
        if ($this->isReporter()) {
            return;
        }

        // Don't reset fields during edit initialization
        if ($this->isEditing) {
            return;
        }

        // Reset Koramil and Location when Kodim changes
        $this->koramilId = null;
        $this->locationId = null;
    }

    public function updatedKoramilId(): void
    {
        // Reporters cannot change office hierarchy
        if ($this->isReporter()) {
            return;
        }

        // Don't reset fields during edit initialization
        if ($this->isEditing) {
            return;
        }

        // Reset Location when Koramil changes
        $this->locationId = null;
    }

    public function updatedLocationId(): void
    {
        // Auto-populate project name when location is selected
        if ($this->locationId) {
            $location = Location::find($this->locationId);
            if ($location) {
                $this->name = 'Koperasi Merah Putih '.$location->village_name;
            }
        }
    }

    public function save(): void
    {
        $user = auth()->user();

        // For reporters and Koramil Admins, ensure office values match their assigned office
        if ($this->isReporter() || $user->hasRole('Koramil Admin')) {
            $office = $user->office;

            if ($office && $office->level && $office->level->level == 4) {
                // Koramil level - enforce their koramil
                $this->koramilId = $office->id;
                $this->kodimId = $office->parent_id;
            } elseif ($office && $office->level && $office->level->level == 3) {
                // Kodim level - enforce their kodim
                $this->kodimId = $office->id;
            }
        }

        // Enforce date constraints for reporters
        if ($this->isReporter()) {

            // Enforce date constraints for reporters
            $minStartDate = Setting::get('project.default_start_date', '2025-11-01');
            $defaultEndDate = Setting::get('project.default_end_date', '2026-01-31');

            // Validate start date is not before setting default
            if ($this->startDate && $this->startDate < $minStartDate) {
                $this->addError(
                    'startDate',
                    'Start date must be on or after '.Carbon::parse($minStartDate)->format('M d, Y').' (project setting).'
                );

                return;
            }

            // Force end date to setting default for reporters
            $this->endDate = $defaultEndDate;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'partnerId' => ['required', 'exists:partners,id'],
            'koramilId' => ['required', 'exists:offices,id'],
            'locationId' => ['required', 'exists:locations,id'],
            'startDate' => $this->isReporter() ? ['required', 'date'] : ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'status' => ['required', 'in:planning,active,completed,on_hold'],
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'partner_id' => $validated['partnerId'],
            'office_id' => $validated['koramilId'],
            'location_id' => $validated['locationId'],
            'start_date' => $validated['startDate'],
            'end_date' => $validated['endDate'],
            'status' => $validated['status'],
        ];

        if ($this->editingId) {
            $project = Project::findOrFail($this->editingId);
            $project->update($data);
        } else {
            $project = Project::create($data);

            // Attach current user as reporter
            $project->users()->attach(auth()->id(), [
                'role' => 'reporter',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->reset(['showModal', 'editingId', 'name', 'description', 'partnerId', 'kodimId', 'koramilId', 'locationId', 'startDate', 'endDate', 'status']);
        $this->dispatch('project-saved');
    }

    public function delete(int $id): void
    {
        $project = Project::findOrFail($id);

        // Check if current user can manage this project
        if (! $this->canManageProject($project)) {
            abort(403, 'You do not have permission to delete this project.');
        }

        $project->delete();
        $this->dispatch('project-deleted');
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'description', 'partnerId', 'kodimId', 'koramilId', 'locationId', 'startDate', 'endDate', 'status', 'isEditing']);
        $this->availableKoramils = collect();
        $this->availableLocations = collect();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Projects</flux:heading>
        <flux:button wire:click="create" variant="primary">
            Create Project
        </flux:button>
    </div>

    <div class="w-full max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search projects..."
            type="search"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Partner</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Office</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Location</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Status</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Start Date</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">End Date</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Assigned Users</th>
                    <th class="px-4 py-3 text-right text-sm font-semibold">
                        @if($this->isReporter())
                            Actual progress %
                        @else
                            Actions
                        @endif
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($projects as $project)
                    <tr wire:key="project-{{ $project->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 text-sm font-medium">{{ $project->name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $project->partner->name }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($project->office)
                                <div class="font-medium">{{ $project->office->name }}</div>
                                @if($project->office->parent)
                                    <div class="text-xs text-neutral-500">{{ $project->office->parent->name }}</div>
                                @endif
                            @else
                                <span class="text-neutral-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium">{{ $project->location->village_name }}</div>
                            <div class="text-xs text-neutral-500">{{ $project->location->district_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium
                                @if($project->status === 'planning') bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300
                                @elseif($project->status === 'active') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300
                                @elseif($project->status === 'completed') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300
                                @endif">
                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $project->start_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $project->end_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($project->users->isNotEmpty())
                                <div class="flex flex-wrap gap-1">
                                    @foreach($project->users as $user)
                                        <flux:badge size="sm" class="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                            {{ $user->name }}
                                        </flux:badge>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-neutral-400 dark:text-neutral-500">None</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            @if($this->canManageProject($project))
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button wire:click="edit({{ $project->id }})" size="sm" variant="ghost">
                                        Edit
                                    </flux:button>
                                    <flux:button
                                        wire:click="delete({{ $project->id }})"
                                        wire:confirm="Are you sure you want to delete this project?"
                                        size="sm"
                                        variant="ghost"
                                        class="text-red-600 hover:text-red-700"
                                    >
                                        Delete
                                    </flux:button>
                                </div>
                            @elseif($this->isReporter())
                                <div class="flex items-center justify-end">
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold
                                        @if($project->actual_progress >= 100) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300
                                        @elseif($project->actual_progress >= 75) bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300
                                        @elseif($project->actual_progress >= 50) bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300
                                        @elseif($project->actual_progress > 0) bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300
                                        @else bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300
                                        @endif">
                                        {{ number_format($project->actual_progress, 2) }}%
                                    </span>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No projects found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $projects->links() }}
    </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Project' : 'Create Project' }}</flux:heading>

            <flux:select wire:model="partnerId" label="Partner" required>
                <option value="">Select partner...</option>
                @foreach($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                @endforeach
            </flux:select>

            @if($this->isReporter() || auth()->user()->hasRole('Koramil Admin'))
                {{-- Read-only office fields for reporters and Koramil Admins --}}
                <div class="grid grid-cols-2 gap-4">
                    <flux:input
                        label="Kodim"
                        type="text"
                        :value="$kodims->firstWhere('id', $kodimId)?->name ?? ''"
                        readonly
                        disabled
                    />

                    <flux:input
                        label="Koramil"
                        type="text"
                        :value="$koramils->firstWhere('id', $koramilId)?->name ?? ''"
                        readonly
                        disabled
                    />
                </div>
            @else
                {{-- Editable office fields for Managers and Admins --}}
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model.live="kodimId" label="Kodim" required>
                        <option value="">Select Kodim...</option>
                        @foreach($kodims as $kodim)
                            <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        wire:model.live="koramilId"
                        wire:key="koramil-select-{{ $editingId ?? 'new' }}"
                        label="Koramil"
                        required>
                        <option value="">Select Koramil...</option>
                        @foreach(($editingId && $availableKoramils->isNotEmpty()) ? $availableKoramils : $koramils as $koramil)
                            <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            <flux:select
                wire:model.live="locationId"
                wire:key="location-select-{{ $editingId ?? 'new' }}"
                label="Location"
                required>
                <option value="">Select location...</option>
                @foreach(($editingId && $availableLocations->isNotEmpty()) ? $availableLocations : $locations as $location)
                    <option value="{{ $location->id }}">
                        {{ $location->village_name }} - {{ $location->district_name }}
                    </option>
                @endforeach
            </flux:select>

            @if($this->isReporter())
                {{-- Reporter: Only start date visible, with constraints --}}
                @php
                    $minStartDate = Setting::get('project.default_start_date', '2025-11-01');
                @endphp
                <flux:input
                    wire:model="startDate"
                    label="Project Start Date"
                    type="date"
                    min="{{ $minStartDate }}"
                    required
                />
                <p class="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                    Minimum allowed date: {{ \Carbon\Carbon::parse($minStartDate)->format('M d, Y') }}
                </p>
            @else
                {{-- Non-reporter: Both dates editable --}}
                <div class="grid grid-cols-2 gap-4">
                    <flux:input
                        wire:model="startDate"
                        label="Start Date"
                        type="date"
                    />

                    <flux:input
                        wire:model="endDate"
                        label="End Date"
                        type="date"
                    />
                </div>
            @endif

            <flux:input
                wire:model="name"
                label="Project Name"
                type="text"
                required
            />

            <flux:textarea
                wire:model="description"
                label="Description"
                rows="3"
            />

            <flux:select wire:model="status" label="Status" required>
                <option value="planning">Planning</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="on_hold">On Hold</option>
            </flux:select>

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" wire:click="cancelEdit" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
