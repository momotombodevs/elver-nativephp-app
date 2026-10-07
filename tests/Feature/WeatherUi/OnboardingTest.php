<?php

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Native\Mobile\Testing\Native;

uses(LazilyRefreshDatabase::class);

it('shows onboarding before the first climate summary', function () {
    Native::visit('/')
        ->assertSee('Clima claro para tus lugares')
        ->assertSee('Agregar ubicación')
        ->assertSee('Ahora no')
        ->assertDontSee('Agrega una ubicación')
        ->assertElement('image', fn (array $node): bool => ($node['props']['src'] ?? null) === public_path('icon.png')
            && ($node['props']['alt'] ?? null) === 'Icono de Elver')
        ->assertTabBarHidden()
        ->assertMissingElement('top_bar')
        ->assertAccessible();
});

it('marks onboarding as seen when the user skips it', function () {
    Native::visit('/onboarding')
        ->assertTabBarHidden()
        ->assertMissingElement('top_bar')
        ->tap('onboarding-skip');

    expect(AppSetting::query()->where('key', 'onboarding_seen')->value('value'))->toBe('1');
});

it('opens saved locations when the user chooses to add a location', function () {
    Native::visit('/')
        ->followNavigation()
        ->tap('onboarding-add-location')
        ->assertReplacedWith('/locations')
        ->followNavigation()
        ->assertSee('Agrega una ubicación')
        ->assertSee('Usar mi ubicación');

    expect(AppSetting::query()->where('key', 'onboarding_seen')->value('value'))->toBe('1');
});

it('does not show onboarding again after it has been seen', function () {
    AppSetting::query()->create(['key' => 'onboarding_seen', 'value' => '1']);

    Native::visit('/')
        ->assertSee('Agrega una ubicación')
        ->assertDontSee('Clima claro para tus lugares');
});
