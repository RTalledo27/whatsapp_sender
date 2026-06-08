import { Component, OnInit, OnDestroy } from '@angular/core';
import { NotesComponent } from '../notes/notes.component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, NavigationEnd } from '@angular/router';
import { ConversationService, Conversation, ConversationDetail, Message, ConversationStats } from '../../services/conversation.service';
import { CampaignService, WhatsAppNumber } from '../../services/campaign.service';
import { AuthService } from '../../services/auth.service';
import { NotesService } from '../../services/notes.service';
import { interval, Subscription } from 'rxjs';
import { switchMap, filter } from 'rxjs/operators';

@Component({
  selector: 'app-conversations',
  standalone: true,
  imports: [CommonModule, FormsModule, NotesComponent],
  templateUrl: './conversations.component.html',
  styleUrls: ['./conversations.component.css']
})
export class ConversationsComponent implements OnInit, OnDestroy {
  conversations: Conversation[] = [];
  filteredConversations: Conversation[] = []; // Conversaciones filtradas por stat
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

  // Estado para el menú de 3 puntos
  menuOpen = false;

  // Estado para el modal de nota
  noteModalOpen = false;
  noteTitle = '';
  noteContent = '';
  noteError = '';

  // Filtro por estadísticas (por defecto 'all' está seleccionado)
  selectedStat = 'all'; // 'all', 'unread', 'messages_today', 'incoming_today'
  selectedBotStatus = 'all'; // 'all', 'qualified', 'not_qualified', 'inactive'
  showInactiveClients = false; // Para canales no-bot: mostrar deshabilitados

  // Estado del toggle de bot (solo para Leads Comunicaciones)
  botPaused = false;    // true = bot en 'handoff' (asesor atiende), false = bot activo
  togglingBot = false;  // true = petición en curso, evita doble click

  // Paginación de conversaciones
  currentPage = 1;
  totalPages = 1;
  loadingMoreConversations = false;

  // Paginación de historial de mensajes (scroll-up para cargar más antiguos)
  messagesPage = 1;
  messagesTotalPages = 1;
  loadingOlderMessages = false;

  // Polling para nuevos mensajes (5s para sensación de tiempo real)
  private pollingSubscription?: Subscription;
  private navigationSubscription?: Subscription;
  private readonly pollingInterval = 5000;

  // Estadísticas
  stats: ConversationStats = {
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
    private notesService: NotesService,
    private router: Router
  ) { }
  // Guardar nota desde el modal
  saveNote() {
    if (!this.noteTitle.trim() || !this.noteContent.trim() || !this.selectedConversation) return;
    const note = {
      title: this.noteTitle.trim(),
      content: this.noteContent.trim(),
      client_id: this.selectedConversation.contact.id
    };
    this.notesService.addNote(note).subscribe({
      next: () => {
        this.closeNoteModal();
      },
      error: () => {
        this.noteError = 'No se pudo guardar la nota. Intenta de nuevo.';
      }
    });
  }


  // Mostrar/ocultar menú de 3 puntos
  toggleMenu() {
    this.menuOpen = !this.menuOpen;
  }

  // Abrir modal de nota
  openNoteModal() {
    this.noteModalOpen = true;
    this.menuOpen = false;
    this.noteError = '';
  }

  // Cerrar modal de nota
  closeNoteModal() {
    this.noteModalOpen = false;
    this.noteTitle = '';
    this.noteContent = '';
    this.noteError = '';
  }

  /**
   * Manejar clic en estadística
   */
  onStatClick(stat: string): void {
    if (this.isBotChannel()) {
      this.selectedBotStatus = stat;
    } else {
      // Para canales no-bot
      if (stat === 'inactive') {
        this.showInactiveClients = true;
        this.selectedStat = 'all'; // Resetear selectedStat
      } else if (stat === 'active') {
        this.showInactiveClients = false;
        this.selectedStat = 'all'; // Resetear selectedStat
      } else {
        this.showInactiveClients = false; // Resetear showInactive
        this.selectedStat = stat;
      }
    }
    this.applyStatFilter();
  }

  /**
   * Verificar si el canal seleccionado es el bot
   */
  isBotChannel(): boolean {
    const botPhoneNumberId = '950764051457024';
    return this.selectedPhoneNumberId === botPhoneNumberId;
  }

  /**
   * Verificar si el usuario actual puede ver la pestaña de inactivos
   */
  canViewInactiveTab(): boolean {
    const user = this.authService.getCurrentUser();
    return this.isBotChannel() && (user?.role === 'admin' || user?.phone_number_id === this.selectedPhoneNumberId);
  }

