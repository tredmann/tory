<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

test('the user menu shows the released version', function () {
    config(['app.version' => '1.2.0']);

    $this->get('/')
        ->assertOk()
        ->assertSee('Version 1.2.0');
});

test('the user menu shows dev without a released version', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Version dev');
});
