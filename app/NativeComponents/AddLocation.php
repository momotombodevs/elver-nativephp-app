<?php

namespace App\NativeComponents;

class AddLocation extends SavedLocations
{
    public function mount(): void
    {
        parent::mount();

        $this->fullScreenAddLocation = true;
        $this->showAddLocation = true;
    }
}