  /**
   * Verificar si el canal seleccionado es el bot de Leads Comunicaciones.
   * Usa el flag is_leads_comunicaciones que devuelve el backend en getAvailableNumbers().
   */
  isLeadsComunicacionesChannel(): boolean {
    if (!this.selectedPhoneNumberId) return false;
    const num = this.availableNumbers.find(n => n.id === this.selectedPhoneNumberId);
    return !!num?.is_leads_comunicaciones;
  }

  /**
   * Pausar o reanudar el bot para la conversación actualmente abierta.
   */
  toggleBot(): void {
    if (!this.selectedConversation || this.togglingBot || !this.selectedPhoneNumberId) return;

    this.togglingBot = true;
    const contactId     = this.selectedConversation.contact.id;
    const phoneNumberId = this.selectedPhoneNumberId;
    const newActive     = this.botPaused; // si está pausado → activar, si activo → pausar

    this.conversationService.setBotStatus(contactId, newActive, phoneNumberId).subscribe({
      next: () => {
        this.botPaused   = !newActive;
        this.togglingBot = false;
      },
      error: (err) => {
        console.error('Error al cambiar estado del bot:', err);
        this.togglingBot = false;
      }
    });
  }

  /**
   * Aplicar filtro basado en estadística seleccionada - optimizado
   */
  applyStatFilter(): void {
    // Para el canal del bot, los filtros se manejan en el backend
    if (this.isBotChannel()) {
      this.currentPage = 1;
      this.conversations = [];
      this.loading = true;
      
      const phoneNumberId = this.selectedPhoneNumberId || null;
      const botStatus = this.selectedBotStatus || 'all';
      
      this.conversationService.getConversations('', 1, 50, phoneNumberId, botStatus, false)
        .subscribe({
          next: (response) => {
            this.conversations = response.data;
            this.currentPage = response.current_page;
            this.totalPages = response.last_page;
            this.loading = false;
            this.filteredConversations = this.conversations;
          },
          error: (error) => {
            console.error('Error cargando conversaciones:', error);
            this.loading = false;
          }
        });
      return;
    }
    
    // Para canales no-bot: si showInactiveClients está activo, cargar inactivos desde backend
    if (this.showInactiveClients) {
      this.currentPage = 1;
      this.conversations = [];
      this.loading = true;
      
      const phoneNumberId = this.selectedPhoneNumberId || null;
      
      this.conversationService.getConversations('', 1, 50, phoneNumberId, null, true)
        .subscribe({
          next: (response) => {
            this.conversations = response.data;
            this.currentPage = response.current_page;
            this.totalPages = response.last_page;
            this.loading = false;
            this.filteredConversations = this.conversations;
          },
          error: (error) => {
            console.error('Error cargando conversaciones:', error);
            this.loading = false;
          }
        });
      return;
    }
    
    // Para canales no-bot: si es 'all', cargar activos; si es otro filtro, cargar activos y filtrar en frontend
    if (this.selectedStat === 'all' || this.selectedStat === 'unread' || 
        this.selectedStat === 'messages_today' || this.selectedStat === 'incoming_today') {
      
      // Cargar conversaciones desde backend
      this.currentPage = 1;
      this.conversations = [];
      this.loading = true;
      
      const phoneNumberId = this.selectedPhoneNumberId || null;
      
      // Si es 'all', aplicar filtro de 24h; si es otro filtro, traer todo sin filtro de tiempo
      const noTimeFilter = this.selectedStat !== 'all';
      
      this.conversationService.getConversations('', 1, 50, phoneNumberId, null, false, noTimeFilter)
        .subscribe({
          next: (response) => {
            this.conversations = response.data;
            this.currentPage = response.current_page;
            this.totalPages = response.last_page;
            this.loading = false;
            
            // Si es 'all', mostrar todo
            if (this.selectedStat === 'all') {
              this.filteredConversations = this.conversations;
              return;
            }
            
            // Para otros filtros, aplicar filtrado frontend
            this.applyFrontendFilter();
          },
          error: (error) => {
            console.error('Error cargando conversaciones:', error);
            this.loading = false;
          }
        });
      return;
    }
    
    // Fallback para filtros desconocidos
    this.applyFrontendFilter();
  }
  
  /**
   * Aplicar filtros en el frontend sobre las conversaciones ya cargadas
   */
  private applyFrontendFilter(): void {
    switch (this.selectedStat) {
      case 'all':
        this.filteredConversations = [...this.conversations];
        break;
      case 'unread':
        this.filteredConversations = this.conversations.filter(conv => (conv.unread_count ?? 0) > 0);
        break;
      case 'messages_today':
        // Filtrar por last_message_at de hoy
        const today = new Date().toDateString();
        this.filteredConversations = this.conversations.filter(conv => {
          if (!conv.last_message_at) return false;
          const messageDate = new Date(conv.last_message_at).toDateString();
          return messageDate === today;
        });
        break;
      case 'incoming_today':
        // Filtrar por mensajes entrantes de hoy
        const todayIncoming = new Date().toDateString();
        this.filteredConversations = this.conversations.filter(conv => {
          if (!conv.last_message_at || conv.last_message_direction !== 'inbound') return false;
          const messageDate = new Date(conv.last_message_at).toDateString();
          return messageDate === todayIncoming;
        });
        break;
      default:
        this.filteredConversations = [...this.conversations];
    }
  }

