<?php

test('the home page renders inside the daisyUI app layout', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee('class="navbar', false)
        ->assertSee('data-theme', false);
});
