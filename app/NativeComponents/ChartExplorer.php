<?php

namespace App\NativeComponents;

use App\Domain\Weather\Data\ForecastResult;
use App\Domain\Weather\Data\WeatherData;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Models\Location;
use App\Services\Localization\LocalePreferences;
use App\Services\Weather\ChartAxisFactory;
use App\Services\Weather\ChartSeriesFactory;
use App\Services\Weather\ForecastRefreshQueue;
use App\Services\Weather\ForecastService;
use App\Services\Weather\UnitPreferences;
use Donmanueldev\NativephpCharts\PointSelection;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

#[Lazy]
class ChartExplorer extends NativeComponent
{
    public ?string $locationId = null;

    public string $locationChoice = '';

    public ?string $loadedLocationUpdatedAt = null;

    public ?string $fetchedAtLabel = null;

    public string $metricChoice = '';

    public string $rangeChoice = '';

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
        app(LocalePreferences::class)->apply();
        $this->metricChoice = WeatherMetric::Temperature->label();
        $this->rangeChoice = __('ui.explorer.hours_24');

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
        $metric = $this->metric();
        $isDailyRange = $this->isDailyRange();
        app(LocalePreferences::class)->apply();
        $this->metricChoice = $metric->label();
        $this->rangeChoice = $isDailyRange ? __('ui.explorer.days_7') : __('ui.explorer.hours_24');

        $currentLocation = $this->location()
            ?? Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();

        if ($currentLocation === null) {
            $this->locationId = null;
            $this->locationChoice = '';
            $this->loadedLocationUpdatedAt = null;
            $this->fetchedAtLabel = null;
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
            $this->fetchedAtLabel = null;
            $this->stale = false;
            $this->error = null;
            $this->clearSelection();
            $this->loadSeries();

            return;
        }

