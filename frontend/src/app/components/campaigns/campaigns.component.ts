import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CampaignService, Campaign, WhatsAppNumber } from '../../services/campaign.service';
import { ContactService, Contact } from '../../services/contact.service';
import { TemplateService, WhatsAppTemplate } from '../../services/template.service';
import { CampaignPollingService } from '../../services/campaign-polling.service';
import { NotificationService } from '../../services/notification.service';
import { AuthService } from '../../services/auth.service';
import { ChannelService } from '../../services/channel.service';
import { Subscription } from 'rxjs';
import * as XLSX from 'xlsx';

@Component({
  selector: 'app-campaigns',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './campaigns.component.html',
  styleUrls: ['./campaigns.component.css']
})
export class CampaignsComponent implements OnInit, OnDestroy {
  campaigns: Campaign[] = [];
  availableContacts: Contact[] = [];
  availableNumbers: WhatsAppNumber[] = [];
  selectedCampaign: Campaign | null = null;
  templates: WhatsAppTemplate[] = [];
  selectedTemplate: WhatsAppTemplate | null = null;
  templateParameters: string[] = [];
  hasVideoHeader = false;
  hasImageHeader = false;
  imageInputType: 'link' | 'file' = 'link';
  selectedImageFile: File | null = null;
  isUploadingImage = false;

  showCreateModal = false;
  contactSearch = '';
  useTemplate = false;
  selectedPhoneNumberId: string = '';
  isCreatingCampaign = false;

  showImportContactsModal = false;
  selectedContactsFile: File | null = null;
  importContactsResult: any = null;

  private pollingSubscription?: Subscription;

  campaignForm = {
    name: '',
    phone_number_id: '',
    phone_number_name: '',
    message: '',
    template_name: '',
    template_parameters: [] as string[],
    video_link: '',
    image_link: '',
    image_media_id: '',
    contact_ids: [] as number[]
  };

  // Channel control
  isWhatsAppOrSMS = true;
  channelSubscription?: Subscription;
  // pollingSubscription is already defined above

  constructor(
    private campaignService: CampaignService,
    private contactService: ContactService,
    private templateService: TemplateService,
    private pollingService: CampaignPollingService,
    private notificationService: NotificationService,
    private authService: AuthService,
    private channelService: ChannelService
  ) {
    // campaignForm is initialized inline
  }

  ngOnInit(): void {
    // Subscribe to channel changes
    this.channelSubscription = this.channelService.selectedChannel$.subscribe(channel => {
      this.isWhatsAppOrSMS = channel.id === 'whatsapp' || channel.id === 'sms';
    });

    // Auto-seleccionar número si el usuario no es admin
    const user = this.authService.getCurrentUser();
    if (user && user.role !== 'admin' && user.phone_number_id) {
      this.selectedPhoneNumberId = user.phone_number_id;
    }

    this.loadCampaigns();
    this.loadAvailableNumbers();
    this.loadContacts();
    this.loadTemplates(); 

    this.startAutoRefresh();
  }

  ngOnDestroy(): void {
    if (this.pollingSubscription) {
      this.pollingSubscription.unsubscribe();
    }
    if (this.channelSubscription) {
      this.channelSubscription.unsubscribe();
    }
    // NO detener el polling al salir, las notificaciones deben continuar
    // this.pollingService.stopAllPolling();
  }

  startAutoRefresh() {
    // Suscribirse a actualizaciones de campaña
    this.pollingSubscription = this.pollingService.campaignStatuses$.subscribe(statuses => {
      // Actualizar campañas con los nuevos estados
      statuses.forEach((status, campaignId) => {
        const campaign = this.campaigns.find(c => c.id === campaignId);
        if (campaign) {
          campaign.status = status.status as 'pending' | 'processing' | 'completed' | 'failed';
          campaign.sent_count = status.sent_count;
          campaign.failed_count = status.failed_count;
          campaign.pending_count = status.pending_count;
        }
      });
    });
  }

  loadCampaigns() {
    const phoneNumberId = this.selectedPhoneNumberId || null;
    this.campaignService.getCampaigns(1, 20, phoneNumberId).subscribe({
      next: (response) => {
        this.campaigns = response.data;
      },
      error: (error) => console.error('Error loading campaigns:', error)
    });
  }

  onPhoneNumberFilterChange() {
    this.loadCampaigns();
    this.loadTemplates(this.selectedPhoneNumberId);
  }

  loadContacts() {
    this.contactService.getContacts(1, 1000).subscribe({
      next: (response) => {
        this.availableContacts = response.data;
      },
      error: (error) => console.error('Error loading contacts:', error)
    });
  }

