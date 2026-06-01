<?php

use App\Http\Controllers\SynchronizationController;
use Illuminate\Support\Facades\Route;

Route::get('/syncs/credits',[SynchronizationController::class,'syncCredits']);
Route::get('/syncs/clients/fix-names',[SynchronizationController::class,'fixClientNames']);
Route::get('/syncs/pays',[SynchronizationController::class,'syncPays']);
Route::get('/syncs/pays/alt',[SynchronizationController::class,'syncPaysAlt']);
Route::post('/syncs/pays/list',[SynchronizationController::class,'getPaysList']);
Route::post('/syncs/credits/list',[SynchronizationController::class,'getCreditsList']);
Route::post('/syncs/contacts/list',[SynchronizationController::class,'getContactsList']);
