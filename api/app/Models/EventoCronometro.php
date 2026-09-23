<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoCronometro extends Model
{
    use HasUuids;

    protected $table = 'eventos_cronometro';
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'dispositivo_id', 'tipo', 'tramo', 'timestamp_servidor',
        'tripulacion_id', 'vuelta_id', 'corrida', 'procesado',
    ];

    protected $casts = [
        'timestamp_servidor' => 'datetime:Y-m-d H:i:s.v',
        'procesado' => 'boolean',
    ];

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoCronometro::class, 'dispositivo_id');
    }

    public function tripulacion(): BelongsTo
    {
        return $this->belongsTo(Tripulacion::class);
    }

    public function vuelta(): BelongsTo
    {
        return $this->belongsTo(Vuelta::class);
    }
}