        $this->refreshForecastIfNeeded();
    }

    public function updatedLocationChoice(string $choice): void
    {
        $this->locationId = $this->locationChoiceMap()[$choice] ?? null;
        $this->loadedLocationUpdatedAt = $this->location()?->updated_at?->toIso8601String();
        $this->clearSelection();
        $this->loadSeries();
    }

    public function chooseLocation(string $locationId): void
    {
        $choice = array_search($locationId, $this->locationChoiceMap(), true);

        if (! is_string($choice)) {
            return;
        }

        $this->locationChoice = $choice;
        $this->updatedLocationChoice($choice);
    }

    public function chooseMetric(string $choice): void
    {
        $this->metricChoice = $choice;
        $this->updatedMetricChoice();
    }

    public function chooseRange(string $choice): void
    {
        $this->rangeChoice = $choice;
        $this->updatedRangeChoice();
    }

    /** @return list<NavAction> */
    public function locationMenu(): array
    {
        return collect($this->locationChoiceMap())
            ->map(fn (string $locationId, string $label): NavAction => NavAction::make('explorer-location-'.$locationId)
                ->label($label)
                ->press("chooseLocation('{$locationId}')"))
            ->values()
            ->all();
    }

    /** @return list<NavAction> */
    public function metricMenu(): array
    {
        return collect($this->metricOptions)
            ->map(fn (string $option, int $index): NavAction => NavAction::make('explorer-metric-'.$index)
                ->label($option)
                ->press("chooseMetric('".addcslashes($option, "\\'")."')"))
            ->values()
            ->all();
    }

    /** @return list<NavAction> */
    public function rangeMenu(): array
    {
        return collect($this->rangeOptions)
            ->map(fn (string $option, int $index): NavAction => NavAction::make('explorer-range-'.$index)
                ->label($option)
                ->press("chooseRange('".addcslashes($option, "\\'")."')"))
            ->values()
            ->all();
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

    public function navTitle(): string
    {
        return __('ui.explorer.title');
    }

    #[Poll(300_000)]
    public function refreshForecastIfNeeded(): void
    {
        if ($this->loading) {
            return;
        }

        $location = $this->location();

        if ($location === null) {
            return;
        }

        $cached = app(ForecastService::class)->cachedForLocation($location);

        if ($cached === null || $cached->stale || $cached->data->toArray() !== $this->forecast) {
            $this->loadSeries();
        }
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
        return array_map(
            fn (WeatherMetric $metric): string => $metric->label(),
            WeatherMetric::cases(),
        );
    }

    /** @return list<string> */
    #[Computed]
    public function rangeOptions(): array
    {
        return [__('ui.explorer.hours_24'), __('ui.explorer.days_7')];
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
        return __('ui.explorer.description', [
            'metric' => $this->metricChoice,
            'location' => $this->location()?->name ?? __('ui.explorer.selected_location'),
            'period' => mb_strtolower($this->rangeChoice),
            'unit' => $this->metricUnit($this->metric()),
        ]);
    }

    #[Computed]
    public function chartLocale(): string
    {
        return app()->getLocale();
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
            'type' => $this->isDailyRange() ? 'date' : 'datetime',
            'dateFormat' => $this->isDailyRange() ? 'short' : 'time',
            'timezone' => $this->timezone,
        ];
    }

    /** @return array<string, bool|float|int|string> */
    #[Computed]
    public function chartYAxis(): array
    {
        return app(ChartAxisFactory::class)->yAxis($this->metric(), $this->series, $this->metricUnit($this->metric()));
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
        $pointsVisible = count($this->series[0]['points'] ?? []) <= 24;

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
            $this->error = __('ui.explorer.load_error');
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
            $this->error = __('ui.explorer.update_error');
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
            $this->error = __('ui.explorer.connection_error');

            return;
        }

        $location = $this->location();
        $result = $location === null ? null : app(ForecastService::class)->cachedForLocation($location);

        if ($location === null || $result === null) {
            $this->error = __('ui.explorer.missing_forecast');

            return;
        }

        $this->error = null;
        $this->applyForecast($location, $result);
    }

    private function applyForecast(Location $location, ForecastResult $result): void
    {
        $this->forecast = $result->data->toArray();
        $this->stale = $result->stale;
        $updatedAt = $result->fetchedAt->setTimezone($result->data->timezone);
        $this->fetchedAtLabel = $updatedAt->isToday()
            ? __('ui.home.today', ['time' => $updatedAt->translatedFormat('g:i a')])
            : __('ui.home.date_time', [
                'date' => $updatedAt->translatedFormat('j M'),
                'time' => $updatedAt->translatedFormat('g:i a'),
            ]);
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

        $seriesFactory = app(ChartSeriesFactory::class);
        $weatherData = WeatherData::fromArray($this->forecast);
        $series = $this->isDailyRange()
            ? $seriesFactory->forMetricByDay($location, $weatherData, $this->metric())
            : $seriesFactory->forMetric($location, $weatherData, $this->metric(), 24);
        $this->series = app(UnitPreferences::class)->series($this->metric(), $series);

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
        foreach (WeatherMetric::cases() as $metric) {
            if ($this->metricChoice === $metric->label()) {
                return $metric;
            }
        }

        return match ($this->metricChoice) {
            'Humedad', 'Humidity' => WeatherMetric::Humidity,
            'Lluvia', 'Rain', 'Precipitación', 'Precipitation' => WeatherMetric::Precipitation,
            'Viento', 'Wind' => WeatherMetric::WindSpeed,
            default => WeatherMetric::Temperature,
        };
    }

    private function metricUnit(WeatherMetric $metric): string
    {
        return app(UnitPreferences::class)->unit($metric);
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
                : $base.' · '.($location->is_default
                    ? __('ui.locations.primary')
                    : __('ui.locations.point', ['number' => $occurrences[$base]]));
            $choices[$label] = $location->id;
        }

        return $choices;
    }

    private function locationLabel(Location $location): string
    {
        return array_search($location->id, $this->locationChoiceMap(), true) ?: $location->name;
    }

    private function isDailyRange(): bool
    {
        return in_array($this->rangeChoice, ['7 días', '7 days', __('ui.explorer.days_7')], true);
    }
}
