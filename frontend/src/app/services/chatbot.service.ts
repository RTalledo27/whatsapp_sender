import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

// ==================== INTERFACES ====================

export type ActionType = 'buttons' | 'free_text' | 'validated_input' | 'link_button' | 'plantilla' | 'crm_lead';
export type ValidationType = 'dni' | 'phone' | 'email' | 'number' | 'text' | 'regex';

export interface BotAction {
  id?: string;
  title?: string;       // Para action_type = 'buttons', 'plantilla'
  button_text?: string; // Para action_type = 'link_button'
  url?: string;         // Para action_type = 'link_button'
  resultado?: string;   // Para validated_input con external_validation ('apto', 'no_apto', 'no_encontrado')
  next_state: string;
}

export interface BotValidation {
  type: ValidationType;
  error_message?: string;
  regex_pattern?: string;
  external_validation?: boolean;  // Para DNI: activar validación contra API externa
}

export interface BotStep {
  state: string;
  question: string;
  action_type: ActionType;
  actions: BotAction[];
  validation?: BotValidation;
  fallback_state?: string; // Para action_type = 'plantilla'
  utm_campaign?: string;   // Para action_type = 'crm_lead'
  utm_term?: string;       // Para action_type = 'crm_lead'
  order: number;
  // Retrocompatibilidad con el formato legacy
  buttons?: { id: string; title: string; nextState: string }[];
}

export interface BotFlow {
  id: string;
  name: string;
  phone_number_id?: string;
  steps: BotStep[];
  created_at?: string;
  updated_at?: string;
}

// ==================== HELPERS ====================

/**
 * Normaliza un paso para asegurar que siempre tenga action_type y actions,
 * incluso si viene en formato legacy (con buttons).
 */
export function normalizeStep(step: BotStep): BotStep {
  const normalized = { ...step };

  // Si viene sin action_type pero con buttons (formato legacy), convertir
  if (!normalized.action_type) {
    normalized.action_type = 'buttons';
  }

  if (!normalized.actions || normalized.actions.length === 0) {
    if (normalized.buttons && normalized.buttons.length > 0) {
      normalized.actions = normalized.buttons.map(b => ({
        id: b.id,
        title: b.title,
        next_state: b.nextState,
      }));
    } else {
      normalized.actions = [];
    }
  }

  return normalized;
}

// ==================== SERVICE ====================

@Injectable({
  providedIn: 'root'
})
export class ChatbotService {
  private apiUrl = `${environment.apiUrl}/chatbot`;

  constructor(private http: HttpClient) {}

  getFlows(): Observable<BotFlow[]> {
    return this.http.get<BotFlow[]>(`${this.apiUrl}/flows`);
  }

  getFlow(id: string): Observable<BotFlow> {
    return this.http.get<BotFlow>(`${this.apiUrl}/flows/${id}`);
  }

  createFlow(name: string, phoneNumberId?: string): Observable<BotFlow> {
    return this.http.post<BotFlow>(`${this.apiUrl}/flows`, { name, phone_number_id: phoneNumberId });
  }

  updateFlow(id: string, data: Partial<BotFlow>): Observable<BotFlow> {
    return this.http.put<BotFlow>(`${this.apiUrl}/flows/${id}`, data);
  }

  deleteFlow(id: string): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/flows/${id}`);
  }

  addStep(flowId: string, step: BotStep): Observable<BotStep> {
    return this.http.post<BotStep>(`${this.apiUrl}/flows/${flowId}/steps`, step);
  }

  updateStep(flowId: string, step: BotStep): Observable<BotStep> {
    return this.http.put<BotStep>(`${this.apiUrl}/flows/${flowId}/steps/${step.state}`, step);
  }

  deleteStep(flowId: string, state: string): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/flows/${flowId}/steps/${state}`);
  }
}
