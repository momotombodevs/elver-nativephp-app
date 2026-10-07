<?php

namespace App\Services\Weather;

use App\Jobs\RefreshLocationForecast;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class ForecastRefreshQueue
{
    private const KEY_PREFIX = 'elver:forecast-refresh:';

    private const LEGACY_KEY_PREFIX = 'agroclima:forecast-refresh:';

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

        if (! is_string($status)) {
            $status = Cache::get($this->legacyKey($requestId));

            if (is_string($status)) {
                $this->store($requestId, $status);
                Cache::forget($this->legacyKey($requestId));
            }
        }

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
        Cache::forget($this->legacyKey($requestId));
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

    private function legacyKey(string $requestId): string
    {
        return self::LEGACY_KEY_PREFIX.$requestId;
    }
}
