<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DispositivoCronometro extends Model
{
    use HasUuids;

    protected $table = 'dispositivos_cronometro';

    protected $fillable = [
        'codigo', 'tipo', 'tramo', 'nombre', 'api_token',
        'ultimo_visto_at', 'ultimo_rssi', 'ultimo_voltaje_mv',
        'ultimo_uptime_sec', 'ultimo_reset_reason', 'activo',
    ];

    protected $casts = [
        'ultimo_visto_at' => 'datetime',
        'activo' => 'boolean',
    ];

    protected $hidden = ['api_token'];

    protected $appends = ['estado'];

    public function getEstadoAttribute(): string
    {
        if (!$this->ultimo_visto_at) return 'nunca';
        return $this->ultimo_visto_at->gt(now()->subSeconds(60)) ? 'online' : 'offline';
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            if (empty($d->api_token)) {
                $d->api_token = Str::random(60);
            }
        });
    }

    public function registrarHeartbeat(?int $rssi, ?int $voltajeMv, ?int $uptimeSec): void
    {
        $data = ['ultimo_visto_at' => now()];
        if ($rssi !== null) $data['ultimo_rssi'] = $rssi;
        if ($voltajeMv !== null) $data['ultimo_voltaje_mv'] = $voltajeMv;
        if ($uptimeSec !== null) $data['ultimo_uptime_sec'] = $uptimeSec;
        $this->update($data);
    }
}
