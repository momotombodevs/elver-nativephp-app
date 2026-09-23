<?php

namespace App\Services\AgroClima;

use App\Jobs\RefreshLocationForecast;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class ForecastRefreshQueue
{
    private const KEY_PREFIX = 'agroclima:forecast-refresh:';

    private const EXPIRATION_MINUTES = 10;

    public function start(Location $location, bool $force = false): string
    {
        $requestId = (string) Str::uuid();
        $this->store($requestId, 'pending');

        try {
            RefreshLocationForecast::dispatch($location->id, $requestId, $force);
        } catch (Throwable $exception) {
            $this->fail($requestId);

            throw $exception;
        }

        return $requestId;
    }

    public function status(string $requestId): ?string
    {
        $status = Cache::get($this->key($requestId));

        return is_string($status) ? $status : null;
    }

    public function complete(string $requestId): void
    {
        $this->store($requestId, 'complete');
    }

    public function fail(string $requestId): void
    {
        $this->store($requestId, 'failed');
    }

    public function forget(string $requestId): void
    {
        Cache::forget($this->key($requestId));
    }

    private function store(string $requestId, string $status): void
    {
        Cache::put(
            $this->key($requestId),
            $status,
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );
    }

    private function key(string $requestId): string
    {
        return self::KEY_PREFIX.$requestId;
    }
}
