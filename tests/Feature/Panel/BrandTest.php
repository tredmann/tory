<?php

use App\Models\User;

test('the panel shows the tory logo in light and dark mode', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee(asset('images/tory-mark.svg'))
        ->assertSee(asset('images/tory-mark-white.svg'))
        ->assertSee('fi-logo-dark', escape: false);
});

test('the login page uses the tory mark as favicon', function () {
    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="'.asset('images/tory-mark.svg').'"', escape: false);
});
