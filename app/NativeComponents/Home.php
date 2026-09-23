<?php

namespace App\NativeComponents;

use App\Domain\AgroClima\Data\ForecastResult;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\Location;
use App\Services\AgroClima\AlertEvaluator;
use App\Services\AgroClima\BackgroundAlertSchedule;
use App\Services\AgroClima\ChartAxisFactory;
use App\Services\AgroClima\ChartSeriesFactory;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

#[Lazy]
class Home extends NativeComponent
{
    public ?string $locationId = null;

    /** @var array<string, mixed> */
    public array $forecast = [];

    public bool $stale = false;

    public bool $loading = false;

    public ?string $error = null;

    public ?string $fetchedAt = null;

    public ?string $pendingForecastRefreshId = null;

    public ?string $pendingForecastLocationId = null;

    public function mount(): void
    {
        $this->loadDefaultLocation();
    }

    public function onResume(): void
    {
        $default = $this->defaultLocation();

        if ($default?->id !== $this->locationId) {
            $this->locationId = $default?->id;
            $this->forecast = [];
            $this->stale = false;
            $this->fetchedAt = null;
            $this->pendingForecastRefreshId = null;
            $this->pendingForecastLocationId = null;
            $this->loading = false;
        }

        $this->loadForecast();
    }

    public function refreshForecast(): void
    {
        $this->loadForecast(force: true);
    }

    /** @return list<array{label: string, value: string, unit: string}> */
    #[Computed]
    public function currentConditions(): array
    {
        if ($this->forecast === []) {
            return [];
        }

        $data = WeatherData::fromArray($this->forecast);

        return collect(WeatherMetric::cases())
            ->map(function (WeatherMetric $metric) use ($data): array {
                $value = $data->currentValue($metric);

                return [
                    'label' => $this->metricLabel($metric),
                    'value' => $value === null ? '—' : number_format($value, $metric === WeatherMetric::Precipitation ? 1 : 0, ',', '.'),
                    'unit' => $this->metricUnit($metric),
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function temperatureSeries(): array
    {
        $location = $this->location();

        if ($location === null || $this->forecast === []) {
            return [];
        }

        return app(ChartSeriesFactory::class)->forMetric(
            $location,
            WeatherData::fromArray($this->forecast),
            WeatherMetric::Temperature,
            24,
        );
    }

    /** @return array<string, bool|float|int|string> */
    #[Computed]
    public function temperatureYAxis(): array
    {
        return app(ChartAxisFactory::class)->yAxis(WeatherMetric::Temperature, $this->temperatureSeries);
    }

    #[Computed]
    public function locationName(): string
    {
        return $this->location()?->name ?? 'Sin ubicación';
    }

    public function render(): View
    {
        $this->finishForecastRefresh();

        return view('native.home');
    }

    private function loadDefaultLocation(): void
    {
        $this->locationId = $this->defaultLocation()?->id;
        $this->loadForecast();
    }

    private function loadForecast(bool $force = false): void
    {
        $location = $this->location();

        if ($location === null) {
            $this->forecast = [];
            $this->stale = false;
            $this->fetchedAt = null;
            $this->error = null;
            $this->loading = false;
            $this->pendingForecastRefreshId = null;
            $this->pendingForecastLocationId = null;
            app(BackgroundAlertSchedule::class)->sync();

            return;
        }

        $this->error = null;

        try {
            $service = app(ForecastService::class);
            $cached = $service->cachedForLocation($location);

            if ($cached !== null) {
                $this->applyForecast($location, $cached);
            } else {
                $this->forecast = [];
                $this->stale = false;
                $this->fetchedAt = null;
            }

            if ($force || $cached === null || $cached->stale) {
                $this->queueForecastRefresh($location, $force);
            } else {
                $this->loading = $this->pendingForecastLocationId === $location->id
                    && $this->pendingForecastRefreshId !== null;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'No pudimos actualizar. Revisa tu conexión.';
            $this->loading = false;
            $this->clearPendingForecastRefresh();
        }

        app(BackgroundAlertSchedule::class)->sync();
    }

    private function queueForecastRefresh(Location $location, bool $force): void
    {
        if ($this->pendingForecastLocationId === $location->id
            && $this->pendingForecastRefreshId !== null
            && app(ForecastRefreshQueue::class)->status($this->pendingForecastRefreshId) === 'pending') {
            $this->loading = true;

            return;
        }

        try {
            $this->pendingForecastRefreshId = app(ForecastRefreshQueue::class)->start($location, $force);
            $this->pendingForecastLocationId = $location->id;
            $this->loading = true;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'No pudimos iniciar la actualización. Inténtalo de nuevo.';
            $this->loading = false;
            $this->clearPendingForecastRefresh();
        }
    }

    private function finishForecastRefresh(): void
    {
        $requestId = $this->pendingForecastRefreshId;

        if ($requestId === null) {
            return;
        }

        $refreshQueue = app(ForecastRefreshQueue::class);
        $status = $refreshQueue->status($requestId);

        if ($status === 'pending') {
            return;
        }

        $locationId = $this->pendingForecastLocationId;
        $refreshQueue->forget($requestId);
        $this->clearPendingForecastRefresh();
        $this->loading = false;

        if ($locationId !== $this->locationId) {
            return;
        }

        if ($status !== 'complete') {
            $this->error = 'No pudimos actualizar. Revisa tu conexión e inténtalo de nuevo.';
            app(BackgroundAlertSchedule::class)->sync();

            return;
        }

        $location = $this->location();
        $result = $location === null ? null : app(ForecastService::class)->cachedForLocation($location);

        if ($location === null || $result === null) {
            $this->error = 'No encontramos un pronóstico guardado. Inténtalo de nuevo.';
            app(BackgroundAlertSchedule::class)->sync();

            return;
        }

        $this->error = null;
        $this->applyForecast($location, $result);
        app(BackgroundAlertSchedule::class)->sync();
    }

    private function applyForecast(Location $location, ForecastResult $result): void
    {
        $this->forecast = $result->data->toArray();
        $this->stale = $result->stale;
        $updatedAt = $result->fetchedAt->setTimezone($result->data->timezone);
        $this->fetchedAt = $updatedAt->isToday()
            ? 'Hoy, '.$updatedAt->translatedFormat('g:i a')
            : $updatedAt->translatedFormat('j M, g:i a');
        app(AlertEvaluator::class)->evaluate($location, $result->data, $result->stale);
    }

    private function clearPendingForecastRefresh(): void
    {
        $this->pendingForecastRefreshId = null;
        $this->pendingForecastLocationId = null;
    }

    private function defaultLocation(): ?Location
    {
        return Location::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->first();
    }

    private function location(): ?Location
    {
        return $this->locationId === null ? null : Location::query()->find($this->locationId);
    }

    private function metricLabel(WeatherMetric $metric): string
    {
        return match ($metric) {
            WeatherMetric::Temperature => 'Temperatura',
            WeatherMetric::Humidity => 'Humedad',
            WeatherMetric::Precipitation => 'Precipitación',
            WeatherMetric::WindSpeed => 'Viento',
        };
    }

    private function metricUnit(WeatherMetric $metric): string
    {
        return match ($metric) {
            WeatherMetric::Temperature => '°C',
            WeatherMetric::Humidity => '%',
            WeatherMetric::Precipitation => 'mm',
            WeatherMetric::WindSpeed => 'km/h',
        };
    }
}
