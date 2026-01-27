import { Component, ChangeDetectorRef, OnInit } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive, Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { CommonModule } from '@angular/common';
import { NotificationToastComponent } from './components/notification-toast/notification-toast.component';
import { ThemeService } from './services/theme.service';
import { AuthService } from './services/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, RouterLink, RouterLinkActive, NotificationToastComponent],
  template: `
    <app-notification-toast></app-notification-toast>
    <div class="app-container" [class.dark-theme]="currentTheme === 'dark'" [class.login-route]="isLoginRoute">
      <nav class="sidebar" [class.collapsed]="isCollapsed">
        <div class="logo">
          <svg class="logo-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <path d="M12 18h.01"/>
          </svg>
          <h2>WhatsApp</h2>    
          <button class="collapse-btn" (click)="toggleSidebar($event)" [attr.aria-label]="isCollapsed ? 'Expandir menú' : 'Contraer menú'">
            <svg *ngIf="!isCollapsed" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 18 9 12 15 6"/>
            </svg>
            <svg *ngIf="isCollapsed" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
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

          <li *ngIf="isAdmin()">
            <a routerLink="/users" routerLinkActive="active">
              <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
                <circle cx="7" cy="6" r="1"/>
                <circle cx="7" cy="12" r="1"/>
                <circle cx="7" cy="18" r="1"/>
              </svg>
              <span>Usuarios</span>
            </a>
          </li>
        </ul>
        <div class="sidebar-footer">
          <button *ngIf="authService.isAuthenticated()" class="logout-btn" (click)="logout()" title="Cerrar sesión">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
              <polyline points="16 17 21 12 16 7"/>
              <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            <span *ngIf="!isCollapsed">Cerrar sesión</span>
          </button>
          <button class="theme-toggle-btn" (click)="toggleTheme()" [attr.aria-label]="currentTheme === 'light' ? 'Activar modo oscuro' : 'Activar modo claro'">
            <svg *ngIf="currentTheme === 'light'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <svg *ngIf="currentTheme === 'dark'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="5"/>
              <line x1="12" y1="1" x2="12" y2="3"/>
              <line x1="12" y1="21" x2="12" y2="23"/>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="1" y1="12" x2="3" y2="12"/>
              <line x1="21" y1="12" x2="23" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <span class="theme-label" *ngIf="!isCollapsed">{{ currentTheme === 'light' ? 'Oscuro' : 'Claro' }}</span>
          </button>
        </div>
      </nav>
      
      <main class="main-content">
        <router-outlet></router-outlet>
      </main>
    </div>

    <!-- Modal de confirmación de cierre de sesión -->
    <div *ngIf="showLogoutModal" class="logout-modal-overlay" (click)="cancelLogout()">
      <div class="logout-modal" (click)="$event.stopPropagation()">
        <div class="modal-icon">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </div>
        <h3>Cerrar sesión</h3>
        <p>¿Estás seguro de que deseas cerrar sesión?</p>
        <div class="modal-actions">
          <button class="btn-cancel" (click)="cancelLogout()">Cancelar</button>
          <button class="btn-confirm" (click)="confirmLogout()">Cerrar sesión</button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .app-container {
      display: flex;
      height: 100vh;
      background: #f3f4f6;
    }

    /* Ocultar sidebar y centrar contenido en la ruta de login */
    .app-container.login-route .sidebar {
      display: none;
    }

    .app-container.login-route {
      background: #fffffff5;
    }

    .app-container.login-route .main-content {
      flex: 1;
      align-items: center;
      justify-content: center;
      padding: 0;
      background: transparent;
      box-shadow: none;
    }

    .sidebar {
      width: 260px;
      background: #1d1f1f;
      color: white;
      display: flex;
      flex-direction: column;
      transition: width 0.25s ease-in-out;
      border-right: 1px solid #2e2f2f;
      overflow: hidden;
    }

    .logo {
      padding: 30px 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .logo-icon {
      flex-shrink: 0;
      color: #3b82f6;
    }

    .logo h2 {
      font-size: 1.3em;
      color: white;
      transition: opacity 0.2s ease-in-out, width 0.2s ease-in-out, margin 0.2s ease-in-out;
    }

    .collapse-btn {
      margin-left: auto;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 8px;
      background: rgba(255,255,255,0.06);
      color: #ffffff;
      cursor: pointer;
      opacity: 0;
      visibility: hidden;
      transform: translateX(8px);
      transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease, background 0.2s, border-color 0.2s;
    }

    .collapse-btn:hover {
      background: rgba(255,255,255,0.12);
      border-color: rgba(255,255,255,0.25);
    }

    /* Mostrar el botón solo cuando el usuario pasa el mouse por el sidebar */
    .sidebar:hover .collapse-btn {
      opacity: 1;
      visibility: visible;
      transform: translateX(0);
    }

    /* Accesibilidad: visible cuando tiene foco por teclado */
    .collapse-btn:focus-visible {
      opacity: 1;
      visibility: visible;
      transform: translateX(0);
      outline: 2px solid #60a5fa;
      outline-offset: 2px;
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

    .nav-menu a span {
      display: inline-block;
      white-space: nowrap;
      overflow: hidden;
      transition: opacity 0.2s ease-in-out, width 0.2s ease-in-out, margin 0.2s ease-in-out;
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
      background:var(--bg-main);
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 20px;
      border-top: 1px solid rgba(255,255,255,0.1);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .logout-btn {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      border-radius: 8px;
      color: #fca5a5;
      cursor: pointer;
      transition: all 0.2s;
      width: 100%;
      justify-content: flex-start;
    }

    .logout-btn:hover {
      background: rgba(239, 68, 68, 0.2);
      color: #fecaca;
      border-color: rgba(239, 68, 68, 0.5);
    }

    .theme-toggle-btn {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 8px;
      color: #d1d5db;
      cursor: pointer;
      transition: background 0.2s, color 0.2s, border-color 0.2s;
      width: 100%;
      justify-content: flex-start;
    }

    .theme-toggle-btn:hover {
      background: rgba(255,255,255,0.12);
      color: white;
      border-color: rgba(255,255,255,0.25);
    }

    .theme-label {
      white-space: nowrap;
      overflow: hidden;
      transition: opacity 0.2s ease-in-out, width 0.2s ease-in-out;
    }

    .sidebar.collapsed .logout-btn,
    .sidebar.collapsed .theme-toggle-btn {
      padding: 12px;
      justify-content: center;
    }

    .sidebar.collapsed .sidebar-footer {
      padding: 12px;
    }

    /* Estado colapsado manual */
    .sidebar.collapsed {
      width: 90px;
    }

    .sidebar.collapsed .logo {
      justify-content: center;
      gap: 12px;
      padding: 20px 12px;
      position: relative;
    }

    .sidebar.collapsed .logo h2 {
      opacity: 0;
      width: 0;
      margin: 0;
    }

    .sidebar.collapsed .collapse-btn {
      margin-left: 0;
      width: 32px;
      height: 32px;
      position: absolute;
      right: 8px;
    }

    .sidebar.collapsed .nav-menu a span {
      opacity: 0;
      width: 0;
      margin: 0;
    }

    .sidebar.collapsed .nav-menu a {
      justify-content: center;
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

    .logout-modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      animation: fadeIn 0.2s ease-out;
    }

    .logout-modal {
      background: white;
      border-radius: 12px;
      padding: 30px;
      max-width: 400px;
      width: 90%;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
      animation: slideUp 0.3s ease-out;
    }

    .dark-theme .logout-modal {
      background: #1e1e1e;
      color: white;
    }

    .modal-icon {
      text-align: center;
      margin-bottom: 20px;
    }

    .logout-modal h3 {
      font-size: 1.5em;
      margin: 0 0 10px 0;
      text-align: center;
      color: #1f2937;
    }

    .dark-theme .logout-modal h3 {
      color: white;
    }

    .logout-modal p {
      text-align: center;
      color: #6b7280;
      margin: 0 0 25px 0;
      font-size: 0.95em;
    }

    .dark-theme .logout-modal p {
      color: #9ca3af;
    }

    .modal-actions {
      display: flex;
      gap: 12px;
      justify-content: center;
    }

    .modal-actions button {
      padding: 10px 24px;
      border: none;
      border-radius: 8px;
      font-size: 0.95em;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-cancel {
      background: #f3f4f6;
      color: #374151;
    }

    .btn-cancel:hover {
      background: #e5e7eb;
    }

    .dark-theme .btn-cancel {
      background: #374151;
      color: #e5e7eb;
    }

    .dark-theme .btn-cancel:hover {
      background: #4b5563;
    }

    .btn-confirm {
      background: #ef4444;
      color: white;
    }

    .btn-confirm:hover {
      background: #dc2626;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from {
        transform: translateY(20px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }
  `]
})
export class AppComponent implements OnInit {
  title = 'WhatsApp Sender';
  isCollapsed = false;
  isLoginRoute = false;
  currentTheme: 'light' | 'dark' = 'light';
  showLogoutModal = false;

