<?php

declare(strict_types=1);

use FileTransfer\Http\Controllers\RemoteController;
use FileTransfer\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TransferController::class, 'index']);
Route::get('/servers', [TransferController::class, 'servers']);
Route::get('/transfers/{id}', [TransferController::class, 'show'])->whereNumber('id');
Route::post('/transfers/{id}/cancel', [TransferController::class, 'cancel'])->whereNumber('id');

Route::post('/import', [TransferController::class, 'import']);
Route::post('/export', [TransferController::class, 'export']);
Route::post('/copy', [TransferController::class, 'copy']);

Route::post('/remote/test', [RemoteController::class, 'test']);
Route::post('/remote/list', [RemoteController::class, 'list']);