  loadTemplates(phoneNumberId?: string) {
    this.templateService.getTemplates(phoneNumberId).subscribe({
      next: (response) => {
        this.templates = response.templates;
      },
      error: (error) => console.error('Error loading templates:', error)
    });
  }

  loadAvailableNumbers() {
    this.campaignService.getAvailableNumbers().subscribe({
      next: (response) => {
        this.availableNumbers = response.numbers;
        if (this.availableNumbers.length > 0 && !this.campaignForm.phone_number_id) {
          this.campaignForm.phone_number_id = this.availableNumbers[0].id;
          this.campaignForm.phone_number_name = this.availableNumbers[0].name;
        }
      },
      error: (error) => console.error('Error loading available numbers:', error)
    });
  }

  onPhoneNumberSelected(event: any) {
    const numberId = event.target.value;
    const number = this.availableNumbers.find(n => n.id === numberId);
    if (number) {
      this.campaignForm.phone_number_name = number.name;
      this.campaignForm.phone_number_id = number.id;
      this.loadTemplates(number.id);
    }
  }

  onTemplateSelected(event: any) {
    const templateName = event.target.value;
    this.selectedTemplate = this.templates.find(t => t.name === templateName) || null;

    if (this.selectedTemplate) {
      this.campaignForm.template_name = this.selectedTemplate.name;
      const params = this.templateService.getTemplateParameters(this.selectedTemplate);
      this.templateParameters = params;
      this.campaignForm.template_parameters = new Array(params.length).fill('');

      // Chequear si tiene header de video
      const header = this.selectedTemplate.components.find(c => c.type === 'HEADER');
      this.hasVideoHeader = header?.format === 'VIDEO';
      if (!this.hasVideoHeader) {
        this.campaignForm.video_link = '';
      }

      // Chequear si tiene header de imagen
      this.hasImageHeader = header?.format === 'IMAGE';
      if (!this.hasImageHeader) {
        this.campaignForm.image_link = '';
        this.campaignForm.image_media_id = '';
        this.selectedImageFile = null;
        this.imageInputType = 'link';
      }
    }
  }

  getTemplateBodyText(): string {
    if (!this.selectedTemplate) return 'Sin contenido';
    const bodyComponent = this.selectedTemplate.components.find(c => c.type === 'BODY');
    return bodyComponent?.text || 'Sin contenido';
  }

  searchContacts() {
    this.contactService.getContacts(1, 100, this.contactSearch).subscribe({
      next: (response) => {
        this.availableContacts = response.data;
      }
    });
  }

  isContactSelected(id: number): boolean {
    return this.campaignForm.contact_ids.includes(id);
  }

  toggleContact(id: number) {
    const index = this.campaignForm.contact_ids.indexOf(id);
    if (index > -1) {
      this.campaignForm.contact_ids.splice(index, 1);
    } else {
      this.campaignForm.contact_ids.push(id);
    }
  }

  selectAll() {
    this.campaignForm.contact_ids = this.availableContacts.map(c => c.id);
  }

  deselectAll() {
    this.campaignForm.contact_ids = [];
  }

  canCreateCampaign(): boolean {
    if (this.useTemplate) {
      const baseValid = !!(this.campaignForm.name &&
        this.campaignForm.phone_number_id &&
        this.campaignForm.template_name &&
        this.campaignForm.contact_ids.length > 0 &&
        this.campaignForm.template_parameters.every(p => p.trim() !== ''));
      
      const videoValid = !this.hasVideoHeader || (this.hasVideoHeader && !!this.campaignForm.video_link);
      
      const imageValid = !this.hasImageHeader || (this.hasImageHeader && (
        (this.imageInputType === 'link' && !!this.campaignForm.image_link) ||
        (this.imageInputType === 'file' && !!this.campaignForm.image_media_id)
      ));
      
      return baseValid && videoValid && imageValid;
    } else {
      return !!(this.campaignForm.name &&
        this.campaignForm.phone_number_id &&
        this.campaignForm.message &&
        this.campaignForm.contact_ids.length > 0);
    }
  }

