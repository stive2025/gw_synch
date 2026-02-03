<?php

use App\Http\Controllers\SynchronizationController;
use Illuminate\Support\Facades\Route;

Route::get('/syncs/credits',[SynchronizationController::class,'syncCredits']);
Route::get('/syncs/pays',[SynchronizationController::class,'syncPays']);
Route::post('/syncs/pays/list',[SynchronizationController::class,'getPaysList']);