  constructor(
    private themeService: ThemeService,
    public authService: AuthService,
    private router: Router,
    private cdr: ChangeDetectorRef
  ) {}

  ngOnInit(): void {
    // Cargar tema actual
    this.currentTheme = this.themeService.getCurrentTheme();
    
    // Suscribirse a cambios del tema
    this.themeService.theme$.subscribe(theme => {
      this.currentTheme = theme;
      console.log('AppComponent tema actualizado:', theme);
      this.cdr.markForCheck();
    });

    // Detectar ruta de login para ocultar sidebar
    this.isLoginRoute = this.router.url?.startsWith('/login');
    this.router.events.pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd)).subscribe(e => {
      this.isLoginRoute = e.urlAfterRedirects.startsWith('/login');
      this.cdr.markForCheck();
    });
  }

  toggleSidebar(event?: Event): void {
    this.isCollapsed = !this.isCollapsed;
    // Quitar el foco del botón para que no quede visible por :focus-visible
    const el = event?.currentTarget as HTMLElement | undefined;
    if (el && typeof el.blur === 'function') {
      el.blur();
    }
  }

  toggleTheme(): void {
    console.log('toggleTheme() llamado');
    this.themeService.toggleTheme();
  }

  logout(): void {
    this.showLogoutModal = true;
  }

  confirmLogout(): void {
    this.showLogoutModal = false;
    this.authService.logout();
  }

  cancelLogout(): void {
    this.showLogoutModal = false;
  }

  isAdmin(): boolean {
    const user = this.authService.getCurrentUser();
    return user && user.role === 'admin';
  }
}
