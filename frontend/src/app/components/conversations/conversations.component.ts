import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ConversationService, Conversation, ConversationDetail, Message } from '../../services/conversation.service';
import { interval, Subscription } from 'rxjs';
import { switchMap } from 'rxjs/operators';

@Component({
  selector: 'app-conversations',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './conversations.component.html',
  styleUrls: ['./conversations.component.css']
})
export class ConversationsComponent implements OnInit, OnDestroy {
  conversations: Conversation[] = [];
  selectedConversation: ConversationDetail | null = null;
  messages: Message[] = [];
  loading = false;
  loadingMessages = false;
  searchTerm = '';
  
  // Input de mensaje
  newMessageText = '';
  sendingMessage = false;
  
  // Paginación
  currentPage = 1;
  totalPages = 1;
  
  // Polling para nuevos mensajes
  private pollingSubscription?: Subscription;
  pollingInterval = 5000; // 5 segundos

  // Estadísticas
  stats = {
    total_conversations: 0,
    unread_messages: 0,
    messages_today: 0,
    incoming_today: 0,
    outgoing_today: 0
  };

  constructor(private conversationService: ConversationService) {}

  ngOnInit(): void {
    this.loadConversations();
    this.loadStats();
    this.startPolling();
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  /**
   * Cargar lista de conversaciones
   */
  loadConversations(): void {
    this.loading = true;
    this.conversationService.getConversations(this.searchTerm, this.currentPage)
      .subscribe({
        next: (response) => {
          this.conversations = response.data;
          this.currentPage = response.current_page;
          this.totalPages = response.last_page;
          this.loading = false;
        },
        error: (error) => {
          console.error('Error loading conversations:', error);
          this.loading = false;
        }
      });
  }

  /**
   * Cargar estadísticas
   */
  loadStats(): void {
    this.conversationService.getStats().subscribe({
      next: (stats) => {
        this.stats = stats;
      },
      error: (error) => {
        console.error('Error loading stats:', error);
      }
    });
  }

  /**
   * Seleccionar una conversación
   */
  selectConversation(conversation: Conversation): void {
    this.loadingMessages = true;
    this.conversationService.getConversation(conversation.id).subscribe({
      next: (detail) => {
        this.selectedConversation = detail;
        this.messages = detail.messages.data.reverse(); // Más antiguos primero
        this.loadingMessages = false;
        
        // Marcar como leído
        if (conversation.unread_count > 0) {
          this.conversationService.markAsRead(conversation.id).subscribe();
          conversation.unread_count = 0;
          this.stats.unread_messages = Math.max(0, this.stats.unread_messages - conversation.unread_count);
        }
        
        // Scroll al final después de que se rendericen los mensajes
        setTimeout(() => this.scrollToBottom(), 200);
      },
      error: (error) => {
        console.error('Error loading conversation:', error);
        this.loadingMessages = false;
      }
    });
  }

  /**
   * Buscar conversaciones
   */
  onSearch(): void {
    this.currentPage = 1;
    this.loadConversations();
  }

  /**
   * Scroll al final del chat
   */
  scrollToBottom(): void {
    const chatContainer = document.querySelector('.messages-container');
    if (chatContainer) {
      chatContainer.scrollTop = chatContainer.scrollHeight;
    }
  }

  /**
   * Iniciar polling para nuevos mensajes
   */
  startPolling(): void {
    this.pollingSubscription = interval(this.pollingInterval)
      .pipe(
        switchMap(() => {
          // Si hay conversación seleccionada, actualizar mensajes
          if (this.selectedConversation) {
            return this.conversationService.getConversation(this.selectedConversation.contact.id)
              .pipe(
                switchMap(detail => {
                  // Actualizar mensajes con estados reales
                  this.messages = detail.messages.data.reverse();
                  return this.conversationService.getStats();
                })
              );
          }
          return this.conversationService.getStats();
        })
      )
      .subscribe({
        next: (stats) => {
          const hadNewMessages = stats.unread_messages > this.stats.unread_messages;
          this.stats = stats;
          
          // Si hay nuevos mensajes, recargar conversaciones
          if (hadNewMessages) {
            this.loadConversations();
          }
        },
        error: (error) => {
          console.error('Polling error:', error);
        }
      });
  }

  /**
   * Detener polling
   */
  stopPolling(): void {
    if (this.pollingSubscription) {
      this.pollingSubscription.unsubscribe();
    }
  }

  /**
   * Formatear hora
   */
  formatTime(timestamp: string): string {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();
    
    if (isToday) {
      return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
    } else {
      const yesterday = new Date(now);
      yesterday.setDate(yesterday.getDate() - 1);
      const isYesterday = date.toDateString() === yesterday.toDateString();
      
      if (isYesterday) {
        return 'Ayer ' + date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
      } else {
        return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' }) + ' ' + 
               date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
      }
    }
  }

  /**
   * Formatear tiempo relativo para lista de conversaciones
   */
  formatRelativeTime(timestamp: string): string {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return 'Ahora';
    if (minutes < 60) return `${minutes}m`;
    if (hours < 24) return `${hours}h`;
    if (days === 1) return 'Ayer';
    if (days < 7) return `${days}d`;
    return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
  }

  /**
   * Obtener contenido del mensaje
   */
  getMessageContent(message: Message): string {
    return message.direction === 'inbound' 
      ? (message.message_content || message.message || '')
      : (message.message || message.message_content || '');
  }

  /**
   * Obtener clase de estado
   */
  getStatusClass(message: Message): string {
    if (message.direction === 'inbound') return '';
    
    switch (message.status) {
      case 'sent': return 'status-sent';
      case 'delivered': return 'status-delivered';
      case 'read': return 'status-read';
      case 'failed': return 'status-failed';
      default: return 'status-pending';
    }
  }

  /**
   * Obtener ícono de estado
   */
  getStatusIcon(message: Message): string {
    if (message.direction === 'inbound') return '';
    
    switch (message.status) {
      case 'sent': return '✓';
      case 'delivered': return '✓✓';
      case 'read': return '✓✓';
      case 'failed': return '✗';
      default: return '⏱';
    }
  }

  /**
   * Enviar mensaje
   */
  sendMessage(): void {
    if (!this.newMessageText.trim() || !this.selectedConversation || this.sendingMessage) {
      return;
    }

    this.sendingMessage = true;
    const messageText = this.newMessageText.trim();
    const contactId = this.selectedConversation.contact.id;

    this.conversationService.sendMessage(contactId, messageText).subscribe({
      next: (response) => {
        // Limpiar input
        this.newMessageText = '';
        
        // Agregar mensaje con el estado real del backend
        const newMessage: Message = {
          id: response.message.id,
          contact_id: contactId,
          message: messageText,
          message_content: messageText,
          status: response.message.status || 'pending',
          direction: 'outbound',
          message_timestamp: response.message.message_timestamp || new Date().toISOString(),
          created_at: response.message.created_at || new Date().toISOString(),
          phone: this.selectedConversation!.contact.phone_number
        };
        
        this.messages.push(newMessage);
        this.sendingMessage = false;
        
        // Scroll al final
        setTimeout(() => this.scrollToBottom(), 100);
      },
      error: (error) => {
        console.error('Error sending message:', error);
        alert('Error al enviar el mensaje. Por favor intenta de nuevo.');
        this.sendingMessage = false;
      }
    });
  }

  /**
   * Manejar tecla Enter
   */
  onEnterPress(event: Event): void {
    const keyboardEvent = event as KeyboardEvent;
    if (keyboardEvent.key === 'Enter' && !keyboardEvent.shiftKey) {
      event.preventDefault();
      this.sendMessage();
    }
  }

  /**
   * Truncar mensaje para preview
   */
  truncateMessage(message: string, maxLength: number = 35): string {
    if (!message) return '';
    if (message.length <= maxLength) return message;
    return message.substring(0, maxLength) + '...';
  }

  /**
   * Manejar error de carga de imagen
   */
  onImageError(event: Event): void {
    const img = event.target as HTMLImageElement;
    img.style.display = 'none';
    // Mostrar el texto alternativo
    if (img.parentElement) {
      const fallback = document.createElement('div');
      fallback.textContent = '📷 Imagen no disponible';
      fallback.className = 'image-error';
      img.parentElement.appendChild(fallback);
    }
  }
}
