import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';
import { Contact } from './contact.service';

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
  contact?: Contact;
}

export interface Campaign {
  id: number;
  name: string;
  phone_number_id?: string;
  phone_number_name?: string;
  message: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  total_contacts: number;
  sent_count: number;
  failed_count: number;
  pending_count: number;
  read_count: number;
  replied_count: number;
  started_at?: string;
  completed_at?: string;
  created_at: string;
  template_name?: string;
  template_parameters?: string[];
  video_link?: string;
  image_link?: string;
  image_media_id?: string;
  messages?: Message[];
}

export interface WhatsAppNumber {
  id: string;
  name: string;
  phone: string;
  access_token?: string;
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

  constructor(
    private http: HttpClient,
    private authService: AuthService
  ) { }

  getAvailableNumbers(): Observable<{ success: boolean; numbers: WhatsAppNumber[] }> {
    return this.http.get<{ success: boolean; numbers: WhatsAppNumber[] }>(`${this.apiUrl}/available-numbers`)
      .pipe(
        map(response => {
          const user = this.authService.getCurrentUser();

          // Si es admin, devolver todos los números
          if (user && user.role === 'admin') {
            return response;
          }

          // Si es usuario normal, filtrar solo su número asignado
          if (user && user.phone_number_id) {
            return {
              success: response.success,
              numbers: response.numbers.filter(n => n.id === user.phone_number_id)
            };
          }

          // Si no hay usuario o no tiene número, devolver array vacío
          return {
            success: response.success,
            numbers: []
          };
        })
      );
  }

  getCampaigns(page: number = 1, perPage: number = 20, phoneNumberId?: string | null): Observable<any> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }

    return this.http.get(this.apiUrl, { params });
  }

  getCampaign(id: number): Observable<Campaign> {
    return this.http.get<Campaign>(`${this.apiUrl}/${id}`);
  }

  getCampaignDetails(id: number): Observable<Campaign> {
    return this.http.get<Campaign>(`${this.apiUrl}/${id}/details`);
  }

  createCampaign(data: any): Observable<any> {
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

  uploadMedia(file: File, phoneNumberId: string): Observable<any> {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('phone_number_id', phoneNumberId);

    return this.http.post(`${this.apiUrl}/upload-media`, formData);
  }
}
