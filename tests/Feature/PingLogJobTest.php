<?php

use App\Jobs\PingLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('the ping job is pushed onto the queue', function () {
    Queue::fake();

    PingLog::dispatch();

    Queue::assertPushed(PingLog::class);
});

test('the ping job writes a marker line to the log', function () {
    Log::shouldReceive('info')->once()->withArgs(fn (string $message) => str_starts_with($message, 'queue ok'));

    (new PingLog)->handle();
});
