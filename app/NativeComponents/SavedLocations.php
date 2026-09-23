<?php

namespace App\NativeComponents;

use App\Domain\AgroClima\Data\Coordinates;
use App\Models\Location;
use App\Services\AgroClima\BackgroundAlertSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\Facades\System;
use Throwable;

class SavedLocations extends NativeComponent
{
    private const NEARBY_LOCATION_DIALOG_PREFIX = 'nearby-location:';

    private const MINIMUM_DUPLICATE_RADIUS_METERS = 25.0;

    public string $name = '';

    public bool $showAddLocation = false;

    public bool $locating = false;

    public string $locationPermission = 'not_determined';

    public ?string $pendingPermissionRequestId = null;

    public ?string $pendingRequestId = null;

    public ?string $error = null;

    public ?string $pendingDeleteAlertId = null;

    public ?string $pendingDeleteLocationId = null;

    public ?string $pendingNearbyLocationId = null;

    public ?float $pendingNearbyLatitude = null;

    public ?float $pendingNearbyLongitude = null;

    public ?string $pendingNearbyName = null;

    public int $locationListVersion = 0;

    public function openAddLocation(): void
    {
        $this->locationListVersion++;
        $this->showAddLocation = true;
        $this->error = null;
        $this->locationPermission = 'not_determined';
    }

    public function closeAddLocation(): void
    {
        $this->showAddLocation = false;
        $this->error = null;

        if (! $this->locating) {
            $this->name = '';
        }
    }

    public function useCurrentLocation(): void
    {
        if ($this->locating) {
            return;
        }

        $this->locating = true;
        $this->error = null;

        $request = Geolocation::requestPermissions();
        $this->pendingPermissionRequestId = $request->getId();
        $request->get();
    }

    #[On(PermissionRequestResult::class)]
    public function permissionRequestResult(
        string $location,
        string $coarseLocation,
        string $fineLocation,
        ?string $error = null,
        ?string $id = null,
    ): void {
        if ($id !== $this->pendingPermissionRequestId) {
            return;
        }

        $this->pendingPermissionRequestId = null;
        $this->locationPermission = $location;

        if ($location !== 'granted') {
            $this->locating = false;
            $this->error = $location === 'permanently_denied'
                ? 'Activa la ubicación para Elver desde Ajustes.'
                : ($error ?: 'Elver necesita permiso para usar tu ubicación.');

            return;
        }

        $request = Geolocation::getCurrentPosition(fineAccuracy: true);
        $this->pendingRequestId = $request->getId();
        $request->get();
    }

    public function openAppSettings(): void
    {
        System::appSettings();
    }

