<?php

namespace App\NativeComponents;

use App\Events\ClimateNotificationPermissionResult;
use App\Models\AppSetting;
use App\Models\Location;
use App\Services\Localization\LocalePreferences;
use App\Services\Weather\BackgroundAlertSchedule;
use App\Services\Weather\UnitPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\System;

class Settings extends NativeComponent
{
    public string $defaultLocationChoice = '';

    public string $unitsChoice = '°C';

    public string $appearanceMode = '';

    public string $localeChoice = '';

    public bool $notificationsEnabled = true;

    public bool $notificationCritical = true;

    public bool $notificationRecommendations = true;

    public bool $notificationRapidChanges = true;

    public bool $notificationDailySummary = false;

    public ?bool $notificationPermissionGranted = null;

    public ?string $pendingPermissionRequestId = null;

    public function mount(): void
    {
        app(LocalePreferences::class)->apply();
        $this->refreshSettings();
        $this->checkNotificationPermission();
        $this->applyAppearance();
    }

    public function onResume(): void
    {
        app(LocalePreferences::class)->apply();
        $this->refreshSettings();
        $this->checkNotificationPermission();
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        return TabBarOptions::make()->hidden();
    }

    public function updatedDefaultLocationChoice(string $choice): void
    {
        $locationId = $this->locationChoiceMap()[$choice] ?? null;

        if ($locationId === null) {
            return;
        }

        DB::transaction(function () use ($locationId): void {
            Location::query()->where('is_default', true)->update(['is_default' => false]);
            Location::query()->whereKey($locationId)->update(['is_default' => true]);
        });
    }

    public function chooseDefaultLocation(string $locationId): void
    {
        $choice = array_search($locationId, $this->locationChoiceMap(), true);

        if (! is_string($choice)) {
            return;
        }

        $this->defaultLocationChoice = $choice;
        $this->updatedDefaultLocationChoice($choice);
    }

    public function chooseUnits(string $choice): void
    {
        $this->unitsChoice = $choice;
        $this->updatedUnitsChoice($choice);
    }

    public function chooseAppearance(string $mode): void
    {
        $this->appearanceMode = $this->appearanceLabel($mode);
        $this->updatedAppearanceMode($mode);
    }

    public function chooseLocale(string $locale): void
    {
        $this->localeChoice = $locale === LocalePreferences::ENGLISH
            ? __('ui.settings.english')
            : __('ui.settings.spanish_nicaragua');
        $this->updatedLocaleChoice($this->localeChoice);
    }

    /** @return list<NavAction> */
    public function appearanceMenu(): array
    {
        return [
            NavAction::make('settings-appearance-system')->label(__('ui.settings.system'))->press("chooseAppearance('system')"),
            NavAction::make('settings-appearance-light')->label(__('ui.settings.light'))->press("chooseAppearance('light')"),
            NavAction::make('settings-appearance-dark')->label(__('ui.settings.dark'))->press("chooseAppearance('dark')"),
        ];
    }

    /** @return list<NavAction> */
    public function localeMenu(): array
    {
        return [
            NavAction::make('settings-locale-es')->label(__('ui.settings.spanish_nicaragua'))->press("chooseLocale('".LocalePreferences::SPANISH."')"),
            NavAction::make('settings-locale-en')->label(__('ui.settings.english'))->press("chooseLocale('".LocalePreferences::ENGLISH."')"),
        ];
    }

    /** @return list<NavAction> */
    public function locationMenu(): array
    {
        return collect($this->locationChoiceMap())
            ->map(fn (string $locationId, string $label): NavAction => NavAction::make('settings-location-'.$locationId)
                ->label($label)
                ->press("chooseDefaultLocation('{$locationId}')"))
            ->values()
            ->all();
    }

    /** @return list<NavAction> */
    public function unitsMenu(): array
    {
        return [
            NavAction::make('settings-units-celsius')->label('°C')->press("chooseUnits('°C')"),
            NavAction::make('settings-units-fahrenheit')->label('°F')->press("chooseUnits('°F')"),
        ];
    }

    public function updatedUnitsChoice(string $choice): void
    {
        app(UnitPreferences::class)->update(in_array($choice, ['°F', 'Imperial'], true) ? 'imperial' : 'metric');
    }

