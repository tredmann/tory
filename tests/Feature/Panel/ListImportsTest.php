<?php

use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\Import;
use App\Models\Spot;
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

test('the list shows the spot or a placeholder', function () {
    $spot = Spot::factory()->create();
    $with = Import::factory()->completed()->create(['spot_id' => $spot->id]);
    $without = Import::factory()->completed()->create(['spot_id' => null]);

    Livewire::test(ListImports::class)
        ->assertCanSeeTableRecords([$with, $without])
        ->assertSee("{$spot->code} · {$spot->name}")
        ->assertSee('—');
});
