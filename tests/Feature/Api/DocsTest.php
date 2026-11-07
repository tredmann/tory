<?php

use App\Models\User;

test('a verified panel user sees the docs', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/docs/api')->assertOk();

    $paths = $this->get('/docs/api.json')->assertOk()->json('paths');

    expect($paths)->toHaveKeys(['/login', '/items/{item}']);
});

test('the spec documents creating an item from a multipart upload', function () {
    $this->actingAs(User::factory()->create());

    $operation = $this->get('/docs/api.json')->json('paths./items.post');

    expect($operation['requestBody']['content'])->toHaveKey('multipart/form-data')
        ->and($operation['responses'])->toHaveKey('201');
});

test('a guest gets 403', function () {
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

test('an unverified user gets 403', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/docs/api')->assertForbidden();
});

test('the spec declares bearer authentication', function () {
    $this->actingAs(User::factory()->create());

    $scheme = collect($this->get('/docs/api.json')->json('components.securitySchemes'))
        ->first(fn (array $scheme) => $scheme['type'] === 'http' && $scheme['scheme'] === 'bearer');

    expect($scheme)->not->toBeNull();
});

test('the spec never mentions the internal spot id', function () {
    $this->actingAs(User::factory()->create());

    $json = $this->get('/docs/api.json')->getContent();

    expect($json)->not->toContain('spot_id')->not->toContain('last_spot_id');
});
