<?php

use App\Models\Customer;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout};

layout('components.layouts.app');

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $address = '';

    public bool $showModal = false;

    public function with(): array
    {
        $customers = Customer::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%")
                ->orWhere('address', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(20);

        return [
            'customers' => $customers,
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'address']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->description = $customer->description ?? '';
        $this->address = $customer->address;
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['required', 'string'],
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'address' => $validated['address'],
        ];

        if ($this->editingId) {
            Customer::findOrFail($this->editingId)->update($data);
        } else {
            Customer::create($data);
        }

        $this->reset(['showModal', 'editingId', 'name', 'description', 'address']);
        $this->dispatch('customer-saved');
    }

    public function delete(int $id): void
    {
        Customer::findOrFail($id)->delete();
        $this->dispatch('customer-deleted');
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'description', 'address']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Customers</flux:heading>
        <flux:button wire:click="create" variant="primary">
            Create Customer
        </flux:button>
    </div>

    <div class="w-full max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search customers..."
            type="search"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Description</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Address</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Projects</th>
                    <th class="px-4 py-3 text-right text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 text-sm font-medium">{{ $customer->name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $customer->description ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $customer->address }}</td>
                        <td class="px-4 py-3 text-sm">{{ $customer->projects->count() }}</td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button wire:click="edit({{ $customer->id }})" size="sm" variant="ghost">
                                    Edit
                                </flux:button>
                                <flux:button
                                    wire:click="delete({{ $customer->id }})"
                                    wire:confirm="Are you sure you want to delete this customer? This will also delete all associated projects."
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
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No customers found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $customers->links() }}
    </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Customer' : 'Create Customer' }}</flux:heading>

            <flux:input
                wire:model="name"
                label="Name"
                type="text"
                required
                autofocus
            />

            <flux:textarea
                wire:model="description"
                label="Description"
                rows="3"
            />

            <flux:textarea
                wire:model="address"
                label="Address"
                rows="3"
                required
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
