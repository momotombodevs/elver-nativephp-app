<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RefreshLocationForecast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public string $locationId,
        public string $requestId,
        public bool $force,
    ) {}

    public function handle(ForecastService $forecastService, ForecastRefreshQueue $refreshQueue): void
    {
        try {
            $location = Location::query()->find($this->locationId);

            if ($location === null) {
                $refreshQueue->fail($this->requestId);

                return;
            }

            $forecastService->forLocation($location, $this->force);
            $refreshQueue->complete($this->requestId);
        } catch (Throwable $exception) {
            report($exception);
            $refreshQueue->fail($this->requestId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        app(ForecastRefreshQueue::class)->fail($this->requestId);
    }
}
