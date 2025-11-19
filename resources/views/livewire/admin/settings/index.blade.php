<?php

use App\Models\Setting;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $key = '';

    public string $value = '';

    public string $description = '';

    public bool $showModal = false;

    public function with(): array
    {
        $settings = Setting::query()
            ->when($this->search, fn ($query) => $query->where('key', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->orderBy('key')
            ->get();

        return [
            'settings' => $settings,
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'key', 'value', 'description']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $setting = Setting::findOrFail($id);
        $this->editingId = $setting->id;
        $this->key = $setting->key;
        $this->value = is_string($setting->value) ? $setting->value : json_encode($setting->value);
        $this->description = $setting->description ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'key' => ['required', 'string', 'max:255', $this->editingId ? 'unique:settings,key,'.$this->editingId : 'unique:settings,key'],
            'value' => ['required', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $data = [
            'key' => $validated['key'],
            'value' => json_decode($validated['value']) ?? $validated['value'],
            'description' => $validated['description'] ?: null,
        ];

        if ($this->editingId) {
            Setting::findOrFail($this->editingId)->update($data);
        } else {
            Setting::create($data);
        }

        $this->reset(['showModal', 'editingId', 'key', 'value', 'description']);
    }

    public function delete(int $id): void
    {
        Setting::findOrFail($id)->delete();
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'key', 'value', 'description']);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Settings</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                Manage application configuration settings
            </p>
        </div>
        <flux:button wire:click="create" variant="primary">
            <flux:icon.plus class="mr-2 h-4 w-4" />
            Create Setting
        </flux:button>
    </div>

    <div class="w-full max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search settings..."
            type="search"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Key</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Value</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Description</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($settings as $setting)
                    <tr wire:key="setting-{{ $setting->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {{ $setting->key }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            <code class="rounded bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">
                                {{ is_string($setting->value) ? Str::limit($setting->value, 50) : json_encode($setting->value) }}
                            </code>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $setting->description ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button wire:click="edit({{ $setting->id }})" size="sm" variant="ghost">
                                    Edit
                                </flux:button>
                                <flux:button
                                    wire:click="delete({{ $setting->id }})"
                                    wire:confirm="Are you sure you want to delete this setting?"
                                    size="sm"
                                    variant="ghost"
                                    class="text-red-600 hover:text-red-700"
                                >
                                    Delete
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No settings found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Setting' : 'Create Setting' }}</flux:heading>

            <flux:input
                wire:model="key"
                label="Key"
                type="text"
                required
                autofocus
                placeholder="e.g., app.name"
                :disabled="!!$editingId"
            />

            <flux:textarea
                wire:model="value"
                label="Value (JSON format)"
                rows="4"
                required
                placeholder='e.g., "My App" or {"foo": "bar"}'
            />

            <flux:textarea
                wire:model="description"
                label="Description"
                rows="2"
            />

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
