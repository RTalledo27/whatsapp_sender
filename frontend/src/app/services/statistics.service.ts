import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Statistics {
  overview: {
    total_contacts: number;
    total_campaigns: number;
    total_messages: number;
    messages_sent: number;
    messages_failed: number;
    messages_pending: number;
  };
  campaigns_status: Array<{ status: string; count: number }>;
  recent_campaigns: any[];
  success_rate: number;
  messages_by_day: Array<{
    date: string;
    total: number;
    sent: number;
    failed: number;
  }>;
  top_contacts: any[];
}

@Injectable({
  providedIn: 'root'
})
export class StatisticsService {
  private apiUrl = `${environment.apiUrl}/statistics`;

  constructor(private http: HttpClient) {}

  getStatistics(): Observable<Statistics> {
    return this.http.get<Statistics>(this.apiUrl);
  }

  exportStatistics(): Observable<any> {
    return this.http.get(`${this.apiUrl}/export`);
  }
}
