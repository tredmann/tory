<?php

use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
});

test('the list is reachable and in the navigation as Importe', function () {
    $this->get('/importe')->assertOk()->assertSee('Importe');
});

test('imports are listed newest first with status and title', function () {
    $older = Import::factory()->completed()->create(['created_at' => now()->subDay()]);
    $newer = Import::factory()->failed()->create();

    Livewire::test(ListImports::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('Raspberry Pi 4')
        ->assertSee('Fehlgeschlagen')
        ->assertSee('Fertig');
});

test('the list offers the Erfassen action', function () {
    Livewire::test(ListImports::class)->assertActionVisible('create');
});