    #[On(LocationReceived::class)]
    public function locationReceived(
        bool $success,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
        ?string $error = null,
        ?string $id = null,
    ): void {
        if ($id !== $this->pendingRequestId) {
            return;
        }

        $this->locating = false;
        $this->pendingRequestId = null;

        if (! $success || $latitude === null || $longitude === null) {
            $this->error = $error ?: 'No pudimos obtener tu ubicación. Revisa el permiso.';

            return;
        }

        try {
            new Coordinates($latitude, $longitude);
        } catch (InvalidArgumentException) {
            $this->error = 'La ubicación recibida tiene coordenadas inválidas. Inténtalo de nuevo.';

            return;
        }

        $duplicate = $this->findNearbyLocation($latitude, $longitude, $accuracy);

        if ($duplicate !== null) {
            $this->pendingNearbyLocationId = $duplicate->id;
            $this->pendingNearbyLatitude = $latitude;
            $this->pendingNearbyLongitude = $longitude;
            $this->pendingNearbyName = trim($this->name) !== '' ? trim($this->name) : null;
            $this->showAddLocation = false;

            Dialog::alert(
                'Ubicación cercana',
                "Ya guardaste un punto cercano como {$duplicate->name}.",
                [
                    ['label' => 'Cancelar', 'style' => 'cancel'],
                    ['label' => 'Usar existente'],
                    ['label' => 'Actualizar ubicación'],
                ],
            )->id(self::NEARBY_LOCATION_DIALOG_PREFIX.$duplicate->id)->show();

            return;
        }

        try {
            $location = DB::transaction(function () use ($latitude, $longitude): Location {
                $isFirst = ! Location::query()->exists();

                return Location::query()->create([
                    'id' => (string) Str::uuid(),
                    'name' => trim($this->name) !== '' ? trim($this->name) : 'Mi ubicación',
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timezone' => 'UTC',
                    'is_default' => $isFirst,
                    'sort_order' => ((int) Location::query()->max('sort_order')) + 1,
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'No pudimos guardar la ubicación. Inténtalo de nuevo.';

            return;
        }

        $this->name = '';
        $this->showAddLocation = false;
        Dialog::toast("{$location->name} se guardó.", 'short');
    }

    public function makeDefault(string $id): void
    {
        $updated = DB::transaction(function () use ($id): bool {
            $location = Location::query()->find($id);

            if ($location === null) {
                return false;
            }

            Location::query()->where('is_default', true)->update(['is_default' => false]);
            $location->update(['is_default' => true]);

            return true;
        });

        if (! $updated) {
            return;
        }

        $this->error = null;
        Dialog::toast('Ubicación principal actualizada.', 'short');
    }

    public function deleteLocation(string $id): void
    {
        $location = Location::query()->find($id);

        if ($location === null) {
            return;
        }

        $this->pendingDeleteLocationId = $location->id;

        $alert = Dialog::alert(
            'Eliminar ubicación',
            $location->is_default
                ? 'Esta es tu ubicación principal. Si la eliminas, otra ubicación pasará a ser principal.'
                : "¿Quieres eliminar {$location->name}?",
            [
                ['label' => 'Cancelar', 'style' => 'cancel'],
                ['label' => 'Eliminar', 'style' => 'destructive'],
            ],
        );

        $this->pendingDeleteAlertId = $alert->getId();
        $alert->show();
    }

    #[On(ButtonPressed::class)]
    public function deleteConfirmationResult(int $index, string $label, ?string $id = null): void
    {
        if ($id !== null && str_starts_with($id, self::NEARBY_LOCATION_DIALOG_PREFIX)) {
            $this->nearbyLocationDecision($label, $id);

            return;
        }

        if ($id !== $this->pendingDeleteAlertId) {
            return;
        }

        $locationId = $this->pendingDeleteLocationId;
        $this->pendingDeleteAlertId = null;
        $this->pendingDeleteLocationId = null;

        if ($index !== 1 || $label !== 'Eliminar' || $locationId === null) {
            return;
        }

        DB::transaction(function () use ($locationId): void {
            $location = Location::query()->find($locationId);

            if ($location === null) {
                return;
            }

            $wasDefault = $location->is_default;
            $location->delete();

            if ($wasDefault) {
                Location::query()->orderBy('sort_order')->first()?->update(['is_default' => true]);
            }
        }, attempts: 3);

        app(BackgroundAlertSchedule::class)->sync();
        $this->error = null;
        Dialog::toast('Ubicación eliminada.', 'short');
    }

    private function nearbyLocationDecision(string $label, string $dialogId): void
    {
        $locationId = substr($dialogId, strlen(self::NEARBY_LOCATION_DIALOG_PREFIX));

        if ($locationId !== $this->pendingNearbyLocationId) {
            return;
        }

        $location = Location::query()->find($locationId);

        if ($location === null) {
            $this->clearPendingNearbyLocation();

            return;
        }

        if ($label === 'Usar existente') {
            DB::transaction(function () use ($location): void {
                Location::query()->where('is_default', true)->update(['is_default' => false]);
                $location->update(['is_default' => true]);
            });
            $this->name = '';
            $this->clearPendingNearbyLocation();
            Dialog::toast("Usando {$location->name}.", 'short');

            return;
        }

        if ($label === 'Actualizar ubicación'
            && $this->pendingNearbyLatitude !== null
            && $this->pendingNearbyLongitude !== null) {
            try {
                DB::transaction(function () use ($location): void {
                    $location->forceFill([
                        'name' => $this->pendingNearbyName ?? $location->name,
                        'latitude' => $this->pendingNearbyLatitude,
                        'longitude' => $this->pendingNearbyLongitude,
                    ])->save();

                    $location->weatherSnapshots()->delete();
                    $location->climateAlerts()->update([
                        'last_state' => null,
                        'last_evaluated_at' => null,
                    ]);
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->error = 'No pudimos actualizar la ubicación. Inténtalo de nuevo.';
                $this->showAddLocation = true;
                $this->clearPendingNearbyLocation();

                return;
            }

            app(BackgroundAlertSchedule::class)->sync();
            $this->name = '';
            $this->clearPendingNearbyLocation();
            Dialog::toast('Ubicación actualizada.', 'short');

            return;
        }

        $this->clearPendingNearbyLocation();
    }

    private function clearPendingNearbyLocation(): void
    {
        $this->pendingNearbyLocationId = null;
        $this->pendingNearbyLatitude = null;
        $this->pendingNearbyLongitude = null;
        $this->pendingNearbyName = null;
    }

    private function findNearbyLocation(float $latitude, float $longitude, ?float $accuracy): ?Location
    {
        $radiusMeters = max(self::MINIMUM_DUPLICATE_RADIUS_METERS, max(0.0, $accuracy ?? 0.0));

        return Location::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn (Location $location): bool => $this->distanceInMeters(
                $latitude,
                $longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            ) <= $radiusMeters);
    }

    private function distanceInMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): float {
        $earthRadiusMeters = 6_371_000.0;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians) * sin($longitudeDelta / 2) ** 2;

        $haversine = max(0.0, min(1.0, $haversine));

        return $earthRadiusMeters * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }

    public function render(): View
    {
        $locations = Location::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get();

        return view('native.saved-locations', [
            'locations' => $locations,
            'deleteActions' => $locations->mapWithKeys(fn (Location $location): array => [
                $location->id => [[
                    'method' => "deleteLocation('{$location->id}')",
                    'label' => 'Eliminar',
                    'icon' => 'delete',
                    'ios' => 'trash',
                    'android' => 'delete',
                    'role' => 'destructive',
                ]],
            ])->all(),
        ]);
    }
}
