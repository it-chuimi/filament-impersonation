<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Http\Controllers\StopImpersonationController;
use Illuminate\Support\Facades\Route;

Route::post('/stop', StopImpersonationController::class)->name('stop');
