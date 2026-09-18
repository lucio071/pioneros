export function formatFecha(fecha: string | null | undefined): string {
  if (!fecha) return ''
  // Handle both "2026-02-22" and "2026-02-22T00:00:00.000000Z"
  const dateStr = fecha.substring(0, 10)
  const d = new Date(dateStr + 'T12:00:00')
  if (isNaN(d.getTime())) return fecha
  return d.toLocaleDateString('es-PY', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function formatTiempo(ms: number | null | undefined): string {
  if (ms == null) return '--:--:--'
  const totalSec = Math.floor(ms / 1000)
  const min = Math.floor(totalSec / 60)
  const sec = totalSec % 60
  const centesimas = Math.floor((ms % 1000) / 10)
  return `${min.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}:${centesimas.toString().padStart(2, '0')}`
}

export function formatDiferencia(ms: number | null | undefined): string {
  if (!ms) return ''
  return `+${formatTiempo(ms)}`
}

export function formatPenales(estacas: number, cintas: number): string {
  const parts: string[] = []
  if (estacas) parts.push(`${estacas}E`)
  if (cintas) parts.push(`${cintas}C`)
  return parts.length ? parts.join(' ') : '-'
}

export function estadoLabel(estado: string): string {
  const map: Record<string, string> = {
    inscripta: 'Inscripta',
    en_pista: 'En pista',
    completada: 'Completada',
    nula: 'DNF',
    borrador: 'Borrador',
    configurada: 'Configurada',
    activa: 'Activa',
    finalizada: 'Finalizada',
    activo: 'Activo',
    finalizado: 'Finalizado',
  }
  return map[estado] || estado
}

export function estadoColor(estado: string): string {
  const map: Record<string, string> = {
    inscripta: 'bg-gray-100 text-gray-700',
    en_pista: 'bg-yellow-100 text-yellow-800',
    completada: 'bg-green-100 text-green-800',
    nula: 'bg-red-100 text-red-800',
    borrador: 'bg-gray-100 text-gray-600',
    configurada: 'bg-blue-100 text-blue-700',
    activa: 'bg-green-100 text-green-800',
    finalizada: 'bg-purple-100 text-purple-800',
    activo: 'bg-green-100 text-green-800',
    finalizado: 'bg-purple-100 text-purple-800',
  }
  return map[estado] || 'bg-gray-100 text-gray-700'
}

export function faseLabel(fase: string): string {
  const map: Record<string, string> = {
    clasificacion: 'Clasificacion',
    final: 'Final',
    finalizada: 'Finalizada',
  }
  return map[fase] || fase
}

export function faseColor(fase: string): string {
  const map: Record<string, string> = {
    clasificacion: 'bg-blue-100 text-blue-800',
    final: 'bg-orange-100 text-orange-800',
    finalizada: 'bg-green-100 text-green-800',
  }
  return map[fase] || 'bg-gray-100 text-gray-700'
}
