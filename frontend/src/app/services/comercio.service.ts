import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

// ==================== INTERFACES ====================

export interface ComercioTelefono {
  id: number;
  comercio_id: number;
  telefono: string;
  tipo_flujo: string;
  activo: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface Comercio {
  id: number;
  nombre: string;
  estado: 'activo' | 'inactivo';
  telefonos?: ComercioTelefono[];
  created_at?: string;
  updated_at?: string;
}

// ==================== SERVICE ====================

@Injectable({
  providedIn: 'root'
})
export class ComercioService {
  private apiUrl = `${environment.apiUrl}/comercios`;

  constructor(private http: HttpClient) {}

  /**
   * Obtener todos los comercios con sus teléfonos
   */
  getAll(): Observable<Comercio[]> {
    return this.http.get<Comercio[]>(this.apiUrl);
  }

  /**
   * Obtener un comercio por ID
   */
  getById(id: number): Observable<Comercio> {
    return this.http.get<Comercio>(`${this.apiUrl}/${id}`);
  }

  /**
   * Crear un nuevo comercio
   */
  create(data: { nombre: string; estado?: string }): Observable<Comercio> {
    return this.http.post<Comercio>(this.apiUrl, data);
  }

  /**
   * Actualizar un comercio
   */
  update(id: number, data: Partial<Comercio>): Observable<Comercio> {
    return this.http.put<Comercio>(`${this.apiUrl}/${id}`, data);
  }

  /**
   * Eliminar un comercio
   */
  delete(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }

  /**
   * Agregar un teléfono a un comercio
   */
  addTelefono(comercioId: number, data: { telefono: string; tipo_flujo?: string }): Observable<ComercioTelefono> {
    return this.http.post<ComercioTelefono>(`${this.apiUrl}/${comercioId}/telefonos`, data);
  }

  /**
   * Eliminar un teléfono de un comercio
   */
  removeTelefono(comercioId: number, telefonoId: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${comercioId}/telefonos/${telefonoId}`);
  }
}
