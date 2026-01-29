import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface WhatsAppTemplate {
  id: string;
  name: string;
  language: string;
  status: string;
  category: string;
  components: Array<{
    type: string;
    format?: string;
    text?: string;
    example?: {
      body_text?: string[][];
    };
  }>;
}

@Injectable({
  providedIn: 'root'
})
export class TemplateService {
  private apiUrl = `${environment.apiUrl}/templates`;

  constructor(private http: HttpClient) { }

  getTemplates(phoneNumberId?: string): Observable<{ templates: WhatsAppTemplate[] }> {
    let params = new HttpParams();
    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }
    return this.http.get<{ templates: WhatsAppTemplate[] }>(this.apiUrl, { params });
  }

  getTemplateParameters(template: WhatsAppTemplate): string[] {
    const bodyComponent = template.components.find(c => c.type === 'BODY');
    if (!bodyComponent || !bodyComponent.text) {
      return [];
    }

    const matches = bodyComponent.text.match(/\{\{(\d+)\}\}/g);
    return matches ? matches.map((_, index) => `param${index + 1}`) : [];
  }
}
