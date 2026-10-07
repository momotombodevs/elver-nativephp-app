<?php

namespace App\NativeComponents;

use App\Domain\AgroClima\Data\ForecastResult;
use App\Domain\AgroClima\Data\WeatherData;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Models\AppSetting;
use App\Models\Location;
use App\Services\AgroClima\AlertEvaluator;
use App\Services\AgroClima\BackgroundAlertSchedule;
use App\Services\AgroClima\ChartAxisFactory;
use App\Services\AgroClima\ChartSeriesFactory;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use App\Services\AgroClima\RainSummaryFactory;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
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

    public string $provider = 'open-meteo';

    public ?string $pendingForecastRefreshId = null;

    public ?string $pendingForecastLocationId = null;

    public function mount(): void
    {
        if (AppSetting::query()->whereKey('onboarding_seen')->value('value') !== '1') {
            $this->replace('/onboarding');

            return;
        }

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

        $this->refreshForecastIfNeeded();
    }

    public function refreshForecast(): void
    {
        $this->loadForecast(force: true);
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
            $this->loadForecast();
        }
    }

    public function startAddingLocation(): void
    {
        $this->markOnboardingAsSeen();
        $this->replace('/locations');
    }

    public function skipOnboarding(): void
    {
        $this->markOnboardingAsSeen();
        $this->replace('/');
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        return AppSetting::query()->whereKey('onboarding_seen')->value('value') === '1'
            ? null
            : TabBarOptions::make()->hidden();
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

    /** @return array{total: float|null, reportedHours: int, expectedHours: int, partial: bool, noData: bool, hasRain: bool, periods: list<string>} */
    #[Computed]
    public function next24HoursRain(): array
    {
        if ($this->forecast === []) {
            return [
                'total' => null,
                'reportedHours' => 0,
                'expectedHours' => 24,
                'partial' => true,
                'noData' => true,
                'hasRain' => false,
                'periods' => [],
            ];
        }

        return app(RainSummaryFactory::class)->forNext24Hours(WeatherData::fromArray($this->forecast));
    }

    /** @return list<array{label: string, value: string, unit: string}> */
    #[Computed]
    public function currentWeatherDetails(): array
    {
        $details = $this->forecast['current_details'] ?? [];

        return [
            [
                'label' => 'Se siente como',
                'value' => $this->formattedDetail($details['apparent_temperature'] ?? null),
                'unit' => '°C',
            ],
            [
                'label' => 'Índice UV',
                'value' => $this->formattedDetail($details['uv_index'] ?? null, 1),
                'unit' => '',
            ],
            [
                'label' => 'Viento',
                'value' => $this->windDirection($details['wind_direction_10m'] ?? null),
                'unit' => '',
            ],
        ];
    }

    /** @return list<array{date: string, maximum: string, minimum: string, uv: string}> */
    #[Computed]
    public function dailyForecast(): array
    {
        return array_map(function (array $day): array {
            $date = isset($day['date']) && is_string($day['date'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', $day['date'])
                : null;

            return [
                'date' => $date?->translatedFormat('D j M') ?? '—',
                'maximum' => $this->formattedDetail($day['maximum_temperature'] ?? null, 0).' °C',
                'minimum' => $this->formattedDetail($day['minimum_temperature'] ?? null, 0).' °C',
                'uv' => 'UV '.$this->formattedDetail($day['maximum_uv_index'] ?? null, 1),
            ];
        }, array_slice($this->forecast['daily'] ?? [], 0, 5));
    }

    #[Computed]
    public function providerLabel(): string
    {
        return match ($this->provider) {
            'open-meteo' => 'Open-Meteo',
            default => $this->provider,
        };
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

        if (AppSetting::query()->whereKey('onboarding_seen')->value('value') !== '1') {
            return view('native.onboarding', ['skipAction' => 'skipOnboarding']);
        }

        return view('native.home');
    }

    private function markOnboardingAsSeen(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'onboarding_seen'],
            ['value' => '1'],
        );
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
            $this->error = 'No se pudo cargar el clima. Revisa tu conexión.';
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
            $this->error = 'No se pudo iniciar. Intenta de nuevo.';
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
            $this->error = 'No se pudo cargar el clima. Revisa tu conexión.';
            app(BackgroundAlertSchedule::class)->sync();

            return;
        }

        $location = $this->location();
        $result = $location === null ? null : app(ForecastService::class)->cachedForLocation($location);

        if ($location === null || $result === null) {
            $this->error = 'No hay pronóstico guardado. Intenta de nuevo.';
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
        $this->provider = $result->provider;
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
            WeatherMetric::Precipitation => 'Lluvia',
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

    private function formattedDetail(mixed $value, int $decimals = 0): string
    {
        if (! is_int($value) && ! is_float($value)) {
            return '—';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    private function windDirection(mixed $degrees): string
    {
        if (! is_int($degrees) && ! is_float($degrees)) {
            return '—';
        }

        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        $index = (int) round((((float) $degrees) % 360) / 45) % count($directions);

        return $directions[$index];
    }
}
