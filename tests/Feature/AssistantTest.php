<?php

use App\Ai\Agents\Assistant;
use App\Models\User;
use Livewire\Livewire;

test('the dashboard embeds the assistant component', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertSeeLivewire('assistant');
});

test('a prompt is sent to the assistant agent and the answer is shown', function () {
    Assistant::fake(fn (string $prompt) => 'Echo: '.$prompt);

    Livewire::actingAs(User::factory()->create())
        ->test('assistant')
        ->set('prompt', 'Hello there')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSet('answer', 'Echo: Hello there')
        ->assertSee('Echo: Hello there');

    Assistant::assertPrompted('Hello there');
});

test('an empty prompt is rejected without calling the agent', function () {
    Assistant::fake();

    Livewire::actingAs(User::factory()->create())
        ->test('assistant')
        ->set('prompt', '')
        ->call('ask')
        ->assertHasErrors(['prompt' => 'required']);

    Assistant::assertNeverPrompted();
});

test('a provider failure is shown as an error instead of crashing', function () {
    Assistant::fake(fn () => throw new RuntimeException('Provider unavailable'));

    Livewire::actingAs(User::factory()->create())
        ->test('assistant')
        ->set('prompt', 'Hello there')
        ->call('ask')
        ->assertSet('answer', null)
        ->assertSet('error', 'Provider unavailable')
        ->assertSee('Provider unavailable');
});
