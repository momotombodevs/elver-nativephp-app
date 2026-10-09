<?php

namespace App\NativeComponents;

class CreateClimateAlert extends ClimateAlerts
{
    public function mount(): void
    {
        parent::mount();

        $this->fullScreenCreate = true;
        $this->showCreateSheet = true;
    }
}
