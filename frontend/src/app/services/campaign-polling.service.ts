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

  constructor(
    private http: HttpClient,
    private notificationService: NotificationService
  ) {}

  startPolling(campaignId: number, intervalMs: number = 2000) {
    // Si ya está en polling, no iniciar otro
    if (this.activeCampaigns.value.has(campaignId)) {
      return;
    }

    const subscription = interval(intervalMs)
      .pipe(
        switchMap(() => this.http.get<CampaignStatus>(`${environment.apiUrl}/campaigns/${campaignId}`))
      )
      .subscribe({
        next: (campaign) => {
          this.updateCampaignStatus(campaign);

          // Si la campaña terminó, mostrar notificación solo si fue exitosa
          if (campaign.status === 'completed' || campaign.status === 'failed') {
            // Solo notificar si fue completada exitosamente
            if (campaign.status === 'completed') {
              this.notificationService.show({
                type: 'success',
                title: '✅ Envío completado',
                message: `${campaign.name}: ${campaign.sent_count} mensajes enviados${campaign.failed_count > 0 ? ', ' + campaign.failed_count + ' fallidos' : ''}`,
                persistent: false
              });
            }
            
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
  }

  private updateCampaignStatus(campaign: CampaignStatus) {
    const current = this.campaignStatuses.value;
    current.set(campaign.id, campaign);
    this.campaignStatuses.next(new Map(current));
  }

  getCampaignStatus(campaignId: number): CampaignStatus | undefined {
    return this.campaignStatuses.value.get(campaignId);
  }

  stopAllPolling() {
    this.activeCampaigns.value.forEach(sub => sub.unsubscribe());
    this.activeCampaigns.next(new Map());
  }
}
