<?php

namespace App\NativeComponents;

use App\Domain\AgroClima\Enums\AlertState;
use App\Domain\AgroClima\Enums\ThresholdOperator;
use App\Domain\AgroClima\Enums\WeatherMetric;
use App\Events\ClimateNotificationPermissionResult;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Services\AgroClima\AlertEvaluator;
use App\Services\AgroClima\BackgroundAlertSchedule;
use App\Services\AgroClima\ForecastRefreshQueue;
use App\Services\AgroClima\ForecastService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\System;
use Throwable;

#[Lazy]
class ClimateAlerts extends NativeComponent
{
    private const DELETE_ALERT_DIALOG_PREFIX = 'delete-alert:';

    public ?string $locationId = null;

    public string $locationChoice = '';

    public string $metricChoice = 'Temperatura';

    public string $operatorChoice = 'Mayor que';

    public string $threshold = '';

    public ?string $error = null;

    public bool $showCreateSheet = false;

    public ?bool $notificationAccess = null;

    public ?string $notificationAccessMessage = null;

    public ?string $pendingNotificationAccessId = null;

    public bool $forecastRefreshLoading = false;

    public ?string $forecastRefreshError = null;

    public ?string $pendingForecastRefreshId = null;

    public ?string $pendingForecastLocationId = null;

    public function mount(): void
    {
        $location = Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();

        if ($location !== null) {
            $this->locationId = $location->id;
            $this->locationChoice = $this->locationLabel($location);
        }

        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        $this->checkNotificationAccess();
        app(BackgroundAlertSchedule::class)->sync();
    }

    public function updatedLocationChoice(string $choice): void
    {
        $this->locationId = $this->locationChoiceMap()[$choice] ?? null;
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        app(BackgroundAlertSchedule::class)->sync();
    }

    public function onResume(): void
    {
        $location = $this->location()
            ?? Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();
        $this->locationId = $location?->id;
        $this->locationChoice = $location === null ? '' : $this->locationLabel($location);
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        $this->checkNotificationAccess();
        app(BackgroundAlertSchedule::class)->sync();
    }

    public function openCreateAlert(): void
    {
        $this->error = null;
        $this->showCreateSheet = true;
    }

    public function dismissCreateAlert(): void
    {
        $this->error = null;
        $this->showCreateSheet = false;
    }

