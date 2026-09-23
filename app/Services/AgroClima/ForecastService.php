<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Contracts\WeatherProvider;
use App\Domain\AgroClima\Data\Coordinates;
use App\Domain\AgroClima\Data\ForecastResult;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Exceptions\WeatherUnavailableException;
use App\Models\Location;
use App\Models\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class ForecastService
{
    public function __construct(private WeatherProvider $provider) {}

    public function forLocation(Location $location, bool $force = false): ForecastResult
    {
        $cached = $this->cachedForLocation($location);
        if (! $force && $cached !== null && ! $cached->stale) {
            return $cached;
        }

        try {
            $data = $this->provider->fetch(new Coordinates(
                latitude: (float) $location->latitude,
                longitude: (float) $location->longitude,
            ));
        } catch (Throwable $exception) {
            if ($cached !== null) {
                return new ForecastResult(
                    data: $cached->data,
                    stale: true,
                    fetchedAt: $cached->fetchedAt,
                    expiresAt: $cached->expiresAt,
                );
            }

            throw new WeatherUnavailableException(
                'No fue posible obtener el pronóstico y no hay datos guardados.',
                previous: $exception,
            );
        }

        $fetchedAt = CarbonImmutable::now();
        $expiresAt = $fetchedAt->addMinutes((int) config('services.open_meteo.cache_minutes', 30));

        $snapshot = WeatherSnapshot::query()->updateOrCreate(
            [
                'location_id' => $location->getKey(),
                'provider' => $this->provider->name(),
            ],
            [
                'schema_version' => 1,
                'payload' => $data->toArray(),
                'fetched_at' => $fetchedAt,
                'expires_at' => $expiresAt,
            ],
        );

        if ($location->timezone !== $data->timezone) {
            $location->forceFill(['timezone' => $data->timezone])->save();
        }

        return new ForecastResult($data, false, $fetchedAt, $expiresAt);
    }

    public function cachedForLocation(Location $location): ?ForecastResult
    {
        $snapshot = WeatherSnapshot::query()
            ->whereBelongsTo($location)
            ->where('provider', $this->provider->name())
            ->first();

        return $this->resultFromSnapshot($snapshot);
    }

    private function resultFromSnapshot(?WeatherSnapshot $snapshot): ?ForecastResult
    {
        if ($snapshot === null || $snapshot->schema_version !== 1 || ! is_array($snapshot->payload)) {
            return null;
        }

        try {
            $data = WeatherData::fromArray($snapshot->payload);
        } catch (Throwable) {
            return null;
        }

        $fetchedAt = CarbonImmutable::parse($snapshot->fetched_at);
        $expiresAt = CarbonImmutable::parse($snapshot->expires_at);

        return new ForecastResult(
            data: $data,
            stale: ! $expiresAt->isFuture(),
            fetchedAt: $fetchedAt,
            expiresAt: $expiresAt,
        );
    }
}