  createCampaign() {
    if (!this.canCreateCampaign() || this.isCreatingCampaign) return;

    this.isCreatingCampaign = true;

    this.campaignService.createCampaign(this.campaignForm).subscribe({
      next: (response) => {
        const campaignId = response.data.id;

        this.isCreatingCampaign = false;
        this.closeCreateModal();
        this.loadCampaigns();

        // Iniciar polling automático para esta campaña
        this.pollingService.startPolling(campaignId, 2000);

        this.notificationService.show({
          type: 'success',
          title: 'Campaña creada',
          message: 'La campaña se está enviando correctamente'
        });
      },
      error: (error) => {
        console.error('Error creating campaign:', error);
        this.isCreatingCampaign = false;
        this.notificationService.show({
          type: 'error',
          title: 'Error',
          message: 'Error al crear la campaña: ' + (error.error?.message || 'Error desconocido')
        });
      }
    });
  }

  closeCreateModal() {
    this.showCreateModal = false;
    this.isCreatingCampaign = false;
    this.campaignForm = {
      name: '',
      phone_number_id: this.availableNumbers.length > 0 ? this.availableNumbers[0].id : '',
      phone_number_name: this.availableNumbers.length > 0 ? this.availableNumbers[0].name : '',
      message: '',
      template_name: '',
      template_parameters: [],
      video_link: '',
      image_link: '',
      image_media_id: '',
      contact_ids: []
    };
    this.contactSearch = '';
    this.useTemplate = false;
    this.selectedTemplate = null;
    this.templateParameters = [];
    this.hasVideoHeader = false;
    this.hasImageHeader = false;
    this.selectedImageFile = null;
    this.imageInputType = 'link';
  }

  viewDetails(campaign: Campaign) {
    this.campaignService.getCampaignDetails(campaign.id).subscribe({
      next: (data) => {
        this.selectedCampaign = data;
      }
    });
  }

  retryFailed(campaign: Campaign) {
    if (!confirm(`¿Reintentar envío de ${campaign.failed_count} mensajes fallidos?`)) return;

    this.campaignService.retryFailed(campaign.id).subscribe({
      next: () => {
        alert('Reintentando mensajes fallidos...');
        this.loadCampaigns();
      }
    });
  }

  deleteCampaign(campaign: Campaign) {
    if (!confirm(`¿Eliminar campaña "${campaign.name}"?`)) return;

    this.campaignService.deleteCampaign(campaign.id).subscribe({
      next: () => {
        this.loadCampaigns();
      }
    });
  }

  getProgress(campaign: Campaign): number {
    if (campaign.total_contacts === 0) return 0;
    const completed = campaign.sent_count + campaign.failed_count;
    return Math.round((completed / campaign.total_contacts) * 100);
  }

  onContactsFileSelected(event: any) {
    this.selectedContactsFile = event.target.files[0];
    this.importContactsResult = null;
  }

  importContactsFromExcel() {
    if (!this.selectedContactsFile) return;

    this.contactService.getContactsFromExcel(this.selectedContactsFile).subscribe({
      next: (result) => {
        this.importContactsResult = result;
        if (result.success && result.contacts) {
          // Seleccionar automáticamente los contactos encontrados
          result.contacts.forEach((contact: Contact) => {
            if (!this.campaignForm.contact_ids.includes(contact.id)) {
              this.campaignForm.contact_ids.push(contact.id);
            }
          });

          // Cerrar el modal después de 2 segundos si fue exitoso
          if (result.found > 0) {
            setTimeout(() => {
              this.closeImportContactsModal();
            }, 2000);
          }
        }
      },
      error: (error) => {
        this.importContactsResult = {
          success: false,
          error: error.error?.message || 'Error al procesar archivo'
        };
      }
    });
  }

  closeImportContactsModal() {
    this.showImportContactsModal = false;
    this.selectedContactsFile = null;
    this.importContactsResult = null;
  }

  /**
   * Obtener mensajes exitosos (sent, delivered, read)
   */
  getSuccessfulMessages(): any[] {
    if (!this.selectedCampaign || !this.selectedCampaign.messages) return [];
    const successStatuses = ['sent', 'delivered', 'read'];
    return this.selectedCampaign.messages.filter(m => successStatuses.includes(m.status));
  }

  /**
   * Obtener mensajes fallidos
   */
  getFailedMessages(): any[] {
    if (!this.selectedCampaign || !this.selectedCampaign.messages) return [];
    return this.selectedCampaign.messages.filter(m => m.status === 'failed');
  }

