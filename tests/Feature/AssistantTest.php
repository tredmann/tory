<?php

use App\Ai\Agents\Assistant;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
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

test('a provider failure is reported and shown as a generic error when debug is off', function () {
    config(['app.debug' => false]);
    Exceptions::fake();
    Assistant::fake(fn () => throw new RuntimeException('Provider unavailable: secret-host:443'));

    Livewire::actingAs(User::factory()->create())
        ->test('assistant')
        ->set('prompt', 'Hello there')
        ->call('ask')
        ->assertSet('answer', null)
        ->assertSet('error', 'The assistant is unavailable right now. Please try again later.')
        ->assertDontSee('secret-host');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Provider unavailable: secret-host:443');
});

test('a provider failure shows the real message when debug is on', function () {
    config(['app.debug' => true]);
    Exceptions::fake();
    Assistant::fake(fn () => throw new RuntimeException('Provider unavailable'));

    Livewire::actingAs(User::factory()->create())
        ->test('assistant')
        ->set('prompt', 'Hello there')
        ->call('ask')
        ->assertSet('error', 'Provider unavailable')
        ->assertSee('Provider unavailable');
});
