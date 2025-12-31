import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Message {
  id: number;
  campaign_id: number;
  contact_id: number;
  phone_number: string;
  message: string;
  status: 'pending' | 'sent' | 'failed' | 'delivered' | 'read';
  whatsapp_message_id?: string;
  error_message?: string;
  sent_at?: string;
  created_at: string;
}

export interface Campaign {
  id: number;
  name: string;
  message: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  total_contacts: number;
  sent_count: number;
  failed_count: number;
  pending_count: number;
  started_at?: string;
  completed_at?: string;
  created_at: string;
  messages?: Message[];
}

export interface CampaignStatistics {
  campaign: Campaign;
  total_messages: number;
  sent: number;
  failed: number;
  pending: number;
  success_rate: number;
  messages_by_status: Array<{ status: string; count: number }>;
  recent_messages: Message[];
}

@Injectable({
  providedIn: 'root'
})
export class CampaignService {
  private apiUrl = `${environment.apiUrl}/campaigns`;

  constructor(private http: HttpClient) {}

  getCampaigns(page: number = 1, perPage: number = 20): Observable<any> {
    const params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    return this.http.get(this.apiUrl, { params });
  }

  getCampaign(id: number): Observable<Campaign> {
    return this.http.get<Campaign>(`${this.apiUrl}/${id}`);
  }

  createCampaign(data: { name: string; message: string; contact_ids: number[] }): Observable<any> {
    return this.http.post(this.apiUrl, data);
  }

  deleteCampaign(id: number): Observable<any> {
    return this.http.delete(`${this.apiUrl}/${id}`);
  }

  getCampaignStatistics(id: number): Observable<CampaignStatistics> {
    return this.http.get<CampaignStatistics>(`${this.apiUrl}/${id}/statistics`);
  }

  retryFailed(id: number): Observable<any> {
    return this.http.post(`${this.apiUrl}/${id}/retry-failed`, {});
  }
}
