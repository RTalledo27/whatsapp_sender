import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StatisticsService, Statistics } from '../../services/statistics.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="dashboard">
      <h1>Dashboard - Envío Masivo WhatsApp</h1>

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
              <div class="bar-label">{{ day.date | date:'shortDate' }}</div>
              <div class="bar-wrapper">
                <div class="bar bar-sent" 
                     [style.height.px]="(day.sent / getMaxMessages()) * 200">
                  {{ day.sent }}
                </div>
                <div class="bar bar-failed" 
                     [style.height.px]="(day.failed / getMaxMessages()) * 200">
                  {{ day.failed }}
                </div>
              </div>
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
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }

    .stat-card {
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .stat-card.success { border-left: 4px solid #22c55e; }
    .stat-card.error { border-left: 4px solid #ef4444; }
    .stat-card.warning { border-left: 4px solid #f59e0b; }
    .stat-card.info { border-left: 4px solid #3b82f6; }

    .stat-icon {
      font-size: 2em;
    }

    .stat-value {
      font-size: 2em;
      font-weight: bold;
      color: #333;
    }

    .stat-label {
      font-size: 0.9em;
      color: #666;
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
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .chart-container {
      margin-top: 20px;
    }

    .bar-chart {
      display: flex;
      justify-content: space-around;
      align-items: flex-end;
      height: 220px;
      padding: 20px;
      border-bottom: 2px solid #e5e7eb;
    }

    .bar-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .bar-wrapper {
      display: flex;
      gap: 5px;
      align-items: flex-end;
      height: 200px;
    }

    .bar {
      width: 30px;
      min-height: 5px;
      border-radius: 4px 4px 0 0;
      display: flex;
      align-items: flex-end;
      justify-content: center;
      color: white;
      font-size: 0.75em;
      font-weight: bold;
      padding-bottom: 5px;
    }

    .bar-sent {
      background: #22c55e;
    }

    .bar-failed {
      background: #ef4444;
    }

    .bar-label {
      font-size: 0.75em;
      color: #666;
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

  constructor(private statisticsService: StatisticsService) {}

  ngOnInit() {
    this.loadStatistics();
  }

  loadStatistics() {
    this.statisticsService.getStatistics().subscribe({
      next: (data) => {
        this.statistics = data;
      },
      error: (error) => {
        console.error('Error loading statistics:', error);
      }
    });
  }

  getMaxMessages(): number {
    if (!this.statistics?.messages_by_day) return 1;
    return Math.max(...this.statistics.messages_by_day.map(d => d.total), 1);
  }
}
