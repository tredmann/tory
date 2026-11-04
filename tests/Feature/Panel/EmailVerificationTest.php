<?php

use App\Models\User;

test('unverified users are sent to the verification prompt', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/')->assertRedirect('/email-verification/prompt');
});

test('verified users see the dashboard at the root', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk()->assertSee($user->name);
});
