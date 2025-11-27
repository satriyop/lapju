<?php

use App\Models\Office;
use App\Models\OfficeLevel;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $nrp = '';

    public string $phone = '';

    public ?int $kodimId = null;

    public ?int $officeId = null;

    public string $password = '';

    public string $password_confirmation = '';

    public function updatedKodimId(): void
    {
        // Reset office_id when kodim changes
        $this->officeId = null;
    }

    public function register(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'nrp' => ['required', 'string', 'max:50', 'unique:users,nrp'],
            'phone' => ['required', 'string', 'min:10', 'max:13', 'regex:/^08[0-9]{8,11}$/', 'unique:users,phone'],
            'kodimId' => ['required', 'integer', 'exists:offices,id'],
            'officeId' => ['nullable', 'integer', 'exists:offices,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'phone.regex' => 'Phone number must start with 08 and contain 10-13 digits.',
            'phone.unique' => 'This phone number is already registered.',
        ]);

        // Verify the selected office belongs to the selected kodim (only if Koramil is selected)
        if ($this->officeId) {
            $office = Office::find($this->officeId);
            if (! $office || $office->parent_id !== $this->kodimId) {
                $this->addError('officeId', 'Invalid office selection.');

                return;
            }
        }

        // Create user via Fortify action
        app(\Laravel\Fortify\Contracts\CreatesNewUsers::class)->create([
            'name' => $this->name,
            'email' => $this->email ?: null, // Convert empty string to null
            'nrp' => $this->nrp,
            'phone' => $this->phone,
            'office_id' => $this->officeId ?: $this->kodimId, // Use Koramil if selected, otherwise use Kodim
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        // Redirect to login with pending message
        session()->flash('status', __('Your account has been created and is pending approval. You will be notified once approved.'));

        $this->redirect(route('login'), navigate: true);
    }

    public function with(): array
    {
        // Get Kodim level (level 3)
        $kodimLevel = OfficeLevel::where('level', 3)->first();
        $kodims = $kodimLevel
            ? Office::where('level_id', $kodimLevel->id)->orderBy('name')->get()
            : collect();

        // Get Koramil for selected Kodim
        $koramils = $this->kodimId
            ? Office::where('parent_id', $this->kodimId)->orderBy('name')->get()
            : collect();

        return [
            'kodims' => $kodims,
            'koramils' => $koramils,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
            <!-- Name -->
            <flux:input
                wire:model="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />
            @error('name')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Phone Number -->
            <flux:input
                wire:model="phone"
                :label="__('Mobile Phone Number')"
                type="tel"
                required
                autocomplete="tel"
                placeholder="08123456789"
            />
            @error('phone')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- NRP (Army Employee ID) -->
            <flux:input
                wire:model="nrp"
                :label="__('NRP (Employee ID)')"
                type="text"
                required
                autocomplete="off"
                :placeholder="__('Enter your NRP')"
            />
            @error('nrp')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Email Address (Optional) -->
            <flux:input
                wire:model="email"
                :label="__('Email address (Optional)')"
                type="email"
                autocomplete="email"
                placeholder="email@example.com"
            />
            @error('email')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Kodim Selection -->
            <flux:select
                wire:model.live="kodimId"
                :label="__('Kodim (District)')"
                required
            >
                <flux:select.option value="">{{ __('Select Kodim first') }}</flux:select.option>
                @foreach ($kodims as $kodim)
                    <flux:select.option value="{{ $kodim->id }}">
                        {{ $kodim->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            @error('kodimId')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Koramil Selection (depends on Kodim) -->
            <flux:select
                wire:model="officeId"
                :label="__('Koramil (Office - Optional)')"
                :disabled="!$kodimId"
            >
                <flux:select.option value="">
                    {{ $kodimId ? __('None - I work at Kodim level') : __('Select Kodim first') }}
                </flux:select.option>
                @foreach ($koramils as $koramil)
                    <flux:select.option value="{{ $koramil->id }}">
                        {{ $koramil->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <div class="-mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Leave empty if you work at Kodim level, or select your specific Koramil') }}
            </div>
            @error('officeId')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Password -->
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />
            @error('password')
                <div class="-mt-4 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <!-- Confirm Password -->
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Create account') }}</span>
                    <span wire:loading>{{ __('Creating...') }}</span>
                </flux:button>
            </div>
        </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
        <span>{{ __('Already have an account?') }}</span>
        <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
    </div>
</div>
