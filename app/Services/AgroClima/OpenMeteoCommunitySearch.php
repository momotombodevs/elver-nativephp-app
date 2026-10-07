<?php

namespace App\Services\AgroClima;

use App\Domain\AgroClima\Data\Coordinates;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class OpenMeteoCommunitySearch
{
    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     label: string,
     *     latitude: float,
     *     longitude: float,
     *     timezone: string
     * }>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        if (mb_strlen($query) > 100) {
            throw new InvalidArgumentException('Community searches cannot exceed 100 characters.');
        }

        $payload = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(5)
            ->get((string) config('services.open_meteo.geocoding_url'), [
                'name' => $query,
                'count' => 5,
                'language' => 'es',
                'format' => 'json',
            ])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Open-Meteo returned an invalid geocoding response.');
        }

        $results = $payload['results'] ?? [];
        if (! is_array($results) || ! array_is_list($results)) {
            throw new InvalidArgumentException('Open-Meteo returned invalid geocoding results.');
        }

        $communities = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $community = $this->mapResult($result);
            if ($community !== null) {
                $communities[] = $community;
            }

            if (count($communities) === 5) {
                break;
            }
        }

        return $communities;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{id: string, name: string, label: string, latitude: float, longitude: float, timezone: string}|null
     */
    private function mapResult(array $result): ?array
    {
        $id = $result['id'] ?? null;
        $name = $result['name'] ?? null;
        $latitude = $result['latitude'] ?? null;
        $longitude = $result['longitude'] ?? null;
        $timezone = $result['timezone'] ?? null;

        if (! is_int($id) || $id < 1 || ! is_string($name) || trim($name) === ''
            || ! is_numeric($latitude) || ! is_numeric($longitude) || ! is_string($timezone) || trim($timezone) === '') {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        try {
            new Coordinates($latitude, $longitude);
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            return null;
        }

        $name = trim($name);
        $context = array_values(array_unique(array_filter([
            $this->optionalLabel($result['admin2'] ?? null),
            $this->optionalLabel($result['admin1'] ?? null),
            $this->optionalLabel($result['country'] ?? null),
        ], fn (?string $value): bool => $value !== null && mb_strtolower($value) !== mb_strtolower($name))));

        return [
            'id' => (string) $id,
            'name' => $name,
            'label' => $name.($context === [] ? '' : ' · '.implode(', ', $context)),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => $timezone,
        ];
    }

    private function optionalLabel(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
