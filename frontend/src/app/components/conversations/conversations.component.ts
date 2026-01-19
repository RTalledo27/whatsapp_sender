import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, NavigationEnd } from '@angular/router';
import { ConversationService, Conversation, ConversationDetail, Message } from '../../services/conversation.service';
import { CampaignService, WhatsAppNumber } from '../../services/campaign.service';
import { AuthService } from '../../services/auth.service';
import { interval, Subscription } from 'rxjs';
import { switchMap, filter } from 'rxjs/operators';

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
  
  // Filtro por número de WhatsApp
  availableNumbers: WhatsAppNumber[] = [];
  selectedPhoneNumberId: string = '';
  
  // Input de mensaje
  newMessageText = '';
  selectedFile: File | null = null;
  sendingMessage = false;

  isRecording = false;
  recordingSeconds = 0;
  private recordingTimer?: number;
  private mediaRecorder?: MediaRecorder;
  private mediaStream?: MediaStream;
  private recordedChunks: BlobPart[] = [];
  
  // Paginación
  currentPage = 1;
  totalPages = 1;
  loadingMoreConversations = false;
  
  // Polling para nuevos mensajes
  private pollingSubscription?: Subscription;
  private navigationSubscription?: Subscription;
  pollingInterval = 5000; // 5 segundos

  // Estadísticas
  stats = {
    total_conversations: 0,
    unread_messages: 0,
    messages_today: 0,
    incoming_today: 0,
    outgoing_today: 0
  };

  constructor(
    private conversationService: ConversationService,
    private campaignService: CampaignService,
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    // Si el usuario no es admin, auto-seleccionar su número ANTES de cargar
    const user = this.authService.getCurrentUser();
    if (user && user.role !== 'admin' && user.phone_number_id) {
      this.selectedPhoneNumberId = user.phone_number_id;
    }
    
    this.loadAvailableNumbers();
    this.loadConversations();
    this.loadStats();
    this.startPolling();

    // Suscribirse a eventos de navegación para recargar cuando se vuelve a esta ruta
    this.navigationSubscription = this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      filter((event: NavigationEnd) => event.url === '/conversations')
    ).subscribe(() => {
      // Recargar datos cuando volvemos a conversations
      this.loadConversations();
      this.loadStats();
    });
  }

  ngOnDestroy(): void {
    this.stopPolling();
    this.cleanupRecorder();
    if (this.navigationSubscription) {
      this.navigationSubscription.unsubscribe();
    }
  }

  /**
   * Cargar números disponibles
   */
  loadAvailableNumbers(): void {
    this.campaignService.getAvailableNumbers().subscribe({
      next: (response) => {
        if (response.success && response.numbers) {
          this.availableNumbers = response.numbers;
        }
      },
      error: (error) => {
        console.error('Error cargando números:', error);
      }
    });
  }

  /**
   * Cambiar número seleccionado
   */
  onPhoneNumberChange(): void {
    this.currentPage = 1;
    this.conversations = [];
    this.selectedConversation = null;
    this.loadConversations();
  }

  /**
   * Cargar lista de conversaciones
   */
  loadConversations(append: boolean = false): void {
    if (append) {
      this.loadingMoreConversations = true;
    } else {
      this.loading = true;
    }

    const phoneNumberId = this.selectedPhoneNumberId || null;
    this.conversationService.getConversations(this.searchTerm, this.currentPage, 50, phoneNumberId)
      .subscribe({
        next: (response) => {
          if (append) {
            // Agregar las nuevas conversaciones a las existentes
            this.conversations = [...this.conversations, ...response.data];
          } else {
            // Reemplazar todas las conversaciones
            this.conversations = response.data;
          }
          this.currentPage = response.current_page;
          this.totalPages = response.last_page;
          this.loading = false;
          this.loadingMoreConversations = false;
        },
        error: (error) => {
          console.error('Error loading conversations:', error);
          this.loading = false;
          this.loadingMoreConversations = false;
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
    const phoneNumberId = this.selectedPhoneNumberId || null;
    this.conversationService.getConversation(conversation.id, 1, 50, phoneNumberId).subscribe({
      next: (detail) => {
        this.selectedConversation = detail;
        // Filtrar mensajes de tipo 'reaction' - solo mostrar mensajes normales
        this.messages = detail.messages.data
          .filter(msg => msg.message_type !== 'reaction')
          .reverse(); // Más antiguos primero
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
          // Solo actualizar stats, no recargar mensajes constantemente
          return this.conversationService.getStats();
        })
      )
      .subscribe({
        next: (stats) => {
          const hadNewMessages = stats.unread_messages > this.stats.unread_messages;
          this.stats = stats;
          
          // Si hay nuevos mensajes no leídos, recargar conversaciones (lista)
          if (hadNewMessages) {
            this.loadConversations();
            
            // Si la conversación seleccionada tiene nuevos mensajes, recargarla
            if (this.selectedConversation) {
              const phoneNumberId = this.selectedPhoneNumberId || null;
              this.conversationService.getConversation(this.selectedConversation.contact.id, 1, 50, phoneNumberId)
                .subscribe({
                  next: (detail) => {
                    const newMessages = detail.messages.data
                      .filter(msg => msg.message_type !== 'reaction')
                      .reverse();
                    
                    // Solo actualizar si hay cambios en la cantidad de mensajes
                    if (newMessages.length !== this.messages.length) {
                      this.messages = newMessages;
                      setTimeout(() => this.scrollToBottom(), 100);
                    }
                  },
                  error: (error) => {
                    console.error('Error updating conversation:', error);
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
   * Actualizar la vista previa de una conversación en la lista
   */
  updateConversationPreview(contactId: number, messageText: string, direction: 'inbound' | 'outbound'): void {
    const conversation = this.conversations.find(c => c.id === contactId);
    if (conversation) {
      conversation.last_message = messageText;
      conversation.last_message_at = new Date().toISOString();
      conversation.last_message_direction = direction;
    }
  }

  /**
   * Enviar mensaje
   */
  sendMessage(): void {
    if (!this.selectedConversation || this.sendingMessage || this.isRecording) {
      return;
    }

    const messageText = this.newMessageText.trim();
    const hasText = messageText.length > 0;
    const hasFile = !!this.selectedFile;

    if (!hasText && !hasFile) {
      return;
    }

    this.sendingMessage = true;
    const contactId = this.selectedConversation.contact.id;
    const phoneNumberId = this.selectedPhoneNumberId || null;

    const request$ = this.selectedFile
      ? this.conversationService.sendFile(contactId, this.selectedFile, messageText || undefined, phoneNumberId)
      : this.conversationService.sendMessage(contactId, messageText, phoneNumberId);

    request$.subscribe({
      next: (response) => {
        // Limpiar input
        this.newMessageText = '';
        this.selectedFile = null;
        
        const backendMessage = response.message as Message;
        const newMessage: Message = {
          ...backendMessage,
          contact_id: backendMessage.contact_id ?? contactId,
          direction: 'outbound',
          status: backendMessage.status || 'pending',
          message_timestamp: backendMessage.message_timestamp || new Date().toISOString(),
          created_at: backendMessage.created_at || new Date().toISOString(),
          phone: backendMessage.phone ?? this.selectedConversation!.contact.phone_number,
          phone_number: backendMessage.phone_number ?? this.selectedConversation!.contact.phone_number,
        };
        
        this.messages.push(newMessage);
        this.sendingMessage = false;
        
        // Actualizar la vista previa en la lista de conversaciones
        this.updateConversationPreview(contactId, messageText || '[Archivo]', 'outbound');
        
        // Scroll al final
        setTimeout(() => this.scrollToBottom(), 100);
      },
      error: (error) => {
        console.error('Error sending message:', error);
        if (error?.status === 413) {
          alert('El archivo es demasiado grande (413). Sube el límite del servidor (Nginx/PHP) o prueba con un archivo más pequeño.');
        } else if (typeof error?.error?.message === 'string' && error.error.message.trim()) {
          alert(error.error.message);
        } else {
          alert('Error al enviar. Por favor intenta de nuevo.');
        }
        this.sendingMessage = false;
      }
    });
  }

  onAttachClick(fileInput: HTMLInputElement): void {
    if (this.sendingMessage || this.isRecording) return;
    fileInput.click();
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] || null;
    input.value = '';
    if (!file) return;
    this.selectedFile = file;
  }

  removeSelectedFile(): void {
    if (this.sendingMessage) return;
    this.selectedFile = null;
  }

  async toggleVoiceRecording(): Promise<void> {
    if (this.sendingMessage) return;
    if (this.isRecording) {
      this.stopVoiceRecording();
      return;
    }
    await this.startVoiceRecording();
  }

  private async startVoiceRecording(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia) {
      alert('Tu navegador no soporta grabación de audio.');
      return;
    }

    try {
      this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      this.recordedChunks = [];

      const preferredTypes = [
        'audio/ogg;codecs=opus',
        'audio/webm;codecs=opus',
        'audio/webm',
      ];

      const chosenType = preferredTypes.find(t => typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(t));
      if (!chosenType) {
        alert('Tu navegador no soporta grabación de audio.');
        this.cleanupRecorder();
        return;
      }

      this.mediaRecorder = new MediaRecorder(this.mediaStream, { mimeType: chosenType });

      this.mediaRecorder.ondataavailable = (event: BlobEvent) => {
        if (event.data && event.data.size > 0) {
          this.recordedChunks.push(event.data);
        }
      };

      this.mediaRecorder.onstop = () => {
        const mimeType = this.mediaRecorder?.mimeType || 'audio/webm';
        const blob = new Blob(this.recordedChunks, { type: mimeType });
        const extension = mimeType.includes('ogg') ? 'ogg' : (mimeType.includes('webm') ? 'webm' : 'audio');
        const fileName = `nota-de-voz-${Date.now()}.${extension}`;
        this.selectedFile = new File([blob], fileName, { type: mimeType });
        this.cleanupRecorder();
      };

      this.mediaRecorder.start();
      this.isRecording = true;
      this.recordingSeconds = 0;
      this.startRecordingTimer();
    } catch (e) {
      console.error('Error starting audio recording', e);
      alert('No se pudo acceder al micrófono. Revisa permisos del navegador.');
      this.cleanupRecorder();
    }
  }

  private stopVoiceRecording(): void {
    if (!this.mediaRecorder || !this.isRecording) return;
    try {
      this.mediaRecorder.stop();
    } catch (e) {
      console.error('Error stopping audio recording', e);
      this.cleanupRecorder();
    }
    this.isRecording = false;
    this.stopRecordingTimer();
  }

  private startRecordingTimer(): void {
    this.stopRecordingTimer();
    this.recordingTimer = window.setInterval(() => {
      this.recordingSeconds += 1;
    }, 1000);
  }

  private stopRecordingTimer(): void {
    if (this.recordingTimer) {
      window.clearInterval(this.recordingTimer);
      this.recordingTimer = undefined;
    }
  }

  private cleanupRecorder(): void {
    this.stopRecordingTimer();
    if (this.mediaStream) {
      for (const track of this.mediaStream.getTracks()) {
        track.stop();
      }
    }
    this.mediaStream = undefined;
    this.mediaRecorder = undefined;
    this.recordedChunks = [];
    this.isRecording = false;
    this.recordingSeconds = 0;
  }

  formatRecordingTime(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
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
   * Detectar scroll para cargar más conversaciones
   */
  onScrollConversations(event: Event): void {
    const element = event.target as HTMLElement;
    const threshold = 100; // Pixels antes del final para cargar más
    
    const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < threshold;
    
    if (atBottom && !this.loadingMoreConversations && this.currentPage < this.totalPages) {
      // Cargar siguiente página
      this.currentPage++;
      this.loadConversations(true);
    }
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

  /**
   * Formatear tamaño de archivo
   */
  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  }
}
