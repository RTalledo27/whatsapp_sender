import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export interface Notification {
  id: string;
  type: 'info' | 'success' | 'warning' | 'error';
  title: string;
  message: string;
  progress?: number; // 0-100
  persistent?: boolean; // Si es true, no se auto-oculta
  actions?: Array<{ label: string; callback: () => void }>;
}

@Injectable({
  providedIn: 'root'
})
export class NotificationService {
  private notifications = new BehaviorSubject<Notification[]>([]);
  public notifications$ = this.notifications.asObservable();

  show(notification: Omit<Notification, 'id'>): string {
    const id = Date.now().toString() + Math.random().toString(36);
    const newNotification = { ...notification, id };
    
    this.notifications.next([...this.notifications.value, newNotification]);

    // Auto-ocultar si no es persistente
    if (!notification.persistent) {
      setTimeout(() => this.remove(id), 5000);
    }

    return id;
  }

  update(id: string, updates: Partial<Notification>) {
    const current = this.notifications.value;
    const index = current.findIndex(n => n.id === id);
    
    if (index !== -1) {
      current[index] = { ...current[index], ...updates };
      this.notifications.next([...current]);
    }
  }

  remove(id: string) {
    this.notifications.next(
      this.notifications.value.filter(n => n.id !== id)
    );
  }

  clear() {
    this.notifications.next([]);
  }
}
