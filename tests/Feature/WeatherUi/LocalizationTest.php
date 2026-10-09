<?php

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('renders the onboarding screen in the saved English locale', function () {
    AppSetting::query()->updateOrCreate(['key' => 'app_locale'], ['value' => 'en']);

    Native::visit('/onboarding')
        ->assertSee('WELCOME TO ELVER')
        ->assertSee('Clear weather for your places')
        ->assertSee('Not now')
        ->assertDontSee('Clima claro para tus lugares');
});

it('rerenders settings in English after the user changes the app language', function () {
    Native::fakeBridge();

    Native::visit('/settings')
        ->set('localeChoice', 'English')
        ->assertSee('Settings')
        ->assertSee('App language')
        ->assertDontSee('Weather source: Open-Meteo')
        ->assertDontSee('Fuente meteorológica: Open-Meteo');
});

it('renders the empty native screens with English navigation and copy', function () {
    AppSetting::query()->updateOrCreate(['key' => 'app_locale'], ['value' => 'en']);
    AppSetting::query()->updateOrCreate(['key' => 'onboarding_seen'], ['value' => '1']);

    Native::fakeBridge();

    Native::visit('/explorer')
        ->assertSee('Charts')
        ->assertSee('Add a location');
    Native::visit('/locations')
        ->assertSee('Locations')
        ->assertSee('Save a place to see its weather.');
    Native::visit('/alerts')
        ->assertSee('Alerts')
        ->assertSee('You can create alerts afterward.');
    Native::visit('/settings')
        ->assertSee('Settings')
        ->assertDontSee('Weather source: Open-Meteo');
});
