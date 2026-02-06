import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ContactService, Contact } from '../../services/contact.service';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-contacts',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="contacts-page">
      <div class="header">
        <h1>Gestión de Contactos</h1>
        <div class="actions">
          <button class="btn btn-secondary" (click)="showImportModal = true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            Importar Excel
          </button>
          <button class="btn btn-primary" (click)="showAddModal = true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
              <line x1="12" y1="5" x2="12" y2="19"/>
              <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Agregar Contacto
          </button>
        </div>
      </div>

      <div class="filters">
        <!-- Filtro de tipo solo para admin -->
        <div class="filter-group" *ngIf="isAdmin()">
          <label>Tipo de Contacto:</label>
          <select [(ngModel)]="selectedContactType" (change)="onFilterChange()">
            <option value="">Todos</option>
            <option value="lead">Leads</option>
            <option value="client">Clientes</option>
          </select>
        </div>

        <div class="filter-group search-group">
          <input 
            type="text" 
            placeholder="Buscar por teléfono, nombre o email..."
            [(ngModel)]="searchTerm"
            (keyup.enter)="search()"
          />
          <button class="btn btn-primary" (click)="search()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
              <circle cx="10" cy="10" r="7"/>
              <line x1="16" y1="16" x2="22" y2="22"/>
            </svg>
            Buscar
          </button>
        </div>
      </div>

      <div class="contacts-table" *ngIf="contacts.length > 0">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Teléfono</th>
              <th>Nombre</th>
              <th>Email</th>
              <th>Fecha Creación</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let contact of contacts">
              <td>{{ contact.id }}</td>
              <td>
                <span class="badge" [class.badge-lead]="contact.contact_type === 'lead'" [class.badge-client]="contact.contact_type === 'client'">
                  {{ contact.contact_type === 'lead' ? 'Lead' : 'Cliente' }}
                </span>
              </td>
              <td>{{ contact.phone_number }}</td>
              <td>{{ contact.name || '-' }}</td>
              <td>{{ contact.email || '-' }}</td>
              <td>{{ contact.created_at | date:'short' }}</td>
              <td>
                <button class="btn-icon" (click)="editContact(contact)" title="Editar">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg>
                </button>
                <button class="btn-icon" (click)="deleteContact(contact)" title="Eliminar">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="pagination">
          <button [disabled]="currentPage === 1" (click)="changePage(currentPage - 1)">Anterior</button>
          <span>Página {{ currentPage }} de {{ lastPage }}</span>
          <button [disabled]="currentPage === lastPage" (click)="changePage(currentPage + 1)">Siguiente</button>
        </div>
      </div>

      <div *ngIf="contacts.length === 0" class="empty-state">
        <p>No hay contactos. Importa un archivo Excel o agrega contactos manualmente.</p>
      </div>

      <!-- Modal Importar Excel -->
      <div class="modal" *ngIf="showImportModal" (click)="closeImportModal()">
        <div class="modal-content" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h2>Importar Contactos desde Excel</h2>
            <button class="close-btn" (click)="closeImportModal()">✖</button>
          </div>
          <div class="modal-body">
            <!-- Selector de tipo solo para admin -->
            <div class="form-group" *ngIf="isAdmin()">
              <label>Tipo de Contacto a Importar *</label>
              <div class="type-selector">
                <div class="type-option" 
                     [class.selected]="importContactType === 'lead'"
                     (click)="importContactType = 'lead'">
                  <div class="radio-circle">
                    <div class="radio-dot" *ngIf="importContactType === 'lead'"></div>
                  </div>
                  <div class="type-content">
                    <div class="type-icon lead-icon">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                      </svg>
                    </div>
                    <div class="type-text">
                      <h4>Leads</h4>
                      <p>Contactos del chatbot</p>
                    </div>
                  </div>
                </div>
                
                <div class="type-option" 
                     [class.selected]="importContactType === 'client'"
                     (click)="importContactType = 'client'">
                  <div class="radio-circle">
                    <div class="radio-dot" *ngIf="importContactType === 'client'"></div>
                  </div>
                  <div class="type-content">
                    <div class="type-icon client-icon">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                      </svg>
                    </div>
                    <div class="type-text">
                      <h4>Clientes</h4>
                      <p>Contactos de otros canales</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="file-upload">
              <input 
                type="file" 
                (change)="onFileSelected($event)"
                accept=".xlsx,.xls,.csv"
                #fileInput
              />
              <p class="hint">Formato esperado: Teléfono | Nombre | Email</p>
            </div>
            <div *ngIf="importResult" class="import-result">
              <div class="alert" [class.alert-success]="importResult.success" 
                   [class.alert-error]="!importResult.success">
                <strong *ngIf="importResult.success">✅ Importación exitosa:</strong>
                <strong *ngIf="!importResult.success">❌ Error en importación:</strong>
                <p *ngIf="importResult.success">
                  {{ importResult.imported }} contactos importados
                  <span *ngIf="importResult.failed > 0">, {{ importResult.failed }} fallidos</span>
                </p>
                <p *ngIf="!importResult.success">{{ importResult.error }}</p>
                <ul *ngIf="importResult.errors && importResult.errors.length > 0">
                  <li *ngFor="let error of importResult.errors.slice(0, 5)">{{ error }}</li>
                </ul>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="closeImportModal()" [disabled]="isImporting">Cerrar</button>
            <button class="btn btn-primary" (click)="importExcel()" [disabled]="!selectedFile || isImporting || importCompleted">
              <span class="spinner" *ngIf="isImporting"></span>
              <svg *ngIf="importCompleted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display: inline; margin-right: 8px; vertical-align: middle;">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              <span *ngIf="!isImporting && !importCompleted">Importar</span>
              <span *ngIf="isImporting">Importando...</span>
              <span *ngIf="importCompleted">¡Importado!</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Agregar/Editar Contacto -->
      <div class="modal" *ngIf="showAddModal || editingContact" (click)="closeContactModal()">
        <div class="modal-content" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h2>{{ editingContact ? 'Editar' : 'Agregar' }} Contacto</h2>
            <button class="close-btn" (click)="closeContactModal()">✖</button>
          </div>
          <div class="modal-body">

          <!-- Selector de tipo solo para admin -->
            <div class="form-group" *ngIf="isAdmin()">
              <label>Tipo de Contacto *</label>
              <div class="type-selector">
                <div class="type-option" 
                     [class.selected]="contactForm.contact_type === 'lead'"
                     (click)="contactForm.contact_type = 'lead'">
                  <div class="radio-circle">
                    <div class="radio-dot" *ngIf="contactForm.contact_type === 'lead'"></div>
                  </div>
                  <div class="type-content">
                    <div class="type-icon lead-icon">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                      </svg>
                    </div>
                    <div class="type-text">
                      <h4>Lead</h4>
                      <p>Contacto del chatbot</p>
                    </div>
                  </div>
                </div>
                
                <div class="type-option" 
                     [class.selected]="contactForm.contact_type === 'client'"
                     (click)="contactForm.contact_type = 'client'">
                  <div class="radio-circle">
                    <div class="radio-dot" *ngIf="contactForm.contact_type === 'client'"></div>
                  </div>
                  <div class="type-content">
                    <div class="type-icon client-icon">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                      </svg>
                    </div>
                    <div class="type-text">
                      <h4>Cliente</h4>
                      <p>Contacto de otros canales</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="form-group">
              <label>Teléfono *</label>
              <input type="text" [(ngModel)]="contactForm.phone_number" placeholder="+1234567890" />
            </div>
            <div class="form-group">
              <label>Nombre</label>
              <input type="text" [(ngModel)]="contactForm.name" placeholder="Juan Pérez" />
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" [(ngModel)]="contactForm.email" placeholder="juan@example.com" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="closeContactModal()" [disabled]="isSaving">Cancelar</button>
            <button class="btn btn-primary" (click)="saveContact()" [disabled]="isSaving || saveCompleted">
              <span class="spinner" *ngIf="isSaving"></span>
              <svg *ngIf="saveCompleted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display: inline; margin-right: 8px; vertical-align: middle;">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              <span *ngIf="!isSaving && !saveCompleted">{{ editingContact ? 'Actualizar' : 'Guardar' }}</span>
              <span *ngIf="isSaving">{{ editingContact ? 'Actualizando...' : 'Guardando...' }}</span>
              <span *ngIf="saveCompleted">¡{{ editingContact ? 'Actualizado' : 'Guardado' }}!</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .contacts-page {
      padding: 20px;
    }
    
    h1{
      color: var(--text);
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }

    .actions {
      display: flex;
      gap: 10px;
    }

    .filters {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
      align-items: flex-end;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .filter-group label {
      font-size: 0.9em;
      font-weight: 600;
      color: var(--text);
    }

    .filter-group select {
      padding: 10px 15px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 1em;
      background: var(--search-bg);
      color: var(--text);
      min-width: 180px;
    }

    .search-group {
      flex: 1;
      flex-direction: row;
      gap: 10px;
    }

    .search-group input {
      flex: 1;
      padding: 10px 15px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 1em;
      background: var(--search-bg);
      color: var(--text);
    }

    .badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 0.85em;
      font-weight: 600;
    }

    .badge-lead {
      background: #e3f2fd;
      color: #1976d2;
    }

    .badge-client {
      background: #f3e5f5;
      color: #7b1fa2;
    }

    .contacts-table {
      background: var(--table-bg);
      border-radius: 8px;
      box-shadow: 0 2px 4px rgb(0 0 0 / 25%);
      overflow: hidden;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      background: var(--scroll-track);
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: var(--text);
      border-bottom: 2px solid var(--border);
    }

    td {
      padding: 12px;
      border-bottom: 1px solid var(--border);
      color: var(--text-cont);
    }

    tr:hover {
      background: var(--search-bg);
    }

    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      font-size: 1em;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-primary {
      background: #3b82f6;
      color: white;
    }

    .btn-primary:hover {
      background: #2563eb;
    }

    .btn-secondary {
      background: #6b7280;
      color: white;
    }

    .btn-secondary:hover {
      background: #4b5563;
    }

    .btn-icon {
      background: none;
      border: none;
      font-size: 1.2em;
      cursor: pointer;
      padding: 5px;
      margin: 0 5px;
      color: var(--text);
    }

    .btn-icon:hover {
      transform: scale(1.2);
    }

    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 20px;
      padding: 20px;
      color: var(--text);
    }

    .pagination button {
      padding: 8px 16px;
      border: 1px solid #d1d5db;
      background: var(--panel-bg);
      border-radius: 6px;
      cursor: pointer;
      color: var(--text);
    }

    .pagination button:disabled {
      opacity: 0.24;
      cursor: not-allowed;
    }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted-2);
      background: var(--panel-bg);
      border-radius: 8px;
    }

    .modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .modal-content {
      background: white;
      border-radius: 8px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px;
      border-bottom: 1px solid #e5e7eb;
    }

    .modal-header h2 {
      margin: 0;
      font-size: 1.5em;
    }

    .close-btn {
      background: none;
      border: none;
      font-size: 1.5em;
      cursor: pointer;
      color: #6b7280;
    }

    .modal-body {
      padding: 20px;
    }

    .modal-footer {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 20px;
      border-top: 1px solid #e5e7eb;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: #374151;
    }

    .form-group input {
      width: 100%;
      padding: 10px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 1em;
    }

    .type-selector {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .type-option {
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 16px;
      cursor: pointer;
      transition: all 0.3s;
      background: white;
      position: relative;
    }

    .type-option:hover {
      border-color: #d1d5db;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    }

    .type-option.selected {
      border-color: #3b82f6;
      background: #eff6ff;
    }

    .type-option.selected .lead-icon {
      background: #1976d2;
      color: white;
    }

    .type-option.selected .client-icon {
      background: #7b1fa2;
      color: white;
    }

    .radio-circle {
      width: 20px;
      height: 20px;
      border: 2px solid #d1d5db;
      border-radius: 50%;
      position: absolute;
      top: 16px;
      right: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s;
    }

    .type-option.selected .radio-circle {
      border-color: #3b82f6;
      background: #3b82f6;
    }

    .radio-dot {
      width: 8px;
      height: 8px;
      background: white;
      border-radius: 50%;
    }

    .type-content {
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }

    .type-icon {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: all 0.3s;
    }

    .lead-icon {
      background: #e3f2fd;
      color: #1976d2;
    }

    .client-icon {
      background: #f3e5f5;
      color: #7b1fa2;
    }

    .type-text {
      flex: 1;
      padding-top: 4px;
    }

    .type-text h4 {
      margin: 0 0 4px 0;
      font-size: 1em;
      font-weight: 600;
      color: #1f2937;
    }

    .type-text p {
      margin: 0;
      font-size: 0.85em;
      color: #6b7280;
    }

    .file-upload {
      text-align: center;
      padding: 30px;
      border: 2px dashed #d1d5db;
      border-radius: 8px;
      cursor: pointer;
    }

    .hint {
      margin-top: 10px;
      font-size: 0.9em;
      color: #6b7280;
    }

    .import-result {
      margin-top: 20px;
    }

    .alert {
      padding: 15px;
      border-radius: 6px;
      margin-bottom: 10px;
    }

    .alert-success {
      background: #dcfce7;
      color: #16a34a;
      border: 1px solid #86efac;
    }

    .alert-error {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fca5a5;
    }

    .alert ul {
      margin-top: 10px;
      padding-left: 20px;
    }

    .spinner {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin-right: 8px;
      vertical-align: middle;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  `]
})
export class ContactsComponent implements OnInit {
  contacts: Contact[] = [];
  currentPage = 1;
  lastPage = 1;
  searchTerm = '';
  selectedContactType = ''; // 'lead', 'client', o '' (todos)
  
  showImportModal = false;
  showAddModal = false;
  selectedFile: File | null = null;
  importResult: any = null;
  isImporting = false;
  isSaving = false;
  importCompleted = false;
  saveCompleted = false;
  importContactType: 'lead' | 'client' = 'client';

  editingContact: Contact | null = null;
  contactForm: any = {
    phone_number: '',
    name: '',
    email: '',
    contact_type: 'client'
  };

  constructor(
    private contactService: ContactService,
    private authService: AuthService
  ) {}

  ngOnInit() {
    this.loadContacts();
    this.setDefaultContactType();
  }

  isAdmin(): boolean {
    const user = this.authService.getCurrentUser();
    return user?.role === 'admin';
  }

  isLeadsUser(): boolean {
    const user = this.authService.getCurrentUser();
    return user?.phone_number_id === '950764051457024';
  }

  setDefaultContactType() {
    const user = this.authService.getCurrentUser();
    if (this.isLeadsUser()) {
      this.contactForm.contact_type = 'lead';
    } else {
      this.contactForm.contact_type = 'client';
    }
  }

  onFilterChange() {
    this.currentPage = 1;
    this.loadContacts();
  }

  loadContacts() {
    this.contactService.getContacts(
      this.currentPage, 
      50, 
      this.searchTerm, 
      this.selectedContactType || undefined
    ).subscribe({
      next: (response) => {
        this.contacts = response.data;
        this.currentPage = response.current_page;
        this.lastPage = response.last_page;
      },
      error: (error) => console.error('Error loading contacts:', error)
    });
  }

  search() {
    this.currentPage = 1;
    this.loadContacts();
  }

  changePage(page: number) {
    this.currentPage = page;
    this.loadContacts();
  }

  onFileSelected(event: any) {
    this.selectedFile = event.target.files[0];
    this.importResult = null;
  }

  importExcel() {
    if (!this.selectedFile || this.isImporting) return;

    this.isImporting = true;
    this.importCompleted = false;
    this.importResult = null;

    const contactType = this.isAdmin() ? this.importContactType : (this.isLeadsUser() ? 'lead' : 'client');

    this.contactService.importExcel(this.selectedFile, contactType).subscribe({
      next: (result) => {
        this.importResult = result;
        if (result.success) {
          // Marcar como completado exitosamente
          this.isImporting = false;
          this.importCompleted = true;
          this.loadContacts();
        } else {
          // Solo habilitar botón si hay error para reintentar
          this.isImporting = false;
          this.importCompleted = false;
        }
      },
      error: (error) => {
        this.isImporting = false;
        this.importCompleted = false;
        this.importResult = {
          success: false,
          error: error.error?.message || 'Error al importar archivo'
        };
      }
    });
  }

  editContact(contact: Contact) {
    this.editingContact = contact;
    this.contactForm = {
      phone_number: contact.phone_number,
      name: contact.name || '',
      email: contact.email || '',
      contact_type: contact.contact_type
    };
  }

  deleteContact(contact: Contact) {
    if (!confirm(`¿Eliminar contacto ${contact.phone_number}?`)) return;

    this.contactService.deleteContact(contact.id).subscribe({
      next: () => {
        this.loadContacts();
      },
      error: (error) => console.error('Error deleting contact:', error)
    });
  }

  saveContact() {
    if (!this.contactForm.phone_number || this.isSaving) {
      if (!this.contactForm.phone_number) {
        alert('El teléfono es obligatorio');
      }
      return;
    }

    this.isSaving = true;
    this.saveCompleted = false;

    const request = this.editingContact
      ? this.contactService.updateContact(this.editingContact.id, this.contactForm)
      : this.contactService.createContact(this.contactForm);

    request.subscribe({
      next: () => {
        // Marcar como completado exitosamente
        this.isSaving = false;
        this.saveCompleted = true;
        this.loadContacts();
      },
      error: (error) => {
        this.isSaving = false;
        this.saveCompleted = false;
        const errorMsg = error.error?.message || 'Error al guardar el contacto';
        alert(errorMsg);
        console.error('Error saving contact:', error);
      }
    });
  }

  closeContactModal() {
    this.showAddModal = false;
    this.editingContact = null;
    this.isSaving = false;
    this.saveCompleted = false;
    this.contactForm = { 
      phone_number: '', 
      name: '', 
      email: '',
      contact_type: this.isLeadsUser() ? 'lead' : 'client'
    };
  }

  closeImportModal() {
    this.showImportModal = false;
    this.isImporting = false;
    this.importCompleted = false;
    this.selectedFile = null;
    this.importResult = null;
    this.importContactType = 'client';
  }
}
