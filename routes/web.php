<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $bootedAt = Carbon::parse(Cache::rememberForever('server_booted_at', fn () => now()->toIso8601String()));

    return view('welcome', ['uptime' => $bootedAt->diffForHumans(null, true)]);
});
