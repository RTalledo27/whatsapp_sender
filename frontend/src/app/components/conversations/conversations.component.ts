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
        
        // Scroll al final
        setTimeout(() => this.scrollToBottom(), 100);
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
        switchMap(() => this.conversationService.getStats())
      )
      .subscribe({
        next: (stats) => {
          const hadNewMessages = stats.unread_messages > this.stats.unread_messages;
          this.stats = stats;
          
          // Si hay nuevos mensajes, recargar conversaciones
          if (hadNewMessages) {
            this.loadConversations();
            
            // Si hay una conversación seleccionada, recargar mensajes
            if (this.selectedConversation) {
              this.conversationService.getConversation(this.selectedConversation.contact.id)
                .subscribe({
                  next: (detail) => {
                    this.messages = detail.messages.data.reverse();
                    setTimeout(() => this.scrollToBottom(), 100);
                  }
                });
            }
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
   * Formatear fecha relativa
   */
  formatRelativeTime(timestamp: string): string {
    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Ahora';
    if (diffMins < 60) return `Hace ${diffMins}m`;
    if (diffHours < 24) return `Hace ${diffHours}h`;
    if (diffDays < 7) return `Hace ${diffDays}d`;
    
    return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
  }

  /**
   * Formatear hora
   */
  formatTime(timestamp: string): string {
    const date = new Date(timestamp);
    return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
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
}
