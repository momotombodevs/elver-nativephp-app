## Elver Geolocation

Foreground-only geolocation bridge for the Elver NativePHP Mobile v4 app.

### Installation

```bash
composer require agroclima/geolocation
```

### PHP Usage

Use NativePHP's core facade and native-UI event attribute:

@verbatim
<code-snippet name="Requesting an Elver location" lang="php">
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Facades\Geolocation;

Geolocation::getCurrentPosition(fineAccuracy: true)->id('saved-location')->get();

#[On(LocationReceived::class)]
public function locationReceived(bool $success, ?float $latitude = null, ?float $longitude = null): void
{
    // Handle the one-shot result.
}
</code-snippet>
@endverbatim

### Available Methods

- `Geolocation::getCurrentPosition()`: Request one foreground location fix.
- `Geolocation::checkPermissions()`: Read the current permission state.
- `Geolocation::requestPermissions()`: Ask for while-in-use permission.

### Events

- `Native\Mobile\Events\Geolocation\LocationReceived`
- `Native\Mobile\Events\Geolocation\PermissionStatusReceived`
- `Native\Mobile\Events\Geolocation\PermissionRequestResult`

This local plugin intentionally exposes no separate PHP facade or JavaScript
wrapper; the API contract belongs to `nativephp/mobile`.
