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
  last_message?: string;
  last_message_direction?: 'inbound' | 'outbound';
}

export interface Reaction {
  contact_id: number;
  emoji: string;
  created_at: string;
}

export interface Message {
  id: number;
  contact_id: number;
  campaign_id?: number;
  phone?: string;
  phone_number?: string;
  message?: string;
  message_content?: string;
  status: string;
  direction: 'inbound' | 'outbound';
  whatsapp_message_id?: string;
  message_timestamp: string;
  read_at?: string;
  delivered_at?: string;
  created_at: string;
  message_type?: string;
  media_url?: string;
  media_id?: string;
  metadata?: any;
  reactions?: Reaction[];
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
  qualified?: number;
  not_qualified?: number;
  inactive?: number;
  active_conversations?: number;
  inactive_conversations?: number;
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
  getConversations(search: string = '', page: number = 1, perPage: number = 20, phoneNumberId: string | null = null, botStatus: string | null = null, showInactive: boolean = false, noTimeFilter: boolean = false): Observable<any> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());
    
    if (search) {
      params = params.set('search', search);
    }
    
    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }

    if (botStatus) {
      params = params.set('bot_status', botStatus);
    }

    // Para canales no-bot, agregar parámetro show_inactive
    if (showInactive) {
      params = params.set('show_inactive', 'true');
    }

    // Para filtros frontend (messages_today, unread, etc.), deshabilitar filtro de tiempo
    if (noTimeFilter) {
      params = params.set('no_time_filter', 'true');
    }

    return this.http.get<any>(this.apiUrl, { params });
  }

  /**
   * Obtener mensajes de una conversación
   */
  getConversation(contactId: number, page: number = 1, perPage: number = 50, phoneNumberId: string | null = null): Observable<ConversationDetail> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());
    
    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }

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
  getStats(phoneNumberId?: string): Observable<ConversationStats> {
    let params = new HttpParams();
    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }
    return this.http.get<ConversationStats>(`${this.apiUrl}/stats`, { params });
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
  sendMessage(contactId: number, message: string, phoneNumberId: string | null = null): Observable<any> {
    const body: any = { message };
    if (phoneNumberId) {
      body.phone_number_id = phoneNumberId;
    }
    return this.http.post(`${this.apiUrl}/${contactId}/send`, body);
  }

  sendFile(contactId: number, file: File, caption?: string, phoneNumberId: string | null = null): Observable<any> {
    const formData = new FormData();
    formData.append('file', file);
    if (caption && caption.trim()) {
      formData.append('message', caption.trim());
    }
    if (phoneNumberId) {
      formData.append('phone_number_id', phoneNumberId);
    }
    return this.http.post(`${this.apiUrl}/${contactId}/send`, formData);
  }
}
