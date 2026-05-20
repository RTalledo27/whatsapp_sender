import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface ClientStatsTotals {
  total_consultas: number;
  unique_clients: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface ClientStatsByMonth {
  month: string;
  total: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface ClientStatsByPhone {
  telefono_origen: string;
  total: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface ClientStatsByPhoneMonth {
  telefono_origen: string;
  month: string;
  total: number;
}

export interface ClientStats {
  totals: ClientStatsTotals;
  by_month: ClientStatsByMonth[];
  by_phone: ClientStatsByPhone[];
  by_phone_month: ClientStatsByPhoneMonth[];
}

export interface ClientDetail {
  dni: string;
  telefono_origen: string | null;
  resultado: 'apto' | 'no_apto' | 'no_encontrado';
  fecha_consulta: string;
}

export interface CommerceStatsTotals {
  total_consumos: number;
  comercios_con_consumo: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface CommerceStatsByMonth {
  month: string;
  total: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface CommerceStatsByComercio {
  comercio_id: number;
  comercio_nombre: string;
  total: number;
  apto: number;
  no_apto: number;
  no_encontrado: number;
}

export interface CommerceStats {
  totals: CommerceStatsTotals;
  by_month: CommerceStatsByMonth[];
  by_comercio: CommerceStatsByComercio[];
}

export interface CommerceDetail {
  dni: string;
  resultado: 'apto' | 'no_apto' | 'no_encontrado';
  fecha_consulta: string;
  comercio_id: number | null;
  comercio_nombre: string | null;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: PaginationMeta;
}

@Injectable({
  providedIn: 'root'
})
export class ClubStatsService {
  private apiUrl = environment.apiUrl || 'http://localhost:8000/api';

  constructor(private http: HttpClient) {}

  getClientStats(params?: Record<string, string | number | boolean | null | undefined>): Observable<ClientStats> {
    return this.http.get<ClientStats>(`${this.apiUrl}/chatbot/metrics/clients`, {
      params: this.buildParams(params)
    });
  }

  getClientDetails(params?: Record<string, string | number | boolean | null | undefined>): Observable<PaginatedResponse<ClientDetail>> {
    return this.http.get<PaginatedResponse<ClientDetail>>(`${this.apiUrl}/chatbot/metrics/clients/detail`, {
      params: this.buildParams(params)
    });
  }

  getCommerceStats(params?: Record<string, string | number | boolean | null | undefined>): Observable<CommerceStats> {
    return this.http.get<CommerceStats>(`${this.apiUrl}/chatbot/metrics/comercios`, {
      params: this.buildParams(params)
    });
  }

  getCommerceDetails(params?: Record<string, string | number | boolean | null | undefined>): Observable<PaginatedResponse<CommerceDetail>> {
    return this.http.get<PaginatedResponse<CommerceDetail>>(`${this.apiUrl}/chatbot/metrics/comercios/detail`, {
      params: this.buildParams(params)
    });
  }

  private buildParams(params?: Record<string, string | number | boolean | null | undefined>): HttpParams {
    let httpParams = new HttpParams();
    if (!params) return httpParams;

    Object.entries(params).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') return;
      httpParams = httpParams.set(key, String(value));
    });

    return httpParams;
  }
}
