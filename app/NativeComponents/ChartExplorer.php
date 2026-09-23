<?php

namespace App\NativeComponents;

use App\Domain\AgroClima\Data\ForecastResult;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\Location;
use App\Services\AgroClima\ChartAxisFactory;
use App\Services\AgroClima\ChartSeriesFactory;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use Donmanueldev\NativephpCharts\PointSelection;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

#[Lazy]
class ChartExplorer extends NativeComponent
{
    public ?string $locationId = null;

    public string $locationChoice = '';

    public ?string $loadedLocationUpdatedAt = null;

    public string $metricChoice = 'Temperatura';

    public string $rangeChoice = '24 horas';

    /** @var list<array<string, mixed>> */
    public array $series = [];

    /** @var array<string, mixed> */
    public array $forecast = [];

    public bool $stale = false;

    public bool $loading = false;

    public ?string $error = null;

    public ?string $selectedPoint = null;

    public ?string $selectedPointId = null;

    public ?string $pendingForecastRefreshId = null;

    public ?string $pendingForecastLocationId = null;

    public function mount(): void
    {
        $location = Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();

        if ($location !== null) {
            $this->locationId = $location->id;
            $this->locationChoice = $this->locationLabel($location);
            $this->loadedLocationUpdatedAt = $location->updated_at?->toIso8601String();
            $this->loadSeries();
        }
    }

    public function onResume(): void
    {
        $currentLocation = $this->location()
            ?? Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();

        if ($currentLocation === null) {
            $this->locationId = null;
            $this->locationChoice = '';
            $this->loadedLocationUpdatedAt = null;
            $this->series = [];
            $this->forecast = [];
            $this->clearSelection();
            $this->loading = false;
            $this->clearPendingForecastRefresh();

            return;
        }

        $updatedAt = $currentLocation->updated_at?->toIso8601String();

        if ($currentLocation->id !== $this->locationId || $updatedAt !== $this->loadedLocationUpdatedAt) {
            $this->locationId = $currentLocation->id;
            $this->locationChoice = $this->locationLabel($currentLocation);
            $this->loadedLocationUpdatedAt = $updatedAt;
            $this->forecast = [];
            $this->series = [];
            $this->stale = false;
            $this->error = null;
            $this->clearSelection();
            $this->loadSeries();
        }
    }

    public function updatedLocationChoice(string $choice): void
    {
        $this->locationId = $this->locationChoiceMap()[$choice] ?? null;
        $this->loadedLocationUpdatedAt = $this->location()?->updated_at?->toIso8601String();
        $this->clearSelection();
        $this->loadSeries();
    }

    public function updatedMetricChoice(): void
    {
        $this->clearSelection();
        $this->rebuildSeries();
    }

    public function updatedRangeChoice(): void
    {
        $this->clearSelection();
        $this->rebuildSeries();
    }

    public function refreshSeries(): void
    {
        $this->loadSeries(force: true);
    }

    public function pointSelected(string $payload): void
    {
        $selection = PointSelection::fromJson($payload);
        $this->selectedPointId = $selection->pointId;
        $this->selectedPoint = "{$selection->label}: {$selection->localizedValue} {$this->metricUnit($this->metric())}";
    }

    /** @return list<string> */
    #[Computed]
    public function locationOptions(): array
    {
        return array_keys($this->locationChoiceMap());
    }

    /** @return list<string> */
    #[Computed]
    public function metricOptions(): array
    {
        return ['Temperatura', 'Humedad', 'Precipitación', 'Viento'];
    }

    #[Computed]
    public function chartKind(): string
    {
        return match ($this->metric()) {
            WeatherMetric::Precipitation => 'bar',
            WeatherMetric::Humidity => 'area',
            default => 'line',
        };
    }

    #[Computed]
    public function chartDescription(): string
    {
        return sprintf(
            '%s para %s durante %s, expresada en %s',
            $this->metricChoice,
            $this->location()?->name ?? 'la ubicación seleccionada',
            mb_strtolower($this->rangeChoice),
            $this->metricUnit($this->metric()),
        );
    }

    #[Computed]
    public function timezone(): string
    {
        return $this->forecast['timezone'] ?? 'UTC';
    }

    /** @return array<string, bool|float|int|string> */
    #[Computed]
    public function chartXAxis(): array
    {
        return [
            'type' => 'datetime',
            'dateFormat' => $this->rangeChoice === '7 días' ? 'short' : 'time',
            'timezone' => $this->timezone,
        ];
    }

    /** @return array<string, bool|float|int|string> */
    #[Computed]
    public function chartYAxis(): array
    {
        return app(ChartAxisFactory::class)->yAxis($this->metric(), $this->series);
    }

