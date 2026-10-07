<?php

it('keeps the Android permission coordinator public for FragmentManager restoration', function () {
    $source = file_get_contents(base_path('packages/agroclima/climate-notifications/resources/android/ClimateNotificationFunctions.kt'));

    expect($source)->toContain('class NotificationPermissionCoordinator : Fragment()')
        ->toContain('fun request(event: String, id: String?)')
        ->not->toContain('fun request(request: PermissionRequest)')
        ->and($source)->not->toContain('private class NotificationPermissionCoordinator');
});
