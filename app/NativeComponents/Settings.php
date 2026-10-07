<?php

namespace App\NativeComponents;

use App\Events\ClimateNotificationPermissionResult;
use App\Models\AppSetting;
use App\Models\Location;
use App\Services\Weather\BackgroundAlertSchedule;
use App\Services\Weather\UnitPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\System;

class Settings extends NativeComponent
{
    public string $defaultLocationChoice = '';

    public string $unitsChoice = 'Métrico';

    public bool $notificationsEnabled = true;

    public ?bool $notificationPermissionGranted = null;

    public ?string $pendingPermissionRequestId = null;

    public function mount(): void
    {
        $this->refreshSettings();
        $this->checkNotificationPermission();
    }

    public function onResume(): void
    {
        $this->refreshSettings();
        $this->checkNotificationPermission();
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

    public function updatedUnitsChoice(string $choice): void
    {
        app(UnitPreferences::class)->update($choice === 'Imperial' ? 'imperial' : 'metric');
    }

    public function updatedNotificationsEnabled(bool $enabled): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'notifications_enabled'],
            ['value' => $enabled ? '1' : '0'],
        );
        app(BackgroundAlertSchedule::class)->sync();
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

    #[Computed]
    public function appVersion(): string
    {
        return (string) config('nativephp.version', '1.0.0');
    }

    public function render(): View
    {
        return view('native.settings');
    }

    private function refreshSettings(): void
    {
        $default = Location::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? Location::query()->orderBy('sort_order')->first();
        $this->defaultLocationChoice = $default === null ? '' : $this->locationLabel($default);
        $this->unitsChoice = app(UnitPreferences::class)->isImperial() ? 'Imperial' : 'Métrico';
        $this->notificationsEnabled = AppSetting::query()->whereKey('notifications_enabled')->value('value') !== '0';
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
