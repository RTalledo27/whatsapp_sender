import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StatisticsService, Statistics } from '../../services/statistics.service';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="dashboard">
      <h1>Dashboard - Envío Masivo WhatsApp</h1>

      <!-- Cards superiores personalizadas (solo para admins) -->
      <div class="top-cards" *ngIf="isAdmin()">
        <div class="top-card" [class.selected]="selectedPhoneNumberId === null" (click)="selectCard(null)">
          <div class="top-card-title">General</div>
        </div>
        <div class="top-card" [class.selected]="selectedPhoneNumberId === statistics?.overview?.customer_service_id" (click)="selectCard(statistics?.overview?.customer_service_id || null)">
          <div class="top-card-title">Atención al Cliente</div>
          <div class="top-card-content">
            <span *ngIf="statistics?.overview?.customer_service_number">{{ statistics?.overview?.customer_service_number }}</span>
          </div>
        </div>
        <div class="top-card" [class.selected]="selectedPhoneNumberId === statistics?.overview?.community_id" (click)="selectCard(statistics?.overview?.community_id || null)">
          <div class="top-card-title">Comunidad</div>
          <div class="top-card-content">
            <span *ngIf="statistics?.overview?.community_number">{{ statistics?.overview?.community_number }}</span>
          </div>
        </div>
      </div>

      <div class="stats-grid" *ngIf="statistics">
        <div class="stat-card">
          <svg class="stat-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <line x1="12" y1="18" x2="12.01" y2="18"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.overview.total_contacts }}</div>
            <div class="stat-label">Total Contactos</div>
          </div>
        </div>

        <div class="stat-card">
          <svg class="stat-icon" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.overview.total_campaigns }}</div>
            <div class="stat-label">Campañas</div>
          </div>
        </div>

        <div class="stat-card success">
          <svg class="stat-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.overview.messages_sent }}</div>
            <div class="stat-label">Mensajes Enviados</div>
          </div>
        </div>

        <div class="stat-card error">
          <svg class="stat-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.overview.messages_failed }}</div>
            <div class="stat-label">Mensajes Fallidos</div>
          </div>
        </div>

        <div class="stat-card warning">
          <svg class="stat-icon" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"/>
            <polyline points="12 6 12 12 16 14"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.overview.messages_pending }}</div>
            <div class="stat-label">Mensajes Pendientes</div>
          </div>
        </div>

        <div class="stat-card info">
          <svg class="stat-icon" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
            <polyline points="17 6 23 6 23 12"/>
          </svg>
          <div class="stat-content">
            <div class="stat-value">{{ statistics.success_rate }}%</div>
            <div class="stat-label">Tasa de Éxito</div>
          </div>
        </div>
      </div>

      <div class="recent-section">
        <h2>Campañas Recientes</h2>
        <div class="campaigns-list" *ngIf="(statistics?.recent_campaigns?.length ?? 0) > 0">
          <div class="campaign-item" *ngFor="let campaign of statistics!.recent_campaigns">
            <div class="campaign-info">
              <div class="campaign-name">{{ campaign.name }}</div>
              <div class="campaign-date">{{ campaign.created_at | date:'short' }}</div>
            </div>
            <div class="campaign-stats">
              <span class="badge" [class.badge-success]="campaign.status === 'completed'"
                    [class.badge-warning]="campaign.status === 'processing'"
                    [class.badge-error]="campaign.status === 'failed'">
                {{ campaign.status }}
              </span>
              <span class="badge">{{ campaign.sent_count }}/{{ campaign.total_contacts }} enviados</span>
            </div>
          </div>
        </div>
        <div *ngIf="!statistics || !statistics.recent_campaigns || statistics.recent_campaigns.length === 0" class="empty-state">
          No hay campañas recientes
        </div>
      </div>

      <div class="messages-chart" *ngIf="(statistics?.messages_by_day?.length ?? 0) > 0">
        <h2>Mensajes por Día (Últimos 7 días)</h2>
        <div class="chart-container">
          <div class="bar-chart">
            <div class="bar-item" *ngFor="let day of statistics!.messages_by_day">
              <div class="bar-wrapper">
                <div class="bar-stack">
                  <div class="bar-column">
                    <span class="bar-value-top" *ngIf="day.sent > 0">{{ day.sent }}</span>
                    <div class="bar bar-sent" 
                         [style.height.px]="(day.sent / getMaxMessages()) * 180">
                    </div>
                  </div>
                  <div class="bar-column">
                    <span class="bar-value-top" *ngIf="day.failed > 0">{{ day.failed }}</span>
                    <div class="bar bar-failed" 
                         [style.height.px]="(day.failed / getMaxMessages()) * 180">
                    </div>
                  </div>
                </div>
              </div>
              <div class="bar-label">{{ day.date | date:'dd/MM' }}</div>
            </div>
          </div>
          <div class="chart-legend">
            <div class="legend-item">
              <div class="legend-color bar-sent"></div>
              <span>Enviados</span>
            </div>
            <div class="legend-item">
              <div class="legend-color bar-failed"></div>
              <span>Fallidos</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .dashboard {
      padding: 20px;
    }

    .top-cards {
      display: flex;
      gap: 20px;
      margin-bottom: 30px;
    }
    .top-card {
      background: #f3f4f6;
      border-radius: 10px;
      padding: 18px 28px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.07);
      min-width: 180px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      cursor: pointer;
      border: 2px solid transparent;
      transition: border 0.2s;
    }
    .top-card.selected {
      border: 2px solid #3b82f6;
      background: #e0e7ff;
    }
    .top-card-title {
      font-size: 1.1em;
      font-weight: 600;
      color: #374151;
      margin-bottom: 6px;
    }
    .top-card-content {
      font-size: 0.95em;
      color: #555;
    }

    h1 {
      margin-bottom: 30px;
      color: #333;
    }

    h2 {
      margin: 30px 0 20px;
      color: #555;
      font-size: 1.4em;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
      margin-bottom: 40px;
    }

    @media (max-width: 1024px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 640px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    .stat-card {
      background: white;
      padding: 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      display: flex;
      align-items: center;
      gap: 18px;
      transition: all 0.3s ease;
      border-left: 4px solid #e5e7eb;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 80px;
      height: 80px;
      border-radius: 50%;
      opacity: 0.05;
      transform: translate(30%, -30%);
    }

    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 16px rgba(0,0,0,0.12);
    }

    .stat-card.success { 
      border-left-color: #22c55e;
    }
    
    .stat-card.success::before {
      background: #22c55e;
    }

    .stat-card.success .stat-icon {
      color: #22c55e;
    }

    .stat-card.error { 
      border-left-color: #ef4444;
    }
    
    .stat-card.error::before {
      background: #ef4444;
    }

    .stat-card.error .stat-icon {
      color: #ef4444;
    }

    .stat-card.warning { 
      border-left-color: #f59e0b;
    }
    
    .stat-card.warning::before {
      background: #f59e0b;
    }

    .stat-card.warning .stat-icon {
      color: #f59e0b;
    }

    .stat-card.info { 
      border-left-color: #3b82f6;
    }
    
    .stat-card.info::before {
      background: #3b82f6;
    }

    .stat-card.info .stat-icon {
      color: #3b82f6;
    }

    .stat-card:not(.success):not(.error):not(.warning):not(.info) {
      border-left-color: #8b5cf6;
    }

    .stat-card:not(.success):not(.error):not(.warning):not(.info)::before {
      background: #8b5cf6;
    }

    .stat-card:not(.success):not(.error):not(.warning):not(.info) .stat-icon {
      color: #8b5cf6;
    }

    .stat-icon {
      flex-shrink: 0;
      opacity: 0.9;
    }

    .stat-content {
      flex: 1;
      min-width: 0;
    }

    .stat-value {
      font-size: 2.2em;
      font-weight: 700;
      color: #1f2937;
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 0.9em;
      color: #6b7280;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .recent-section {
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      margin-bottom: 30px;
    }

    .campaigns-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .campaign-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px;
      background: #f9fafb;
      border-radius: 6px;
    }

    .campaign-name {
      font-weight: 600;
      color: #333;
    }

    .campaign-date {
      font-size: 0.85em;
      color: #666;
      margin-top: 4px;
    }

    .campaign-stats {
      display: flex;
      gap: 10px;
    }

    .badge {
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 0.85em;
      background: #e5e7eb;
      color: #4b5563;
    }

    .badge-success {
      background: #dcfce7;
      color: #16a34a;
    }

    .badge-warning {
      background: #fef3c7;
      color: #d97706;
    }

    .badge-error {
      background: #fee2e2;
      color: #dc2626;
    }

    .empty-state {
      text-align: center;
      padding: 40px;
      color: #9ca3af;
    }

    .messages-chart {
      background: white;
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .chart-container {
      margin-top: 30px;
    }

    .bar-chart {
      display: flex;
      justify-content: space-around;
      align-items: flex-end;
      height: 250px;
      padding: 20px;
      background: #f9fafb;
      border-radius: 8px;
      position: relative;
    }

    .bar-chart::before {
      content: '';
      position: absolute;
      bottom: 20px;
      left: 20px;
      right: 20px;
      height: 1px;
      background: #d1d5db;
    }

    .bar-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      position: relative;
    }

    .bar-wrapper {
      display: flex;
      align-items: flex-end;
      height: 200px;
      width: 100%;
      justify-content: center;
    }

    .bar-stack {
      display: flex;
      gap: 6px;
      align-items: flex-end;
      height: 100%;
    }

    .bar-column {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }

    .bar {
      width: 35px;
      min-height: 4px;
      border-radius: 6px 6px 0 0;
      position: relative;
      transition: all 0.3s ease;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .bar:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .bar-value-top {
      color: #374151;
      font-size: 0.75em;
      font-weight: 700;
      background: rgba(255, 255, 255, 0.9);
      padding: 2px 6px;
      border-radius: 4px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      min-width: 24px;
      text-align: center;
    }

    .bar-sent {
      background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
    }

    .bar-failed {
      background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%);
    }

    .bar-label {
      font-size: 0.8em;
      color: #6b7280;
      font-weight: 500;
      text-align: center;
    }

    .chart-legend {
      display: flex;
      justify-content: center;
      gap: 30px;
      margin-top: 20px;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .legend-color {
      width: 20px;
      height: 20px;
      border-radius: 4px;
    }
  `]
})
export class DashboardComponent implements OnInit {
  statistics: Statistics | null = null;
  selectedPhoneNumberId: string | null = null;

  constructor(
    private statisticsService: StatisticsService,
    private authService: AuthService
  ) {}

  ngOnInit() {
    // Si no es admin, cargar automáticamente las estadísticas filtradas por su phone_number_id
    if (!this.isAdmin()) {
      const user = this.authService.getCurrentUser();
      if (user && user.phone_number_id) {
        this.selectedPhoneNumberId = user.phone_number_id;
      }
    }
    this.loadStatistics();
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

  isAdmin(): boolean {
    const user = this.authService.getCurrentUser();
    return user && user.role === 'admin';
  }

  getMaxMessages(): number {
    if (!this.statistics?.messages_by_day) return 1;
    return Math.max(...this.statistics.messages_by_day.map(d => d.total), 1);
  }
}