    public function updatedNotificationsEnabled(bool $enabled): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'notifications_enabled'],
            ['value' => $enabled ? '1' : '0'],
        );
        app(BackgroundAlertSchedule::class)->sync();
    }

    public function updatedAppearanceMode(string $mode): void
    {
        $mode = match ($mode) {
            __('ui.settings.light'), 'light' => 'light',
            __('ui.settings.dark'), 'dark' => 'dark',
            default => 'system',
        };
        $this->appearanceMode = $this->appearanceLabel($mode);
        $this->saveSetting('appearance_mode', $mode);
        $this->applyAppearance();
    }

    public function updatedLocaleChoice(string $choice): void
    {
        app(LocalePreferences::class)->update($choice === __('ui.settings.english')
            ? LocalePreferences::ENGLISH
            : LocalePreferences::SPANISH);
        app(BackgroundAlertSchedule::class)->sync();
    }

    public function updatedNotificationCritical(bool $enabled): void
    {
        $this->saveNotificationPreference('notification_critical', $enabled);
    }

    public function updatedNotificationRecommendations(bool $enabled): void
    {
        $this->saveNotificationPreference('notification_recommendations', $enabled);
    }

    public function updatedNotificationRapidChanges(bool $enabled): void
    {
        $this->saveNotificationPreference('notification_rapid_changes', $enabled);
    }

    public function updatedNotificationDailySummary(bool $enabled): void
    {
        $this->saveNotificationPreference('notification_daily_summary', $enabled);
    }

    public function requestNotificationPermission(): void
    {
        $this->dispatchPermissionRequest('ClimateNotifications.RequestPermission');
    }

    public function openNotificationSettings(): void
    {
        System::appSettings();
    }

    #[On(ClimateNotificationPermissionResult::class)]
    public function notificationPermissionResult(bool $granted, ?string $id = null): void
    {
        if ($id !== $this->pendingPermissionRequestId) {
            return;
        }

        $this->pendingPermissionRequestId = null;
        $this->notificationPermissionGranted = $granted;
        app(BackgroundAlertSchedule::class)->sync();
    }

    /** @return list<string> */
    #[Computed]
    public function locationOptions(): array
    {
        return array_keys($this->locationChoiceMap());
    }

    public function render(): View
    {
        app(LocalePreferences::class)->apply();

        return view('native.settings');
    }

    private function refreshSettings(): void
    {
        $default = Location::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? Location::query()->orderBy('sort_order')->first();
        $this->defaultLocationChoice = $default === null ? '' : $this->locationLabel($default);
        $this->unitsChoice = app(UnitPreferences::class)->isImperial() ? '°F' : '°C';
        $this->notificationsEnabled = AppSetting::query()->whereKey('notifications_enabled')->value('value') !== '0';
        $this->appearanceMode = $this->appearanceLabel((string) AppSetting::query()->whereKey('appearance_mode')->value('value'));
        $this->localeChoice = app(LocalePreferences::class)->preference() === LocalePreferences::ENGLISH
            ? __('ui.settings.english')
            : __('ui.settings.spanish_nicaragua');
        $this->notificationCritical = $this->notificationPreference('notification_critical', true);
        $this->notificationRecommendations = $this->notificationPreference('notification_recommendations', true);
        $this->notificationRapidChanges = $this->notificationPreference('notification_rapid_changes', true);
        $this->notificationDailySummary = $this->notificationPreference('notification_daily_summary', false);
    }

    private function checkNotificationPermission(): void
    {
        $this->dispatchPermissionRequest('ClimateNotifications.CheckPermission');
    }

    private function dispatchPermissionRequest(string $function): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can($function)) {
            $this->notificationPermissionGranted = false;

            return;
        }

        $id = (string) Str::uuid();
        $this->pendingPermissionRequestId = $id;

        nativephp_call($function, json_encode([
            'event' => ClimateNotificationPermissionResult::class,
            'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    private function applyAppearance(): void
    {
        if (! function_exists('nativephp_call')
            || ! function_exists('nativephp_can')
            || ! nativephp_can('Appearance.Set')) {
            return;
        }

        nativephp_call('Appearance.Set', json_encode([
            'mode' => match ($this->appearanceMode) {
                'Claro', 'light' => 'light',
                'Oscuro', 'dark' => 'dark',
                default => 'system',
            },
        ], JSON_THROW_ON_ERROR));
    }

    private function appearanceLabel(string $mode): string
    {
        return match ($mode) {
            'light' => __('ui.settings.light'),
            'dark' => __('ui.settings.dark'),
            default => __('ui.settings.system'),
        };
    }

    private function saveSetting(string $key, string $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function saveNotificationPreference(string $key, bool $enabled): void
    {
        $this->saveSetting($key, $enabled ? '1' : '0');
        app(BackgroundAlertSchedule::class)->sync();
    }

    private function notificationPreference(string $key, bool $default): bool
    {
        $value = AppSetting::query()->whereKey($key)->value('value');

        return $value === null ? $default : $value !== '0';
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
}
