<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComandoCronometro extends Model
{
    use HasUuids;

    protected $table = 'comandos_cronometro';
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'dispositivo_id', 'tipo', 'payload', 'estado',
        'entregado_at', 'ejecutado_at', 'creado_por_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'entregado_at' => 'datetime:Y-m-d H:i:s.v',
        'ejecutado_at' => 'datetime:Y-m-d H:i:s.v',
    ];

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoCronometro::class, 'dispositivo_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }
}
