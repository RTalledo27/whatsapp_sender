import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StatisticsService, Statistics } from '../../services/statistics.service';
import { AuthService } from '../../services/auth.service';
import { ChannelService } from '../../services/channel.service';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.css']
})
export class DashboardComponent implements OnInit {
  statistics: Statistics | null = null;
  selectedPhoneNumberId: string | null = null;

  // Channel control
  isWhatsAppOrSMS = true;
  channelSubscription?: Subscription;
  selectedEmailCategory: string | null = null;

  // Placeholder metrics for Email
  emailMetrics = {
    sent: 0,
    opened: 0,
    clicked: 0,
    bounced: 0,
    openRate: 0,
    clickRate: 0
  };

  constructor(
    private statisticsService: StatisticsService,
    private authService: AuthService,
    private channelService: ChannelService
  ) {}

  ngOnInit() {
    // Subscribe to channel changes
    this.channelSubscription = this.channelService.selectedChannel$.subscribe(channel => {
      this.isWhatsAppOrSMS = channel.id === 'whatsapp' || channel.id === 'sms';
    });

    // Si no es admin, cargar automáticamente las estadísticas filtradas por su phone_number_id
    if (!this.isAdmin()) {
      const user = this.authService.getCurrentUser();
      if (user && user.phone_number_id) {
        this.selectedPhoneNumberId = user.phone_number_id;
      }
    }
    this.loadStatistics();
  }
  
  ngOnDestroy() {
    if (this.channelSubscription) {
      this.channelSubscription.unsubscribe();
    }
  }

  loadStatistics() {
    this.statisticsService.getStatistics(this.selectedPhoneNumberId).subscribe({
      next: (data) => {
        this.statistics = data;
      },
      error: (error) => {
        console.error('Error loading statistics:', error);
      }
    });
  }

  selectCard(phoneNumberId: string | null) {
    this.selectedPhoneNumberId = phoneNumberId;
    this.loadStatistics();
  }

  selectEmailCard(category: string | null) {
    this.selectedEmailCategory = category;
    // Aquí se cargaría la información filtrada por categoría de email
    console.log('Filtrando por categoría de email:', category);
  }

  isAdmin(): boolean {
    const user = this.authService.getCurrentUser();
    return user && user.role === 'admin';
  }

  getMaxMessages(): number {
    if (!this.statistics?.messages_by_day) return 1;
    return Math.max(...this.statistics.messages_by_day.map(d => d.total), 1);
  }
}
