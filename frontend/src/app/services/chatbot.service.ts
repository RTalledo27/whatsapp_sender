import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface BotButton {
  id: string;
  title: string;
  nextState: string;
}

export interface BotStep {
  state: string;
  question: string;
  buttons: BotButton[];
  order: number;
}

export interface BotFlow {
  id: string;
  name: string;
  steps: BotStep[];
  created_at?: string;
  updated_at?: string;
}

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

  createFlow(name: string): Observable<BotFlow> {
    return this.http.post<BotFlow>(`${this.apiUrl}/flows`, { name });
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
