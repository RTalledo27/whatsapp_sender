import { Component } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { NotificationToastComponent } from './components/notification-toast/notification-toast.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, RouterLinkActive, NotificationToastComponent],
  template: `
    <app-notification-toast></app-notification-toast>
    <div class="app-container">
      <nav class="sidebar">
        <div class="logo">
          <h2>📱 WhatsApp Sender</h2>
        </div>
        <ul class="nav-menu">
          <li>
            <a routerLink="/dashboard" routerLinkActive="active">
              <span class="icon">📊</span>
              <span>Dashboard</span>
            </a>
          </li>
          <li>
            <a routerLink="/contacts" routerLinkActive="active">
              <span class="icon">👥</span>
              <span>Contactos</span>
            </a>
          </li>
          <li>
            <a routerLink="/campaigns" routerLinkActive="active">
              <span class="icon">📢</span>
              <span>Campañas</span>
            </a>
          </li>
        </ul>
      </nav>
      
      <main class="main-content">
        <router-outlet></router-outlet>
      </main>
    </div>
  `,
  styles: [`
    .app-container {
      display: flex;
      height: 100vh;
      background: #f3f4f6;
    }

    .sidebar {
      width: 260px;
      background: #1f2937;
      color: white;
      display: flex;
      flex-direction: column;
    }

    .logo {
      padding: 30px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .logo h2 {
      margin: 0;
      font-size: 1.3em;
      color: white;
    }

    .nav-menu {
      list-style: none;
      padding: 20px 0;
      margin: 0;
    }

    .nav-menu li {
      margin-bottom: 5px;
    }

    .nav-menu a {
      display: flex;
      align-items: center;
      gap: 15px;
      padding: 15px 20px;
      color: #d1d5db;
      text-decoration: none;
      transition: all 0.2s;
    }

    .nav-menu a:hover {
      background: rgba(255,255,255,0.1);
      color: white;
    }

    .nav-menu a.active {
      background: #3b82f6;
      color: white;
      border-right: 4px solid #60a5fa;
    }

    .icon {
      font-size: 1.4em;
    }

    .main-content {
      flex: 1;
      overflow-y: auto;
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 80px;
      }

      .logo h2 {
        font-size: 1.5em;
        text-align: center;
      }

      .nav-menu a span:not(.icon) {
        display: none;
      }

      .nav-menu a {
        justify-content: center;
      }
    }
  `]
})
export class AppComponent {
  title = 'WhatsApp Sender';
}
