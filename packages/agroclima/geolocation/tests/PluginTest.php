<?php

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(
        file_get_contents($this->pluginPath.'/nativephp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

it('declares the core NativePHP geolocation bridge contract', function () {
    $functions = collect($this->manifest['bridge_functions'])->keyBy('name');

    expect($functions->keys()->all())->toBe([
        'Geolocation.GetCurrentPosition',
        'Geolocation.CheckPermissions',
        'Geolocation.RequestPermissions',
    ])->and($functions['Geolocation.GetCurrentPosition']['android'])
        ->toBe('com.agroclima.plugins.geolocation.AgroClimaGeolocationFunctions.GetCurrentPosition')
        ->and($functions['Geolocation.GetCurrentPosition']['ios'])
        ->toBe('AgroClimaGeolocationFunctions.GetCurrentPosition');
});

it('declares only foreground location permissions', function () {
    expect($this->manifest['android']['min_version'])->toBe(26)
        ->and($this->manifest['android']['permissions'])->toBe([
            'android.permission.ACCESS_COARSE_LOCATION',
            'android.permission.ACCESS_FINE_LOCATION',
        ])
        ->and($this->manifest['ios']['min_version'])->toBe('18.2')
        ->and($this->manifest['ios']['info_plist']['NSLocationWhenInUseUsageDescription'])
        ->toContain('Elver')
        ->and($this->manifest['android']['permissions'])
        ->not->toContain('android.permission.ACCESS_BACKGROUND_LOCATION')
        ->and($this->manifest['ios']['info_plist'])
        ->not->toHaveKey('NSLocationAlwaysAndWhenInUseUsageDescription');
});

it('dispatches the core geolocation events', function () {
    expect($this->manifest['events'])->toBe([
        'Native\\Mobile\\Events\\Geolocation\\LocationReceived',
        'Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived',
        'Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult',
    ]);
});

it('contains real Android permission and one-shot location handling', function () {
    $source = file_get_contents(
        $this->pluginPath.'/resources/android/AgroClimaGeolocationFunctions.kt',
    );

    expect($source)->toContain('package com.agroclima.plugins.geolocation')
        ->toContain('class GetCurrentPosition')
        ->toContain('class CheckPermissions')
        ->toContain('class RequestPermissions')
        ->toContain('registerForActivityResult')
        ->toContain('RequestMultiplePermissions')
        ->toContain('requestLocationUpdates')
        ->toContain('NativeActionCoordinator.dispatchEvent')
        ->toContain('"timestamp", location.time')
        ->not->toContain('TODO');
});

it('contains real iOS permission and one-shot location handling', function () {
    $source = file_get_contents(
        $this->pluginPath.'/resources/ios/AgroClimaGeolocationFunctions.swift',
    );

    expect($source)->toContain('import CoreLocation')
        ->toContain('final class GetCurrentPosition')
        ->toContain('final class CheckPermissions')
        ->toContain('final class RequestPermissions')
        ->toContain('requestWhenInUseAuthorization')
        ->toContain('manager.requestLocation()')
        ->toContain('LaravelBridge.shared.send?')
        ->toContain('timeIntervalSince1970 * 1_000')
        ->not->toContain('TODO');
});

it('requires NativePHP Mobile v4 and exposes no competing facade', function () {
    $composer = json_decode(
        file_get_contents($this->pluginPath.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['type'])->toBe('nativephp-plugin')
        ->and($composer['require']['nativephp/mobile'])->toBe('^4.0')
        ->and(file_exists($this->pluginPath.'/src/Facades/AgroClimaGeolocation.php'))->toBeFalse()
        ->and(file_exists($this->pluginPath.'/resources/js/agroClimaGeolocation.js'))->toBeFalse();
});
