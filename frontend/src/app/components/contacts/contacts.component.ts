import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ContactService, Contact } from '../../services/contact.service';

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

      <div class="search-bar">
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

      <div class="contacts-table" *ngIf="contacts.length > 0">
        <table>
          <thead>
            <tr>
              <th>ID</th>
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
      <div class="modal" *ngIf="showImportModal">
        <div class="modal-content">
          <div class="modal-header">
            <h2>Importar Contactos desde Excel</h2>
            <button class="close-btn" (click)="showImportModal = false">✖</button>
          </div>
          <div class="modal-body">
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
            <button class="btn btn-secondary" (click)="showImportModal = false">Cancelar</button>
            <button class="btn btn-primary" (click)="importExcel()" [disabled]="!selectedFile">
              Importar
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Agregar/Editar Contacto -->
      <div class="modal" *ngIf="showAddModal || editingContact">
        <div class="modal-content">
          <div class="modal-header">
            <h2>{{ editingContact ? 'Editar' : 'Agregar' }} Contacto</h2>
            <button class="close-btn" (click)="closeContactModal()">✖</button>
          </div>
          <div class="modal-body">
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
            <button class="btn btn-secondary" (click)="closeContactModal()">Cancelar</button>
            <button class="btn btn-primary" (click)="saveContact()">
              {{ editingContact ? 'Actualizar' : 'Guardar' }}
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

    .search-bar {
      display: flex;
      gap: 10px;
      margin-bottom: 20px;
    }

    .search-bar input {
      flex: 1;
      padding: 10px 15px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 1em;
    }

    .contacts-table {
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      overflow: hidden;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      background: #f9fafb;
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: #374151;
      border-bottom: 2px solid #e5e7eb;
    }

    td {
      padding: 12px;
      border-bottom: 1px solid #e5e7eb;
    }

    tr:hover {
      background: #f9fafb;
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
    }

    .pagination button {
      padding: 8px 16px;
      border: 1px solid #d1d5db;
      background: white;
      border-radius: 6px;
      cursor: pointer;
    }

    .pagination button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #9ca3af;
      background: white;
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
  `]
})
export class ContactsComponent implements OnInit {
  contacts: Contact[] = [];
  currentPage = 1;
  lastPage = 1;
  searchTerm = '';
  
  showImportModal = false;
  showAddModal = false;
  selectedFile: File | null = null;
  importResult: any = null;

  editingContact: Contact | null = null;
  contactForm = {
    phone_number: '',
    name: '',
    email: ''
  };

  constructor(private contactService: ContactService) {}

  ngOnInit() {
    this.loadContacts();
  }

  loadContacts() {
    this.contactService.getContacts(this.currentPage, 50, this.searchTerm).subscribe({
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
    if (!this.selectedFile) return;

    this.contactService.importExcel(this.selectedFile).subscribe({
      next: (result) => {
        this.importResult = result;
        if (result.success) {
          setTimeout(() => {
            this.showImportModal = false;
            this.loadContacts();
          }, 2000);
        }
      },
      error: (error) => {
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
      email: contact.email || ''
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
    if (!this.contactForm.phone_number) {
      alert('El teléfono es obligatorio');
      return;
    }

    const request = this.editingContact
      ? this.contactService.updateContact(this.editingContact.id, this.contactForm)
      : this.contactService.createContact(this.contactForm);

    request.subscribe({
      next: () => {
        this.closeContactModal();
        this.loadContacts();
      },
      error: (error) => console.error('Error saving contact:', error)
    });
  }

  closeContactModal() {
    this.showAddModal = false;
    this.editingContact = null;
    this.contactForm = { phone_number: '', name: '', email: '' };
  }
}
