<?php

use App\Services\AgroClima\OpenMeteoCommunitySearch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('does not search until the query has two characters', function () {
    Http::preventStrayRequests();

    expect((new OpenMeteoCommunitySearch)->search('M'))->toBe([]);

    Http::assertNothingSent();
});

it('returns up to five Spanish communities with coordinates and timezone', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::response([
            'results' => [
                communitySearchResult(1, 'Masaya'),
                communitySearchResult(2, 'Nindirí'),
                communitySearchResult(3, 'Ticuantepe'),
                communitySearchResult(4, 'Catarina'),
                communitySearchResult(5, 'Nandasmo'),
                communitySearchResult(6, 'San Juan de Oriente'),
            ],
        ]),
    ]);

    $results = (new OpenMeteoCommunitySearch)->search(' Masaya ');

    expect($results)->toHaveCount(5)
        ->and($results[0])->toMatchArray([
            'id' => '1',
            'name' => 'Masaya',
            'label' => 'Masaya · Nicaragua',
            'latitude' => 11.974,
            'longitude' => -86.094,
            'timezone' => 'America/Managua',
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://geocoding-api.open-meteo.com/v1/search?name=Masaya&count=5&language=es&format=json');
});

it('returns an empty list when Open-Meteo finds no communities', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::response([]),
    ]);

    expect((new OpenMeteoCommunitySearch)->search('No existe'))->toBe([]);
});

it('skips geocoding results with invalid coordinates or timezones', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::response([
            'results' => [
                communitySearchResult(1, 'Lugar válido'),
                [...communitySearchResult(2, 'Lugar inválido'), 'latitude' => 120],
                [...communitySearchResult(3, 'Zona inválida'), 'timezone' => 'Mars/Olympus'],
            ],
        ]),
    ]);

    $results = (new OpenMeteoCommunitySearch)->search('Lugar');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Lugar válido');
});

it('surfaces a geocoding connection failure to the caller', function () {
    Http::preventStrayRequests();
    Http::fake([
        'geocoding-api.open-meteo.com/v1/search*' => Http::failedConnection(),
    ]);

    (new OpenMeteoCommunitySearch)->search('Masaya');
})->throws(ConnectionException::class);

function communitySearchResult(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'admin1' => 'Masaya',
        'admin2' => 'Masaya',
        'country' => 'Nicaragua',
        'latitude' => 11.974,
        'longitude' => -86.094,
        'timezone' => 'America/Managua',
    ];
}
