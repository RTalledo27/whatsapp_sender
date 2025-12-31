import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NotificationService, Notification } from '../../services/notification.service';

@Component({
  selector: 'app-notification-toast',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="notification-container">
      <div 
        *ngFor="let notification of notifications$ | async"
        class="notification"
        [class.info]="notification.type === 'info'"
        [class.success]="notification.type === 'success'"
        [class.warning]="notification.type === 'warning'"
        [class.error]="notification.type === 'error'"
      >
        <div class="notification-header">
          <strong>{{ notification.title }}</strong>
          <button 
            class="close-btn"
            (click)="close(notification.id)"
            [title]="notification.persistent ? 'Ocultar notificación' : 'Cerrar'"
          >
            ×
          </button>
        </div>
        
        <p class="notification-message">{{ notification.message }}</p>
        
        <div class="progress-bar" *ngIf="notification.progress !== undefined">
          <div 
            class="progress-fill"
            [style.width.%]="notification.progress"
          ></div>
          <span class="progress-text">{{ notification.progress }}%</span>
        </div>

        <div class="notification-actions" *ngIf="notification.actions && notification.actions.length">
          <button 
            *ngFor="let action of notification.actions"
            class="action-btn"
            (click)="action.callback()"
          >
            {{ action.label }}
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .notification-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
      max-width: 400px;
      width: 100%;
    }

    .notification {
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      margin-bottom: 12px;
      padding: 16px;
      border-left: 4px solid;
      animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
      from {
        transform: translateX(400px);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    .notification.info { border-left-color: #2196F3; }
    .notification.success { border-left-color: #4CAF50; }
    .notification.warning { border-left-color: #FF9800; }
    .notification.error { border-left-color: #F44336; }

    .notification-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }

    .notification-header strong {
      color: #333;
      font-size: 14px;
    }

    .close-btn {
      background: none;
      border: none;
      font-size: 24px;
      color: #999;
      cursor: pointer;
      padding: 0;
      width: 24px;
      height: 24px;
      line-height: 20px;
    }

    .close-btn:hover {
      color: #333;
    }

    .notification-message {
      color: #666;
      font-size: 13px;
      margin: 0 0 12px 0;
    }

    .progress-bar {
      position: relative;
      height: 24px;
      background: #f0f0f0;
      border-radius: 12px;
      overflow: hidden;
      margin-top: 12px;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #4CAF50, #8BC34A);
      transition: width 0.3s ease;
    }

    .progress-text {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: #333;
      font-weight: bold;
      font-size: 12px;
    }

    .notification-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
    }

    .action-btn {
      padding: 6px 12px;
      background: #2196F3;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
    }

    .action-btn:hover {
      background: #1976D2;
    }
  `]
})
export class NotificationToastComponent {
  notifications$ = this.notificationService.notifications$;

  constructor(private notificationService: NotificationService) {}

  close(id: string) {
    this.notificationService.remove(id);
  }
}
