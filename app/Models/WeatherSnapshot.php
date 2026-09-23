<?php

namespace App\Models;

use Database\Factories\WeatherSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherSnapshot extends Model
{
    /** @use HasFactory<WeatherSnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'location_id',
        'provider',
        'schema_version',
        'payload',
        'fetched_at',
        'expires_at',
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'payload' => 'array',
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
