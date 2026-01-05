import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Conversation {
  id: number;
  name: string;
  phone_number: string;
  email?: string;
  total_messages: number;
  unread_count: number;
  last_message_at: string;
}

export interface Message {
  id: number;
  contact_id: number;
  campaign_id?: number;
  phone: string;
  message?: string;
  message_content?: string;
  status: string;
  direction: 'inbound' | 'outbound';
  whatsapp_message_id?: string;
  message_timestamp: string;
  read_at?: string;
  delivered_at?: string;
  created_at: string;
}

export interface ConversationDetail {
  contact: {
    id: number;
    name: string;
    phone_number: string;
    email?: string;
  };
  messages: {
    data: Message[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface ConversationStats {
  total_conversations: number;
  unread_messages: number;
  messages_today: number;
  incoming_today: number;
  outgoing_today: number;
}

@Injectable({
  providedIn: 'root'
})
export class ConversationService {
  private apiUrl = `${environment.apiUrl}/conversations`;

  constructor(private http: HttpClient) {}

  /**
   * Obtener lista de conversaciones
   */
  getConversations(search: string = '', page: number = 1, perPage: number = 20): Observable<any> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());
    
    if (search) {
      params = params.set('search', search);
    }

    return this.http.get<any>(this.apiUrl, { params });
  }

  /**
   * Obtener mensajes de una conversación
   */
  getConversation(contactId: number, page: number = 1, perPage: number = 50): Observable<ConversationDetail> {
    const params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    return this.http.get<ConversationDetail>(`${this.apiUrl}/${contactId}`, { params });
  }

  /**
   * Marcar mensajes como leídos
   */
  markAsRead(contactId: number): Observable<any> {
    return this.http.post(`${this.apiUrl}/${contactId}/mark-read`, {});
  }

  /**
   * Obtener estadísticas
   */
  getStats(): Observable<ConversationStats> {
    return this.http.get<ConversationStats>(`${this.apiUrl}/stats`);
  }

  /**
   * Buscar en mensajes
   */
  searchMessages(query: string, page: number = 1, perPage: number = 20): Observable<any> {
    const params = new HttpParams()
      .set('q', query)
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    return this.http.get<any>(`${this.apiUrl}/search`, { params });
  }

  /**
   * Enviar mensaje a un contacto
   */
  sendMessage(contactId: number, message: string): Observable<any> {
    return this.http.post(`${this.apiUrl}/${contactId}/send`, { message });
  }
}
