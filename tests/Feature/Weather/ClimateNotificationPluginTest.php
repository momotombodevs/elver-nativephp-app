<?php

it('keeps the Android permission coordinator public for FragmentManager restoration', function () {
    $source = file_get_contents(base_path('packages/elver/climate-notifications/resources/android/ClimateNotificationFunctions.kt'));

    expect($source)->toContain('class NotificationPermissionCoordinator : Fragment()')
        ->toContain('fun request(event: String, id: String?)')
        ->not->toContain('fun request(request: PermissionRequest)')
        ->and($source)->not->toContain('private class NotificationPermissionCoordinator');
});

it('keeps local alert schedules and notification deep links compatible across the rename', function () {
    $android = file_get_contents(base_path('packages/elver/climate-notifications/resources/android/ClimateNotificationFunctions.kt'));
    $ios = file_get_contents(base_path('packages/elver/climate-notifications/resources/ios/ClimateNotificationFunctions.swift'));

    expect($android)->toContain('private const val PREFERENCES = "elver_climate_notifications"')
        ->toContain('private const val LEGACY_PREFERENCES = "agroclima_climate_notifications"')
        ->toContain('private const val LEGACY_WORK_NAME = "agroclima-climate-alert-refresh"')
        ->toContain('private const val CRITICAL_CHANNEL_ID = "climate_alerts_critical"')
        ->toContain('private const val RECOMMENDATION_CHANNEL_ID = "climate_recommendations"')
        ->toContain('if (key.startsWith("recommendation:"))')
        ->toContain('active && previousState != "active"')
        ->toContain('locationId = location.optString("id")')
        ->toContain('"/alerts/location/$locationId/alert/${alert.optString("id")}"')
        ->toContain('recommendationNotificationSent = evaluateRecommendations(')
        ->toContain('priorityRank(rule.optString("priority"))')
        ->toContain('private fun priorityRank(priority: String): Int')
        ->and($ios)->toContain('private static let scheduleKey = "elver.climate-alerts.schedule"')
        ->toContain('private static let legacyScheduleKey = "agroclima.climate-alerts.schedule"')
        ->toContain('"notification_url": "/alerts/location/\\(locationId)/alert/\\(alertId)"');
});
