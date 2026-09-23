<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'timezone',
        'is_default',
        'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'UTC',
        'is_default' => false,
        'sort_order' => 0,
    ];

    /** @return HasMany<WeatherSnapshot, $this> */
    public function weatherSnapshots(): HasMany
    {
        return $this->hasMany(WeatherSnapshot::class);
    }

    /** @return HasMany<ClimateAlert, $this> */
    public function climateAlerts(): HasMany
    {
        return $this->hasMany(ClimateAlert::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
