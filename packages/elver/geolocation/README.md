# Elver Geolocation

Local NativePHP Mobile v4 plugin that implements the foreground, one-shot part
of NativePHP's built-in geolocation API for Elver. It requests only
while-in-use location access and does not provide background tracking.

## Usage

Register the package as a NativePHP plugin, then use the core facade and events:

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Facades\Geolocation;

Geolocation::getCurrentPosition(fineAccuracy: true)
    ->id('saved-location')
    ->get();

#[On(LocationReceived::class)]
public function locationReceived(
    bool $success,
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $error = null,
    ?string $id = null,
): void {
    // Persist or display the result.
}
```

The plugin implements these bridge calls:

- `Geolocation.GetCurrentPosition`
- `Geolocation.CheckPermissions`
- `Geolocation.RequestPermissions`

Native source changes require rebuilding the iOS or Android app.
