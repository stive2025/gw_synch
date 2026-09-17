<?php

namespace App\Jobs;

use App\Http\Controllers\SynchronizationController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncContactsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    public function __construct(private array $batch) {}

    public function handle(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        Log::channel('credits')->info('Job contactos iniciado', ['creditos' => count($this->batch)]);

        $stats = (new SynchronizationController())->syncContactsForBatch($this->batch);

        Log::channel('credits')->info('Job contactos completado', $stats);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('credits')->error('Job contactos falló', [
            'creditos' => count($this->batch),
            'error' => $exception->getMessage()
        ]);
    }
}