    public function createAlert(): void
    {
        $location = $this->location();

        if ($location === null) {
            $this->error = 'Primero guarda una ubicación.';

            return;
        }

        $threshold = filter_var(str_replace(',', '.', trim($this->threshold)), FILTER_VALIDATE_FLOAT);

        if ($threshold === false || ! is_finite((float) $threshold)) {
            $this->error = 'Escribe un umbral numérico válido.';

            return;
        }

        ClimateAlert::query()->create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'metric' => $this->metric(),
            'operator' => $this->operator(),
            'threshold' => (float) $threshold,
            'enabled' => true,
        ]);

        $this->threshold = '';
        $this->error = null;
        $this->showCreateSheet = false;
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        app(BackgroundAlertSchedule::class)->sync();

        if ($this->notificationAccess !== true) {
            $this->requestNotificationAccess();
        }

        Dialog::toast('Alerta guardada.', 'short');
    }

    public function toggleAlert(string $id): void
    {
        $alert = ClimateAlert::query()->find($id);

        if ($alert === null) {
            return;
        }

        $this->setAlertEnabled($id, ! $alert->enabled);
    }

    public function setAlertEnabled(string $id, bool $enabled): void
    {
        $alert = ClimateAlert::query()->find($id);

        if ($alert === null) {
            return;
        }

        $alert->update([
            'enabled' => $enabled,
            ...($enabled ? ['last_state' => null, 'last_evaluated_at' => null] : []),
        ]);
        if ($enabled) {
            $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        }
        app(BackgroundAlertSchedule::class)->sync();

        if ($enabled && $this->notificationAccess !== true) {
            $this->requestNotificationAccess();
        }

        Dialog::toast($enabled ? 'Alerta activada.' : 'Alerta pausada.', 'short');
    }

    public function refreshAlerts(): void
    {
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true, forceRefresh: true);
    }

    public function requestDeleteAlert(string $id): void
    {
        if (! ClimateAlert::query()->whereKey($id)->exists()) {
            return;
        }

        Dialog::alert(
            'Eliminar alerta',
            'Esta alerta se eliminará de forma permanente.',
            [
                ['label' => 'Cancelar', 'style' => 'cancel'],
                ['label' => 'Eliminar', 'style' => 'destructive'],
            ],
        )->id(self::DELETE_ALERT_DIALOG_PREFIX.$id)->show();
    }

    #[On(ButtonPressed::class)]
    public function handleDeleteConfirmation(string $label, ?string $id = null): void
    {
        if ($label !== 'Eliminar' || $id === null || ! str_starts_with($id, self::DELETE_ALERT_DIALOG_PREFIX)) {
            return;
        }

        $alertId = substr($id, strlen(self::DELETE_ALERT_DIALOG_PREFIX));
        $deleted = ClimateAlert::query()->whereKey($alertId)->delete();

        if ($deleted > 0) {
            app(BackgroundAlertSchedule::class)->sync();
            Dialog::toast('Alerta eliminada.', 'short');
        }
    }

    public function requestNotificationAccess(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.RequestPermission')) {
            $this->notificationAccess = false;
            $this->notificationAccessMessage = 'No pudimos abrir los permisos de notificación en este dispositivo.';

            return;
        }

        $this->notificationAccessMessage = null;
        $this->dispatchNotificationAccessRequest('ClimateNotifications.RequestPermission');
    }

    public function openNotificationSettings(): void
    {
        System::appSettings();
    }

    #[On(ClimateNotificationPermissionResult::class)]
    public function notificationPermissionResult(bool $granted, ?string $id = null): void
    {
        if ($id !== $this->pendingNotificationAccessId) {
            return;
        }

        $this->pendingNotificationAccessId = null;
        $this->notificationAccess = $granted;
        $this->notificationAccessMessage = $granted
            ? null
            : 'Permite las notificaciones en Ajustes para recibir avisos con AgroClima cerrada.';
        app(BackgroundAlertSchedule::class)->sync();
    }

    private function checkNotificationAccess(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.CheckPermission')) {
            $this->notificationAccess = false;

            return;
        }

        $this->dispatchNotificationAccessRequest('ClimateNotifications.CheckPermission');
    }

    private function dispatchNotificationAccessRequest(string $function): void
    {
        $id = (string) Str::uuid();
        $this->pendingNotificationAccessId = $id;

        nativephp_call($function, json_encode([
            'event' => ClimateNotificationPermissionResult::class,
            'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    #[Computed]
    public function locationOptions(): array
    {
        return array_keys($this->locationChoiceMap());
    }

    #[Computed]
    public function thresholdUnit(): string
    {
        return $this->metricUnit($this->metric());
    }

    public function render(): View
    {
        $this->finishForecastRefresh();

        $alerts = $this->locationId === null
            ? collect()
            : ClimateAlert::query()->where('location_id', $this->locationId)->latest()->get();

        return view('native.climate-alerts', [
            'alertRows' => $alerts->map(fn (ClimateAlert $alert): array => [
                'id' => $alert->id,
                'metric' => $this->metricLabel($alert->metric),
                'operator' => $alert->operator === ThresholdOperator::Above ? 'mayor que' : 'menor que',
                'threshold' => number_format((float) $alert->threshold, 1, ',', '.').' '.$this->metricUnit($alert->metric),
                'enabled' => $alert->enabled,
                'state' => $this->stateLabel($alert->last_state),
                'deleteActions' => [[
                    'method' => "requestDeleteAlert('{$alert->id}')",
                    'label' => 'Eliminar',
                    'icon' => 'delete',
                    'role' => 'destructive',
                ]],
            ])->all(),
        ]);
    }

    private function location(): ?Location
    {
        return $this->locationId === null ? null : Location::query()->find($this->locationId);
    }

    private function evaluateSelectedLocationAlerts(bool $refreshIfStale = false, bool $forceRefresh = false): void
    {
        $location = $this->location();

        if ($this->pendingForecastLocationId !== null
            && $this->pendingForecastLocationId !== $location?->id) {
            $this->clearPendingForecastRefresh();
            $this->forecastRefreshLoading = false;
        }

        if ($location === null || ! $location->climateAlerts()->where('enabled', true)->exists()) {
            $this->clearPendingForecastRefresh();
            $this->forecastRefreshLoading = false;
            $this->forecastRefreshError = null;

            return;
        }

        $this->forecastRefreshError = null;

        try {
            $forecastService = app(ForecastService::class);
            $forecast = $forecastService->cachedForLocation($location);

            if ($forecast !== null) {
                app(AlertEvaluator::class)->evaluate($location, $forecast->data, $forecast->stale);
            }

            if ($forceRefresh || ($refreshIfStale && ($forecast === null || $forecast->stale))) {
                $this->queueForecastRefresh($location, $forceRefresh);
            } else {
                $this->forecastRefreshLoading = $this->pendingForecastLocationId === $location->id
                    && $this->pendingForecastRefreshId !== null;
            }

        } catch (Throwable $exception) {
            report($exception);
            $this->forecastRefreshError = 'No pudimos revisar el pronóstico. Inténtalo de nuevo.';
            $this->forecastRefreshLoading = false;
            $this->clearPendingForecastRefresh();
        }
    }

    private function queueForecastRefresh(Location $location, bool $force): void
    {
        if ($this->pendingForecastLocationId === $location->id
            && $this->pendingForecastRefreshId !== null
            && app(ForecastRefreshQueue::class)->status($this->pendingForecastRefreshId) === 'pending') {
            $this->forecastRefreshLoading = true;

            return;
        }

        try {
            $this->pendingForecastRefreshId = app(ForecastRefreshQueue::class)->start($location, $force);
            $this->pendingForecastLocationId = $location->id;
            $this->forecastRefreshLoading = true;
            $this->forecastRefreshError = null;
        } catch (Throwable $exception) {
            report($exception);
            $this->forecastRefreshError = 'No pudimos iniciar la revisión de alertas.';
            $this->forecastRefreshLoading = false;
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
        $this->forecastRefreshLoading = false;

        if ($locationId !== $this->locationId) {
            return;
        }

        if ($status !== 'complete') {
            $this->forecastRefreshError = 'No pudimos actualizar el pronóstico. Revisa tu conexión e inténtalo de nuevo.';

            return;
        }

        $this->evaluateSelectedLocationAlerts();
        app(BackgroundAlertSchedule::class)->sync();
    }

    private function clearPendingForecastRefresh(): void
    {
        $this->pendingForecastRefreshId = null;
        $this->pendingForecastLocationId = null;
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

    private function metric(): WeatherMetric
    {
        return match ($this->metricChoice) {
            'Humedad' => WeatherMetric::Humidity,
            'Precipitación' => WeatherMetric::Precipitation,
            'Viento' => WeatherMetric::WindSpeed,
            default => WeatherMetric::Temperature,
        };
    }

    private function operator(): ThresholdOperator
    {
        return $this->operatorChoice === 'Menor que' ? ThresholdOperator::Below : ThresholdOperator::Above;
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

    private function stateLabel(?AlertState $state): string
    {
        return match ($state) {
            AlertState::Exceeded => 'Umbral superado',
            AlertState::Normal => 'Dentro del umbral',
            AlertState::NoData => 'Sin datos',
            AlertState::Stale => 'Datos desactualizados',
            null => 'Pendiente de evaluar',
        };
    }
}