    /** @return array<string, bool|string> */
    #[Computed]
    public function chartInteraction(): array
    {
        return [
            'enabled' => true,
            'mode' => 'scrub',
            'crosshair' => 'x',
            'tooltip' => 'single',
        ];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function chartStyle(): array
    {
        $pointsVisible = $this->rangeChoice === '24 horas';

        return match ($this->chartKind) {
            'area' => [
                'line' => ['width' => 3, 'interpolation' => 'smooth'],
                'area' => ['opacity' => 0.18],
                'points' => ['visible' => $pointsVisible, 'size' => 4],
                'axis' => ['labelCount' => 5],
            ],
            'bar' => [
                'bar' => ['radius' => 3],
                'axis' => ['labelCount' => 5],
            ],
            default => [
                'line' => ['width' => 3, 'interpolation' => 'smooth'],
                'points' => ['visible' => $pointsVisible, 'size' => 4],
                'axis' => ['labelCount' => 5],
            ],
        };
    }

    public function render(): View
    {
        $this->finishForecastRefresh();

        return view('native.chart-explorer');
    }

    private function loadSeries(bool $force = false): void
    {
        $location = $this->location();

        if ($this->pendingForecastLocationId !== null
            && $this->pendingForecastLocationId !== $location?->id) {
            $this->clearPendingForecastRefresh();
            $this->loading = false;
        }

        if ($location === null) {
            $this->series = [];
            $this->forecast = [];
            $this->loading = false;

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
                $this->series = [];
                $this->stale = false;
            }

            if ($force || $cached === null || $cached->stale) {
                $this->queueForecastRefresh($location, $force);
            } else {
                $this->loading = $this->pendingForecastLocationId === $location->id
                    && $this->pendingForecastRefreshId !== null;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'No pudimos cargar la serie climática.';
            $this->loading = false;
            $this->clearPendingForecastRefresh();
        }
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
            $this->error = 'No pudimos iniciar la actualización de la gráfica.';
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
            $this->error = 'No pudimos cargar la serie climática. Revisa tu conexión e inténtalo de nuevo.';

            return;
        }

        $location = $this->location();
        $result = $location === null ? null : app(ForecastService::class)->cachedForLocation($location);

        if ($location === null || $result === null) {
            $this->error = 'No encontramos un pronóstico guardado. Inténtalo de nuevo.';

            return;
        }

        $this->error = null;
        $this->applyForecast($location, $result);
    }

    private function applyForecast(Location $location, ForecastResult $result): void
    {
        $this->forecast = $result->data->toArray();
        $this->stale = $result->stale;
        $this->loadedLocationUpdatedAt = Location::query()->find($location->id)?->updated_at?->toIso8601String();
        $this->rebuildSeries();
    }

    private function clearPendingForecastRefresh(): void
    {
        $this->pendingForecastRefreshId = null;
        $this->pendingForecastLocationId = null;
    }

    private function rebuildSeries(): void
    {
        $location = $this->location();

        if ($location === null || $this->forecast === []) {
            $this->series = [];

            return;
        }

        $this->series = app(ChartSeriesFactory::class)->forMetric(
            $location,
            WeatherData::fromArray($this->forecast),
            $this->metric(),
            $this->rangeChoice === '7 días' ? 168 : 24,
        );

        if ($this->selectedPointId !== null) {
            $point = $this->point($this->selectedPointId);

            if ($point === null) {
                $this->clearSelection();
            } else {
                $this->selectedPoint = $this->formatSelectedPoint($point);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function point(string $pointId): ?array
    {
        foreach ($this->series as $series) {
            foreach ($series['points'] ?? [] as $point) {
                if (($point['id'] ?? null) === $pointId) {
                    return $point;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $point */
    private function formatSelectedPoint(array $point): string
    {
        $value = number_format((float) ($point['value'] ?? 0), 1, ',', '.');

        return ($point['label'] ?? '').": {$value} {$this->metricUnit($this->metric())}";
    }

    private function clearSelection(): void
    {
        $this->selectedPoint = null;
        $this->selectedPointId = null;
    }

    private function metric(): WeatherMetric
    {
        return match ($this->metricChoice) {
            'Humedad' => WeatherMetric::Humidity,
            'Precipitación' => WeatherMetric::Precipitation,
            'Viento' => WeatherMetric::WindSpeed,
            default => WeatherMetric::Temperature,
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

    private function location(): ?Location
    {
        return $this->locationId === null ? null : Location::query()->find($this->locationId);
    }

    /** @return array<string, string> */
    private function locationChoiceMap(): array
    {
        $locations = Location::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $nameCounts = $locations->countBy('name');
        $bases = $locations->map(fn (Location $location): string => $nameCounts[$location->name] === 1
            ? $location->name
            : sprintf('%s · %.4f, %.4f', $location->name, $location->latitude, $location->longitude));
        $baseCounts = $bases->countBy();
        $occurrences = [];
        $choices = [];

        foreach ($locations as $index => $location) {
            $base = $bases[$index];
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $label = $baseCounts[$base] === 1
                ? $base
                : $base.' · '.($location->is_default ? 'Principal' : 'Punto '.$occurrences[$base]);
            $choices[$label] = $location->id;
        }

        return $choices;
    }

    private function locationLabel(Location $location): string
    {
        return array_search($location->id, $this->locationChoiceMap(), true) ?: $location->name;
    }
}