  // Cerrar menú si se hace click fuera
  ngAfterViewInit() {
    document.addEventListener('click', this.closeMenuOnClickOutside.bind(this));
  }

  // Eliminar el listener y limpiar recursos al destruir el componente
  ngOnDestroy(): void {
    document.removeEventListener('click', this.closeMenuOnClickOutside.bind(this));
    this.stopPolling();
    this.cleanupRecorder();
    if (this.navigationSubscription) {
      this.navigationSubscription.unsubscribe();
    }
  }

  closeMenuOnClickOutside(event: MouseEvent) {
    const menu = document.querySelector('.chat-menu');
    const modal = document.querySelector('.note-modal');
    if (menu && !menu.contains(event.target as Node)) {
      this.menuOpen = false;
    }
    // Si el modal está abierto y se hace click fuera, ciérralo
    if (this.noteModalOpen && modal && !modal.contains(event.target as Node)) {
      this.closeNoteModal();
    }
  }

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
   * Cambiar número seleccionado - optimizado
   */
  onPhoneNumberChange(): void {
    this.currentPage = 1;
    this.conversations = [];
    this.selectedConversation = null;
    this.loading = true;
    this.showInactiveClients = false; // Resetear al cambiar de número
    
    // Cargar conversaciones y stats en paralelo
    const phoneNumberId = this.selectedPhoneNumberId || null;
    const botStatus = this.isBotChannel() ? (this.selectedBotStatus || 'all') : null;
    
    Promise.all([
      this.conversationService.getConversations('', 1, 50, phoneNumberId, botStatus, false).toPromise(),
      this.conversationService.getStats(this.selectedPhoneNumberId || undefined).toPromise()
    ]).then(([conversationsResponse, stats]) => {
      this.conversations = conversationsResponse.data;
      this.currentPage = conversationsResponse.current_page;
      this.totalPages = conversationsResponse.last_page;
      if (stats) {
        this.stats = stats;
      }
      this.loading = false;
      this.applyStatFilter();
    }).catch(error => {
      console.error('Error cargando datos:', error);
      this.loading = false;
    });
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
    const botStatus = this.isBotChannel() ? (this.selectedBotStatus || 'all') : null;
    const showInactive = !this.isBotChannel() ? this.showInactiveClients : false;
    
    // Para filtros frontend (unread, messages_today, incoming_today), necesitamos cargar sin filtro de tiempo
    const needsNoTimeFilter = !this.isBotChannel() && !this.showInactiveClients && 
                               (this.selectedStat === 'unread' || this.selectedStat === 'messages_today' || this.selectedStat === 'incoming_today');
    
    this.conversationService.getConversations(this.searchTerm, this.currentPage, 50, phoneNumberId, botStatus, showInactive, needsNoTimeFilter)
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
          
          // Si es bot o si showInactiveClients está activo o selectedStat es 'all', usar data del backend
          if (this.isBotChannel() || this.showInactiveClients || this.selectedStat === 'all') {
            this.filteredConversations = [...this.conversations];
          } else {
            // Para filtros frontend (unread, messages_today, incoming_today), aplicar filtro sobre los datos cargados
            this.applyFrontendFilter();
          }
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
    this.conversationService.getStats(this.selectedPhoneNumberId || undefined).subscribe({
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
    this.botPaused = false;
    // Resetear paginación de mensajes al cambiar de conversación
    this.messagesPage = 1;
    this.messagesTotalPages = 1;
    this.loadingOlderMessages = false;

    const phoneNumberId = this.selectedPhoneNumberId || null;
    this.conversationService.getConversation(conversation.id, 1, 50, phoneNumberId).subscribe({
      next: (detail: any) => {
        this.selectedConversation = detail;
        if (this.isLeadsComunicacionesChannel()) {
          this.botPaused = detail.bot_state === 'handoff';
        }
        this.messagesTotalPages = detail.messages.last_page ?? 1;
        this.messages = detail.messages.data
          .filter((msg: any) => msg.message_type !== 'reaction')
          .reverse();
        this.loadingMessages = false;

        if (conversation.unread_count > 0) {
          this.conversationService.markAsRead(conversation.id).subscribe();
          conversation.unread_count = 0;
          this.stats.unread_messages = Math.max(0, this.stats.unread_messages - conversation.unread_count);
        }

        setTimeout(() => this.scrollToBottom(), 200);
      },
      error: (error) => {
        console.error('Error loading conversation:', error);
        this.loadingMessages = false;
      }
    });
  }

  /**
   * Cargar mensajes más antiguos (scroll-up al tope del historial).
   * Inserta los mensajes al inicio sin mover la vista del usuario.
   */
  loadOlderMessages(): void {
    if (
      this.loadingOlderMessages ||
      !this.selectedConversation ||
      this.messagesPage >= this.messagesTotalPages
    ) {
      return;
    }

    this.loadingOlderMessages = true;
    const nextPage = this.messagesPage + 1;
    const phoneNumberId = this.selectedPhoneNumberId || null;

    this.conversationService.getConversation(this.selectedConversation.contact.id, nextPage, 50, phoneNumberId)
      .subscribe({
        next: (detail: any) => {
          const olderMessages = detail.messages.data
            .filter((msg: any) => msg.message_type !== 'reaction')
            .reverse();

          // Guardar altura actual del scroll para restaurarla después de insertar mensajes
          const container = document.querySelector('.messages-container') as HTMLElement | null;
          const prevScrollHeight = container?.scrollHeight ?? 0;

          this.messages = [...olderMessages, ...this.messages];
          this.messagesPage = nextPage;
          this.messagesTotalPages = detail.messages.last_page ?? this.messagesTotalPages;
          this.loadingOlderMessages = false;

          // Restaurar posición de scroll para que el usuario vea los mismos mensajes
          if (container) {
            requestAnimationFrame(() => {
              container.scrollTop = container.scrollHeight - prevScrollHeight;
            });
          }
        },
        error: (error) => {
          console.error('Error loading older messages:', error);
          this.loadingOlderMessages = false;
        }
      });
  }

  /**
   * Detectar scroll al tope del contenedor de mensajes para cargar historial.
   * Llamar desde el evento (scroll) del elemento .messages-container en el template.
   */
  onMessagesScroll(event: Event): void {
    const container = event.target as HTMLElement;
    if (container.scrollTop === 0) {
      this.loadOlderMessages();
    }
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
          return this.conversationService.getStats(this.selectedPhoneNumberId || undefined);
        })
      )
      .subscribe({
        next: (stats) => {
          // Detectar nuevos mensajes de forma confiable (incluso si se leen rápido)
          const hadNewMessages = 
            stats.messages_today > (this.stats.messages_today || 0) || 
            stats.unread_messages > this.stats.unread_messages;
            
          const activeChanged = (stats.active_conversations || 0) !== (this.stats.active_conversations || 0);
          const inactiveChanged = (stats.inactive_conversations || 0) !== (this.stats.inactive_conversations || 0);
          
          this.stats = stats;

          // Si hay cambios relevantes, recargar conversaciones (sidebar)
          if (hadNewMessages || activeChanged || inactiveChanged) {
            // Refrescar el sidebar
            this.loadConversations();

            // Si hay una conversación abierta, verificar si recibió el nuevo mensaje
            if (this.selectedConversation) {
              const phoneNumberId = this.selectedPhoneNumberId || null;
              this.conversationService.getConversation(this.selectedConversation.contact.id, 1, 50, phoneNumberId)
                .subscribe({
                  next: (detail: any) => {
                    const newMessages = detail.messages.data
                      .filter((msg: any) => msg.message_type !== 'reaction')
                      .reverse();

                    if (newMessages.length > 0 && this.messages.length > 0) {
                      const latestNewMsg = newMessages[newMessages.length - 1];
                      const latestCurrentMsg = this.messages[this.messages.length - 1];
                      
                      // Actualizar si el último mensaje es diferente
                      if (latestNewMsg.id !== latestCurrentMsg.id) {
                        this.messages = newMessages;
                        this.messagesPage = 1;
                        this.messagesTotalPages = detail.messages.last_page ?? 1;
                        setTimeout(() => this.scrollToBottom(), 100);
                      }
                    } else if (newMessages.length > 0 && this.messages.length === 0) {
                      this.messages = newMessages;
                      this.messagesTotalPages = detail.messages.last_page ?? 1;
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

        // Actualizar estadísticas localmente
        this.stats.messages_today += 1;
        this.stats.outgoing_today += 1;

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

  /**
   * Verificar si el admin debe seleccionar un número antes de enviar
   */
  get isAdminWithoutNumberSelected(): boolean {
    const user = this.authService.getCurrentUser();
    return user && user.role === 'admin' && !this.selectedPhoneNumberId;
  }

  /**
   * Verificar si se puede enviar mensajes
   */
  get canSendMessages(): boolean {
    return !this.isAdminWithoutNumberSelected;
  }
}
