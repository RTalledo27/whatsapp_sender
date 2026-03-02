import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface BotStats {
  qualified_leads: number;
  non_qualified_leads: number;
  sent_to_crm: number;
  pending_to_send: number;
  failed_to_send: number;
  abandoned_chats: number;
  success_rate: number;
  recently_sent: Contact[];
}

export interface Contact {
  id: number;
  name: string;
  phone_number: string;
  email?: string;
  metadata?: {
    crm_sent?: boolean;
    crm_lead_id?: string;
    crm_assigned_to?: string;
    crm_sent_at?: string;
  };
}

@Injectable({
  providedIn: 'root'
})
export class BotStatsService {
  private apiUrl = environment.apiUrl || 'http://localhost:8000/api';

  constructor(private http: HttpClient) {}

  getStats(): Observable<BotStats> {
    return this.http.get<BotStats>(`${this.apiUrl}/crm/stats`);
  }

  getFailedLeads(): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/crm/failed-leads`);
  }

  resendLead(contactId: number): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/crm/resend/${contactId}`, {});
  }
}
