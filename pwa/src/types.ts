export interface CategoriaCatalogo {
  id: string
  nombre: string
  slug: string
  orden: number
}

export interface FechaCategoriaResumen {
  id: string
  nombre: string
  slug: string
  fase: 'clasificacion' | 'final' | 'finalizada'
  penal_estaca_seg: number
  penal_cinta_seg: number
  tripulaciones_count?: number
}

export interface Fecha {
  id: string
  nombre: string
  fecha: string
  tipo_pista: 'simple' | 'doble'
  vueltas_clasificacion: number
  tiene_final: boolean
  finalistas_top: number | null
  estado: 'borrador' | 'configurada' | 'activa' | 'finalizada'
  version: number
  tripulaciones_count: number
  categorias: FechaCategoriaResumen[]
  campeonato_id?: string | null
  campeonato?: Campeonato | null
}

export interface Campeonato {
  id: string
  nombre: string
  anio: number
  estado: 'borrador' | 'activo' | 'finalizado'
  tipo_pista_forzado?: string | null
  fechas_count?: number
  pilotos_count?: number
}

export interface CampeonatoPuntos {
  id: string
  posicion: number
  puntos: number
}

export interface Piloto {
  id: string
  campeonato_id: string
  nombre: string
  inscripciones?: InscripcionCampeonato[]
}

export interface InscripcionCampeonato {
  id: string
  campeonato_id: string
  piloto_id: string
  categoria_id: string
  numero: string
  nombre_tripulacion: string | null
  piloto?: Piloto
  categoria?: CategoriaCatalogo
}

export interface CampeonatoRankingEntry {
  inscripcion_id: string
  piloto_id: string
  piloto_nombre: string
  numero: string
  nombre_tripulacion: string | null
  puntos_total: number
  posicion: number
  fechas_detalle: {
    fecha_id: string
    fecha_nombre: string
    posicion: number | null
    puntos: number
  }[]
}

export interface TiempoMuerto {
  id: string
  segundos: number
  comentario: string | null
}

export interface TramoDetalle {
  id: string
  letra: 'A' | 'B'
  tiempo_ms: number | null
  estacas: number
  cintas: number
  tiempos_muertos: TiempoMuerto[]
  total_trancas_seg: number
  tiempo_con_penal: number | null
}

export interface VueltaDetalle {
  id: string
  numero_vuelta: number
  fase: 'clasificacion' | 'final'
  nula: boolean
  confirmada: boolean
  tramos: TramoDetalle[]
  total_vuelta: number | null
}

export interface TripulacionRanking {
  id: string
  numero: string
  nombre: string
  piloto: string
  copiloto: string
  estado: string
  orden_largada: number | null
  vueltas: VueltaDetalle[]
  mejor_vuelta_ms: number | null
  mejor_vuelta_numero: number | null
  vuelta_final: VueltaDetalle | null
  dnf: boolean
  posicion?: number
  diferencia?: number
}

export interface FinalRankingEntry {
  id: string
  numero: string
  nombre: string
  piloto: string
  posicion: number
  vuelta_final: VueltaDetalle
  diferencia: number
}

export interface RankingStats {
  total: number
  en_pista: number
  completadas: number
  nulas: number
  fase: 'clasificacion' | 'final' | 'finalizada'
}

export interface RankingData {
  fecha: Fecha
  categoria: {
    id: string
    nombre: string
    penal_estaca_seg: number
    penal_cinta_seg: number
    fase: string
  }
  ranking: TripulacionRanking[]
  en_curso: TripulacionRanking[]
  dnf: TripulacionRanking[]
  final_ranking: FinalRankingEntry[] | null
  stats: RankingStats
}
