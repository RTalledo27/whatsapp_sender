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
          <svg class="logo-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <path d="M12 18h.01"/>
          </svg>
          <h2>WhatsApp Sender</h2>    
        </div>
        <ul class="nav-menu">
          <li>
            <a routerLink="/dashboard" routerLinkActive="active">
              <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="20" x2="21" y2="20"/>
                <rect x="3" y="14" width="3" height="6"/>
                <rect x="10" y="6" width="3" height="14"/>
                <rect x="17" y="10" width="3" height="10"/>
              </svg>
              <span>Dashboard</span>
            </a>
          </li>
          <li>
            <a routerLink="/contacts" routerLinkActive="active">
              <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
              <span>Contactos</span>
            </a>
          </li>
          <li>
            <a routerLink="/campaigns" routerLinkActive="active">
              <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
              </svg>
              <span>Campañas</span>
            </a>
          </li>
          <li>
            <a routerLink="/conversations" routerLinkActive="active">
              <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
              </svg>
              <span>Conversaciones</span>
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
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .logo-icon {
      flex-shrink: 0;
      color: #3b82f6;
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
