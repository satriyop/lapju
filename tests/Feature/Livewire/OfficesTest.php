<?php

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OfficesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_render(): void
    {
        $component = Volt::test('offices');

        $component->assertSee('');
    }
}
