<?php

use App\Http\Controllers\SynchronizationController;
use Illuminate\Support\Facades\Route;

Route::get('/syncs/credits',[SynchronizationController::class,'syncCredits']);
