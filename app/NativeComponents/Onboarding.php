<?php

namespace App\NativeComponents;

use App\Models\AppSetting;
use App\Services\Localization\LocalePreferences;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Onboarding extends NativeComponent
{
    public function mount(): void
    {
        app(LocalePreferences::class)->apply();
    }

    public function startAddingLocation(): void
    {
        $this->markAsSeen();
        $this->replace('/locations/add');
    }

    public function skip(): void
    {
        $this->markAsSeen();
        $this->replace('/');
    }

    public function render(): View
    {
        return view('native.onboarding');
    }

    private function markAsSeen(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'onboarding_seen'],
            ['value' => '1'],
        );
    }
}
