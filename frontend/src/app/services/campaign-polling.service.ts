import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, interval, Subscription } from 'rxjs';
import { switchMap } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { NotificationService } from './notification.service';

export interface CampaignStatus {
  id: number;
  name: string;
  status: string;
  total_contacts: number;
  sent_count: number;
  failed_count: number;
  pending_count: number;
}

@Injectable({
  providedIn: 'root'
})
export class CampaignPollingService {
  private activeCampaigns = new BehaviorSubject<Map<number, Subscription>>(new Map());
  private campaignStatuses = new BehaviorSubject<Map<number, CampaignStatus>>(new Map());
  
  public campaignStatuses$ = this.campaignStatuses.asObservable();

  // Mapa de notificaciones activas por campaña
  private notificationIds = new Map<number, string>();

  constructor(
    private http: HttpClient,
    private notificationService: NotificationService
  ) {}

  startPolling(campaignId: number, intervalMs: number = 2000) {
    // Si ya está en polling, no iniciar otro
    if (this.activeCampaigns.value.has(campaignId)) {
      return;
    }

    // Crear notificación persistente con botón para ir a campañas
    const notificationId = this.notificationService.show({
      type: 'info',
      title: '📤 Enviando mensajes',
      message: 'Preparando envío...',
      progress: 0,
      persistent: true,
      actions: [
        {
          label: 'Ver Campañas',
          callback: () => {
            window.location.hash = '/campaigns';
          }
        }
      ]
    });

    this.notificationIds.set(campaignId, notificationId);

    const subscription = interval(intervalMs)
      .pipe(
        switchMap(() => this.http.get<CampaignStatus>(`${environment.apiUrl}/campaigns/${campaignId}`))
      )
      .subscribe({
        next: (campaign) => {
          this.updateCampaignStatus(campaign);
          this.updateNotification(campaign, notificationId);

          // Si la campaña terminó, detener polling
          if (campaign.status === 'completed' || campaign.status === 'failed') {
            this.stopPolling(campaignId);
          }
        },
        error: (error) => {
          console.error('Error polling campaign:', error);
          this.stopPolling(campaignId);
        }
      });

    const current = this.activeCampaigns.value;
    current.set(campaignId, subscription);
    this.activeCampaigns.next(current);
  }

  stopPolling(campaignId: number) {
    const current = this.activeCampaigns.value;
    const subscription = current.get(campaignId);
    
    if (subscription) {
      subscription.unsubscribe();
      current.delete(campaignId);
      this.activeCampaigns.next(current);
    }

    // Obtener el estado final
    const finalStatus = this.campaignStatuses.value.get(campaignId);
    const notificationId = this.notificationIds.get(campaignId);

    if (notificationId && finalStatus) {
      // Actualizar notificación a estado final
      if (finalStatus.status === 'completed') {
        this.notificationService.update(notificationId, {
          type: 'success',
          title: '✅ Envío completado',
          message: `${finalStatus.sent_count} mensajes enviados, ${finalStatus.failed_count} fallidos`,
          progress: 100,
          persistent: false,
          actions: [
            {
              label: 'Ver Detalles',
              callback: () => {
                window.location.hash = '/campaigns';
                this.notificationService.remove(notificationId);
              }
            }
          ]
        });
      } else if (finalStatus.status === 'failed') {
        this.notificationService.update(notificationId, {
          type: 'error',
          title: '❌ Envío fallido',
          message: `Error en la campaña "${finalStatus.name}"`,
          persistent: false,
          actions: [
            {
              label: 'Ver Detalles',
              callback: () => {
                window.location.hash = '/campaigns';
                this.notificationService.remove(notificationId);
              }
            }
          ]
        });
      }

      // Eliminar después de mostrar resultado (solo si no interactúa)
      setTimeout(() => {
        this.notificationService.remove(notificationId);
        this.notificationIds.delete(campaignId);
      }, 5000);
    }
  }

  private updateCampaignStatus(campaign: CampaignStatus) {
    const current = this.campaignStatuses.value;
    current.set(campaign.id, campaign);
    this.campaignStatuses.next(new Map(current));
  }

  private updateNotification(campaign: CampaignStatus, notificationId: string) {
    const progress = campaign.total_contacts > 0
      ? Math.round(((campaign.sent_count + campaign.failed_count) / campaign.total_contacts) * 100)
      : 0;

    this.notificationService.update(notificationId, {
      title: `📤 Enviando: ${campaign.name}`,
      message: `${campaign.sent_count} enviados, ${campaign.failed_count} fallidos, ${campaign.pending_count} pendientes`,
      progress
    });
  }

  getCampaignStatus(campaignId: number): CampaignStatus | undefined {
    return this.campaignStatuses.value.get(campaignId);
  }

  stopAllPolling() {
    this.activeCampaigns.value.forEach(sub => sub.unsubscribe());
    this.activeCampaigns.next(new Map());
    this.notificationIds.forEach(id => this.notificationService.remove(id));
    this.notificationIds.clear();
  }
}