  /**
   * Descargar Excel con filtro
   * @param filter 'all' | 'success' | 'failed'
   */
  downloadExcel(filter: 'all' | 'success' | 'failed') {
    if (!this.selectedCampaign || !this.selectedCampaign.messages) return;

    let messagesToExport: any[] = [];
    let fileName = '';

    switch (filter) {
      case 'all':
        messagesToExport = this.selectedCampaign.messages;
        fileName = `Campaña_${this.selectedCampaign.name}_Todos`;
        break;
      case 'success':
        messagesToExport = this.getSuccessfulMessages();
        fileName = `Campaña_${this.selectedCampaign.name}_Exitosos`;
        break;
      case 'failed':
        messagesToExport = this.getFailedMessages();
        fileName = `Campaña_${this.selectedCampaign.name}_Fallidos`;
        break;
    }

    if (messagesToExport.length === 0) {
      this.notificationService.show({
        type: 'warning',
        title: 'Sin datos',
        message: 'No hay mensajes para exportar con el filtro seleccionado'
      });
      return;
    }

    // Preparar datos para Excel: Número, Nombre, Correo, Estado
    const excelData = messagesToExport.map(message => {
      return {
        'Número': message.phone_number || '',
        'Nombre': message.contact?.name || '',
        'Correo': message.contact?.email || '',
        'Estado': this.translateStatus(message.status)
      };
    });

    // Crear hoja de cálculo
    const worksheet = XLSX.utils.json_to_sheet(excelData);

    // Ajustar ancho de columnas
    const columnWidths = [
      { wch: 20 }, // Número
      { wch: 25 }, // Nombre
      { wch: 30 }, // Correo
      { wch: 15 }  // Estado
    ];
    worksheet['!cols'] = columnWidths;

    // Crear libro de trabajo
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Mensajes');

    // Generar archivo y descargarlo
    const timestamp = new Date().toISOString().split('T')[0];
    XLSX.writeFile(workbook, `${fileName}_${timestamp}.xlsx`);

    this.notificationService.show({
      type: 'success',
      title: 'Excel descargado',
      message: `Se han exportado ${messagesToExport.length} registros`
    });
  }

  /**
   * Traducir estado del mensaje al español
   */
  private translateStatus(status: string): string {
    const translations: { [key: string]: string } = {
      'pending': 'Pendiente',
      'sent': 'Enviado',
      'delivered': 'Entregado',
      'read': 'Leído',
      'failed': 'Fallido'
    };
    return translations[status] || status;
  }

  onImageInputTypeChange(type: 'link' | 'file') {
    this.imageInputType = type;
    // Limpiar datos del tipo anterior
    if (type === 'link') {
      this.selectedImageFile = null;
      this.campaignForm.image_media_id = '';
    } else {
      this.campaignForm.image_link = '';
    }
  }

  onImageFileSelected(event: any) {
    const file = event.target.files[0];
    if (file) {
      // Validar tipo de archivo
      if (!file.type.match(/image\/(jpeg|jpg|png)/)) {
        this.notificationService.show({
          type: 'error',
          title: 'Archivo inválido',
          message: 'Solo se permiten imágenes JPEG o PNG'
        });
        event.target.value = '';
        return;
      }

      // Validar tamaño (5MB máx)
      if (file.size > 5 * 1024 * 1024) {
        this.notificationService.show({
          type: 'error',
          title: 'Archivo muy grande',
          message: 'La imagen no debe superar 5MB'
        });
        event.target.value = '';
        return;
      }

      this.selectedImageFile = file;
      this.uploadImageFile();
    }
  }

  uploadImageFile() {
    if (!this.selectedImageFile || !this.campaignForm.phone_number_id) return;

    this.isUploadingImage = true;

    this.campaignService.uploadMedia(this.selectedImageFile, this.campaignForm.phone_number_id).subscribe({
      next: (response) => {
        this.isUploadingImage = false;
        this.campaignForm.image_media_id = response.media_id;
        this.notificationService.show({
          type: 'success',
          title: 'Imagen subida',
          message: 'La imagen se subió correctamente a WhatsApp'
        });
      },
      error: (error) => {
        this.isUploadingImage = false;
        this.selectedImageFile = null;
        this.notificationService.show({
          type: 'error',
          title: 'Error al subir imagen',
          message: error.error?.message || 'No se pudo subir la imagen'
        });
      }
    });
  }

  getReadRate(campaign: Campaign): number {
    if (campaign.sent_count === 0) return 0;
    return Math.round(((campaign.read_count || 0) / campaign.sent_count) * 100);
  }

  getReplyRate(campaign: Campaign): number {
    if (campaign.sent_count === 0) return 0;
    return Math.round(((campaign.replied_count || 0) / campaign.sent_count) * 100);
  }

  getNotOpenedCount(campaign: Campaign): number {
    // Entregados pero no leídos = sent - read
    return campaign.sent_count - (campaign.read_count || 0);
  }
}
