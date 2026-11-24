<?php

use App\Filament\Pages\TorySetup;
use App\Mcp\ClaudeConnections;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the page explains how to connect', function (): void {
    $this->get('/tory-in-claude')
        ->assertOk()
        ->assertSee(url('/mcp'))
        ->assertSee('claude mcp add --transport http --scope user tory '.url('/mcp'))
        ->assertSee('Wo ist die Bohrmaschine?');
});

test('the explanation avoids technical terms', function (): void {
    $html = Livewire::test(TorySetup::class)->html();
    $body = str_replace('claude mcp add --transport http --scope user tory '.url('/mcp'), '', $html);
    $body = str_replace('tippe /mcp,', '', $body);
    $body = str_replace(url('/mcp'), '', $body);
    $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $body));

    expect($text)->not->toContain('MCP')->not->toContain('OAuth')->not->toContain('Token');
});

test('the address and the command show a copy icon', function (): void {
    $html = Livewire::test(TorySetup::class)->html();

    expect(substr_count($html, '<svg'))->toBe(2);
});

test('it says when no claude is connected', function (): void {
    Livewire::test(TorySetup::class)->assertSee('Noch kein Claude verbunden.');
});

test("it lists the user's connections", function (): void {
    issueMcpTokens($this->user);
    $this->actingAs($this->user);

    Livewire::test(TorySetup::class)
        ->assertSee('claude.ai')
        ->assertSee('Verbunden am '.now()->format('d.m.Y'))
        ->assertDontSee('Noch kein Claude verbunden.');
});

test('trennen disconnects', function (): void {
    $tokens = issueMcpTokens($this->user);
    $this->actingAs($this->user);

    Livewire::test(TorySetup::class)
        ->callAction(TestAction::make('disconnect')->arguments(['clientId' => $tokens['client_id']]))
        ->assertHasNoActionErrors();

    expect(app(ClaudeConnections::class)->for($this->user))->toBeEmpty();
});

test('the page lists what claude cannot do in its own section', function (): void {
    Livewire::test(TorySetup::class)
        ->assertSee('Was Claude nicht kann')
        ->assertSee('Fotos und neue Gegenstände gehen über „Erfassen“.')
        ->assertSee('Tippe als Name')
        ->assertSee('tippe /mcp, wähle „tory“ und melde dich bei Tory an.', escape: false);
});
