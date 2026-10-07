<?php

namespace App\NativeComponents;

use App\Models\AppSetting;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Onboarding extends NativeComponent
{
    public function startAddingLocation(): void
    {
        $this->markAsSeen();
        $this->replace('/locations');
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
