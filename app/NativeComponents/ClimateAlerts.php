<?php

namespace App\NativeComponents;

use App\Domain\Weather\Enums\AlertState;
use App\Domain\Weather\Enums\RecommendationPriority;
use App\Domain\Weather\Enums\ThresholdOperator;
use App\Domain\Weather\Enums\WeatherMetric;
use App\Events\ClimateNotificationPermissionResult;
use App\Models\ClimateAlert;
use App\Models\Location;
use App\Services\Localization\LocalePreferences;
use App\Services\Weather\AlertEvaluator;
use App\Services\Weather\BackgroundAlertSchedule;
use App\Services\Weather\ForecastRefreshQueue;
use App\Services\Weather\ForecastService;
use App\Services\Weather\UnitPreferences;
use App\Services\Weather\WeatherRecommendationService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
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

    public string $metricChoice = '';

    public string $operatorChoice = '';

    public string $threshold = '';

    public ?string $error = null;

    public bool $showCreateSheet = false;

    public bool $fullScreenCreate = false;

    public ?bool $notificationAccess = null;

    public string $notificationAccessState = 'checking';

    public int $notificationPermissionCheckAttempts = 0;

    public ?string $notificationAccessMessage = null;

    public ?string $pendingNotificationAccessId = null;

    public bool $forecastRefreshLoading = false;

    public ?string $forecastRefreshError = null;

    public ?string $pendingForecastRefreshId = null;

    public ?string $pendingForecastLocationId = null;

    public ?string $selectedAlertId = null;

    public function mount(): void
    {
        app(LocalePreferences::class)->apply();
        $this->metricChoice = WeatherMetric::Temperature->label();
        $this->operatorChoice = __('weather.alerts.comparison_above');

        $routeLocation = $this->param('locationId');
        $routeAlert = $this->param('alertId');
        $location = is_string($routeLocation) ? Location::query()->find($routeLocation) : null;
        $location ??= Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();
        $this->selectedAlertId = is_string($routeAlert) ? $routeAlert : null;

        if ($location !== null) {
            $this->locationId = $location->id;
            $this->locationChoice = $this->locationLabel($location);
        }

        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        $this->checkNotificationAccess();
    }

    public function updatedLocationChoice(string $choice): void
    {
        $this->locationId = $this->locationChoiceMap()[$choice] ?? null;
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        app(BackgroundAlertSchedule::class)->sync();
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
    }

    public function chooseOperator(string $choice): void
    {
        $this->operatorChoice = $choice;
    }

    /** @return list<NavAction> */
    public function locationMenu(): array
    {
        return collect($this->locationChoiceMap())
            ->map(fn (string $locationId, string $label): NavAction => NavAction::make('alerts-location-'.$locationId)
                ->label($label)
                ->press("chooseLocation('{$locationId}')"))
            ->values()
            ->all();
    }

    /** @return list<NavAction> */
    public function metricMenu(): array
    {
        return collect($this->metricOptions)
            ->map(fn (string $option, int $index): NavAction => NavAction::make('alerts-metric-'.$index)
                ->label($option)
                ->press("chooseMetric('".addcslashes($option, "\\'")."')"))
            ->values()
            ->all();
    }

    /** @return list<NavAction> */
    public function operatorMenu(): array
    {
        return collect($this->operatorOptions)
            ->map(fn (string $option, int $index): NavAction => NavAction::make('alerts-operator-'.$index)
                ->label($option)
                ->press("chooseOperator('".addcslashes($option, "\\'")."')"))
            ->values()
            ->all();
    }

    public function onResume(): void
    {
        $metric = $this->metric();
        $operator = $this->operator();
        app(LocalePreferences::class)->apply();
        $this->metricChoice = $metric->label();
        $this->operatorChoice = $operator === ThresholdOperator::Below
            ? __('weather.alerts.comparison_below')
            : __('weather.alerts.comparison_above');

        $location = $this->location()
            ?? Location::query()->orderByDesc('is_default')->orderBy('sort_order')->first();
        $this->locationId = $location?->id;
        $this->locationChoice = $location === null ? '' : $this->locationLabel($location);
        $this->evaluateSelectedLocationAlerts(refreshIfStale: true);
        $this->checkNotificationAccess();
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        return TabBarOptions::make()->hidden();
    }

    public function openCreateAlert(): void
    {
        if (! $this->fullScreenCreate) {
            $this->navigate('/alerts/create');

            return;
        }

        $this->error = null;
        $this->showCreateSheet = true;
    }

    public function dismissCreateAlert(): void
    {
        $this->error = null;

        if ($this->fullScreenCreate) {
            $this->back();

            return;
        }

        $this->showCreateSheet = false;
    }

    public function chooseQuickAlert(string $choice): void
    {
        $selection = match ($choice) {
            'rain' => [WeatherMetric::Precipitation->label(), __('weather.alerts.comparison_above')],
            'heat' => [WeatherMetric::Temperature->label(), __('weather.alerts.comparison_above')],
            'cold' => [WeatherMetric::Temperature->label(), __('weather.alerts.comparison_below')],
            'wind' => [WeatherMetric::WindSpeed->label(), __('weather.alerts.comparison_above')],
            default => null,
        };

        if ($selection === null) {
            return;
        }

        [$this->metricChoice, $this->operatorChoice] = $selection;
        $this->threshold = '';
        $this->error = null;
    }

    public function createAlert(): void
    {
        $location = $this->location();

        if ($location === null) {
            $this->error = __('ui.alerts.no_location_error');

            return;
        }

        $threshold = filter_var(str_replace(',', '.', trim($this->threshold)), FILTER_VALIDATE_FLOAT);

        if ($threshold === false || ! is_finite((float) $threshold)) {
            $this->error = __('ui.alerts.invalid_number');

            return;
        }

        ClimateAlert::query()->create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'metric' => $this->metric(),
            'operator' => $this->operator(),
            'threshold' => app(UnitPreferences::class)->toBase($this->metric(), (float) $threshold),
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

        Dialog::toast(__('ui.alerts.saved'), 'short');

        if ($this->fullScreenCreate) {
            $this->back();
        }
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

        Dialog::toast($enabled ? __('ui.alerts.activated') : __('ui.alerts.paused_toast'), 'short');
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
            __('ui.alerts.delete_alert'),
            __('ui.alerts.delete_alert_message'),
            [
                ['label' => __('ui.common.cancel'), 'style' => 'cancel'],
                ['label' => __('ui.common.delete'), 'style' => 'destructive'],
            ],
        )->id(self::DELETE_ALERT_DIALOG_PREFIX.$id)->show();
    }

    #[On(ButtonPressed::class)]
    public function handleDeleteConfirmation(string $label, ?string $id = null): void
    {
        if ($label !== __('ui.common.delete') || $id === null || ! str_starts_with($id, self::DELETE_ALERT_DIALOG_PREFIX)) {
            return;
        }

        $alertId = substr($id, strlen(self::DELETE_ALERT_DIALOG_PREFIX));
        $deleted = ClimateAlert::query()->whereKey($alertId)->delete();

        if ($deleted > 0) {
            app(BackgroundAlertSchedule::class)->sync();
            Dialog::toast(__('ui.alerts.deleted'), 'short');
        }
    }

    public function requestNotificationAccess(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.RequestPermission')) {
            $this->notificationAccess = false;
            $this->notificationAccessState = 'error';
            $this->notificationAccessMessage = __('ui.alerts.notifications_unavailable');

            return;
        }

        $this->notificationAccessState = 'checking';
        $this->notificationPermissionCheckAttempts = 0;
        $this->notificationAccessMessage = null;
        $this->dispatchNotificationAccessRequest('ClimateNotifications.RequestPermission');
    }

    public function refreshNotificationAccess(): void
    {
        $this->checkNotificationAccess();
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
        $this->notificationAccessState = $granted ? 'granted' : 'denied';
        $this->notificationPermissionCheckAttempts = 0;
        $this->notificationAccessMessage = $granted
            ? null
            : __('ui.alerts.notifications_settings');
        app(BackgroundAlertSchedule::class)->sync();
    }

    private function checkNotificationAccess(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('ClimateNotifications.CheckPermission')) {
            $this->notificationAccess = false;
            $this->notificationAccessState = 'error';
            $this->notificationAccessMessage = __('ui.alerts.notifications_unavailable');

            return;
        }

        $this->notificationAccessState = 'checking';
        $this->notificationPermissionCheckAttempts = 0;
        $this->notificationAccessMessage = null;
        $this->dispatchNotificationAccessRequest('ClimateNotifications.CheckPermission');
    }

    #[Poll(5000)]
    public function expireNotificationPermissionCheck(): void
    {
        if ($this->notificationAccessState !== 'checking' || $this->pendingNotificationAccessId === null) {
            return;
        }

        $this->notificationPermissionCheckAttempts++;

        if ($this->notificationPermissionCheckAttempts < 3) {
            return;
        }

        $this->pendingNotificationAccessId = null;
        $this->notificationAccess = false;
        $this->notificationAccessState = 'error';
        $this->notificationAccessMessage = __('ui.alerts.notifications_check_error');
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
    public function operatorOptions(): array
    {
        return [
            __('weather.alerts.comparison_above'),
            __('weather.alerts.comparison_below'),
        ];
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function automaticRecommendations(): array
    {
        $location = $this->location();
        $forecast = $location === null ? null : app(ForecastService::class)->cachedForLocation($location);

        if ($forecast === null) {
            return [];
        }

        $recommendations = array_filter(
            app(WeatherRecommendationService::class)->recommendationsFor($forecast->data, $forecast->stale),
            fn ($recommendation): bool => $recommendation->priority !== RecommendationPriority::Info,
        );

        return array_map(
            fn ($recommendation): array => $recommendation->toArray(),
            array_slice(array_values($recommendations), 0, 2),
        );
    }

    public function render(): View
    {
        app(LocalePreferences::class)->apply();
        $this->finishForecastRefresh();

        $alerts = $this->locationId === null
            ? collect()
            : ClimateAlert::query()->where('location_id', $this->locationId)->latest()->get();

        return view('native.climate-alerts', [
            'automaticRecommendations' => $this->automaticRecommendations,
            'alertRows' => $alerts->map(fn (ClimateAlert $alert): array => [
                'id' => $alert->id,
                'selected' => $alert->id === $this->selectedAlertId,
                'metric' => $this->metricLabel($alert->metric),
                'operator' => $alert->operator === ThresholdOperator::Above
                    ? mb_strtolower(__('weather.alerts.comparison_above'))
                    : mb_strtolower(__('weather.alerts.comparison_below')),
                'location' => $this->location()?->name ?? __('ui.home.no_location_fallback'),
                'threshold' => app(UnitPreferences::class)->format($alert->metric, (float) $alert->threshold).' '.$this->metricUnit($alert->metric),
                'enabled' => $alert->enabled,
                'state' => $this->stateLabel($alert->last_state),
                'lastTriggered' => $alert->last_triggered_at === null
                    ? __('ui.alerts.no_triggers')
                    : __('ui.alerts.last_triggered', ['date' => $alert->last_triggered_at->translatedFormat('j M, g:i a')]),
                'isExceeded' => $alert->last_state === AlertState::Exceeded,
                'deleteActions' => [[
                    'method' => "requestDeleteAlert('{$alert->id}')",
                    'label' => __('ui.common.delete'),
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
            $this->forecastRefreshError = __('ui.alerts.review_error');
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
            $this->forecastRefreshError = __('ui.alerts.review_error_short');
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
            $this->forecastRefreshError = __('ui.alerts.refresh_error');

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

    private function operator(): ThresholdOperator
    {
        return in_array($this->operatorChoice, [
            __('weather.alerts.comparison_below'),
            'Menos de',
            'Below',
            'Menor que',
        ], true)
            ? ThresholdOperator::Below
            : ThresholdOperator::Above;
    }

    private function metricLabel(WeatherMetric $metric): string
    {
        return $metric->label();
    }

    private function metricUnit(WeatherMetric $metric): string
    {
        return app(UnitPreferences::class)->unit($metric);
    }

    private function stateLabel(?AlertState $state): string
    {
        return match ($state) {
            AlertState::Exceeded => __('ui.alerts.state_exceeded'),
            AlertState::Normal => __('ui.alerts.state_normal'),
            AlertState::NoData => __('ui.alerts.state_no_data'),
            AlertState::Stale => __('ui.alerts.state_stale'),
            null => __('ui.alerts.state_unchecked'),
        };
    }
}
