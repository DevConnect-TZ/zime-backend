<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $bootedAt = Cache::rememberForever('server_booted_at', fn () => now());

    return view('welcome', ['uptime' => $bootedAt->diffForHumans(null, true)]);
});
