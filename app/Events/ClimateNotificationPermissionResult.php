<?php

namespace App\Events;

class ClimateNotificationPermissionResult
{
    public function __construct(
        public readonly bool $granted,
        public readonly ?string $id = null,
    ) {}
}
