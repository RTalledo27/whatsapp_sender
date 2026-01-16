import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CampaignService, Campaign, WhatsAppNumber } from '../../services/campaign.service';
import { ContactService, Contact } from '../../services/contact.service';
import { TemplateService, WhatsAppTemplate } from '../../services/template.service';
import { CampaignPollingService } from '../../services/campaign-polling.service';
import { NotificationService } from '../../services/notification.service';
import { AuthService } from '../../services/auth.service';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-campaigns',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="campaigns-page">
      <div class="header">
        <div class="header-left">
          <h1>Campañas de Envío</h1>
          <div class="phone-selector" *ngIf="availableNumbers.length > 1">
            <label for="phone-filter">Filtrar por número:</label>
            <select id="phone-filter" [(ngModel)]="selectedPhoneNumberId" (change)="onPhoneNumberFilterChange()">
              <option value="">Todos los números</option>
              <option *ngFor="let number of availableNumbers" [value]="number.id">
                {{ number.name }} - {{ number.phone }}
              </option>
            </select>
          </div>
        </div>
        <button class="btn btn-primary" (click)="showCreateModal = true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Nueva Campaña
        </button>
      </div>

      <div class="campaigns-list" *ngIf="campaigns.length > 0">
        <div class="campaign-card" *ngFor="let campaign of campaigns">
          <div class="campaign-header">
            <div>
              <h3>{{ campaign.name }}</h3>
              <p class="campaign-date">{{ campaign.created_at | date:'medium' }}</p>
            </div>
            <span class="badge" 
                  [class.badge-success]="campaign.status === 'completed'"
                  [class.badge-warning]="campaign.status === 'processing'"
                  [class.badge-info]="campaign.status === 'pending'"
                  [class.badge-error]="campaign.status === 'failed'">
              {{ campaign.status }}
            </span>
          </div>
          
          <div class="campaign-message">
            <strong>Mensaje:</strong> {{ campaign.message }}
          </div>

          <!-- Barra de progreso para campañas en proceso -->
          <div class="campaign-progress" *ngIf="campaign.status === 'processing'">
            <div class="progress-bar-container">
              <div class="progress-bar-fill" 
                   [style.width.%]="getProgress(campaign)">
              </div>
            </div>
            <span class="progress-text">{{ getProgress(campaign) }}% completado</span>
          </div>

          <div class="campaign-stats">
            <div class="stat">
              <span class="stat-value">{{ campaign.total_contacts }}</span>
              <span class="stat-label">Total</span>
            </div>
            <div class="stat success">
              <span class="stat-value">{{ campaign.sent_count }}</span>
              <span class="stat-label">Enviados</span>
            </div>
            <div class="stat error">
              <span class="stat-value">{{ campaign.failed_count }}</span>
              <span class="stat-label">Fallidos</span>
            </div>
            <div class="stat warning">
              <span class="stat-value">{{ campaign.pending_count }}</span>
              <span class="stat-label">Pendientes</span>
            </div>
          </div>

          <div class="campaign-actions">
            <button class="btn btn-small" (click)="viewDetails(campaign)">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                <path d="M3 3h7v7H3z"/>
                <path d="M14 3h7v7h-7z"/>
                <path d="M14 14h7v7h-7z"/>
                <path d="M3 14h7v7H3z"/>
              </svg>
              Ver Detalles
            </button>
            <button class="btn btn-small" 
                    *ngIf="campaign.failed_count > 0"
                    (click)="retryFailed(campaign)">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2-8.83"/>
              </svg>
              Reintentar Fallidos
            </button>
            <button class="btn btn-small btn-danger" (click)="deleteCampaign(campaign)">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                <line x1="10" y1="11" x2="10" y2="17"/>
                <line x1="14" y1="11" x2="14" y2="17"/>
              </svg>
              Eliminar
            </button>
          </div>
        </div>
      </div>

      <div *ngIf="campaigns.length === 0" class="empty-state">
        <p>No hay campañas creadas. Crea tu primera campaña de envío.</p>
      </div>

      <!-- Modal Nueva Campaña -->
      <div class="modal" *ngIf="showCreateModal">
        <div class="modal-content large">
          <div class="modal-header">
            <h2>Nueva Campaña de Envío</h2>
            <button class="close-btn" (click)="closeCreateModal()">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label>Nombre de la Campaña *</label>
              <input type="text" [(ngModel)]="campaignForm.name" 
                     placeholder="Ej: Promoción Navidad 2024" />
            </div>

            <div class="form-group">
              <label>Número de WhatsApp *</label>
              <select [(ngModel)]="campaignForm.phone_number_id" 
                      (change)="onPhoneNumberSelected($event)"
                      class="form-select">
                <option value="">-- Selecciona un número --</option>
                <option *ngFor="let number of availableNumbers" [value]="number.id">
                  {{ number.name }} - {{ number.phone }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" [(ngModel)]="useTemplate" />
                Usar Template de WhatsApp (Recomendado para nuevos contactos)
              </label>
            </div>

            <!-- Sección de Template -->
            <div *ngIf="useTemplate">
              <div class="form-group">
                <label>Seleccionar Template *</label>
                <select [(ngModel)]="campaignForm.template_name" 
                        (change)="onTemplateSelected($event)"
                        class="form-select">
                  <option value="">-- Selecciona un template --</option>
                  <option *ngFor="let template of templates" [value]="template.name">
                    {{ template.name }} ({{ template.language }})
                  </option>
                </select>
              </div>

              <div class="template-preview" *ngIf="selectedTemplate">
                <h4>Vista Previa del Template</h4>
                <div class="template-body">
                  {{ getTemplateBodyText() }}
                </div>
              </div>

              <div *ngIf="templateParameters.length > 0">
                <h4>Parámetros del Template</h4>
                <div class="form-group" *ngFor="let param of templateParameters; let i = index">
                  <label>Parámetro {{i + 1}} *</label>
                  <input type="text" 
                         [(ngModel)]="campaignForm.template_parameters[i]"
                         [placeholder]="'Valor para {{' + (i+1) + '}}'"/>
                </div>
              </div>
            </div>

            <!-- Sección de Mensaje de Texto -->
            <div *ngIf="!useTemplate">
              <div class="form-group">
                <label>Mensaje *</label>
                <textarea [(ngModel)]="campaignForm.message" 
                          rows="4"
                          placeholder="Escribe el mensaje que se enviará a todos los contactos..."></textarea>
                <small>{{ campaignForm.message.length }} caracteres</small>
              </div>
              <div class="alert alert-warning">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
                  <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3.05h16.94a2 2 0 0 0 1.71-3.05L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                  <line x1="12" y1="9" x2="12" y2="13"/>
                  <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Los mensajes de texto solo funcionan con números que ya han conversado contigo en las últimas 24 horas.
              </div>
            </div>

            <div class="form-group">
              <label>Seleccionar Contactos *</label>
              <div class="contacts-selection">
                <div class="search-box">
                  <input type="text" 
                         [(ngModel)]="contactSearch"
                         (keyup)="searchContacts()"
                         placeholder="Buscar contactos..." />
                </div>
                <div class="contacts-list">
                  <div class="contact-item" *ngFor="let contact of availableContacts">
                    <label>
                      <input type="checkbox" 
                             [checked]="isContactSelected(contact.id)"
                             (change)="toggleContact(contact.id)" />
                      <span>{{ contact.phone_number }}</span>
                      <span class="contact-name" *ngIf="contact.name">- {{ contact.name }}</span>
                    </label>
                  </div>
                </div>
                <div class="selection-summary">
                  <strong>{{ campaignForm.contact_ids.length }}</strong> contactos seleccionados
                  <button class="btn-link" (click)="selectAll()">Seleccionar todos</button>
                  <button class="btn-link" (click)="deselectAll()">Deseleccionar todos</button>
                  <button class="btn-link" (click)="showImportContactsModal = true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                      <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                    </svg>
                    Importar desde Excel
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="closeCreateModal()">Cancelar</button>
            <button class="btn btn-primary" 
                    (click)="createCampaign()"
                    [disabled]="!canCreateCampaign()">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 8px; vertical-align: middle;">
                <polygon points="2 21 23 12 2 3 2 10 19 12 2 14 2 21"/>
              </svg>
              Crear y Enviar
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Importar Contactos desde Excel -->
      <div class="modal" *ngIf="showImportContactsModal">
        <div class="modal-content">
          <div class="modal-header">
            <h2>Importar Contactos desde Excel</h2>
            <button class="close-btn" (click)="closeImportContactsModal()">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="file-upload">
              <input 
                type="file" 
                (change)="onContactsFileSelected($event)"
                accept=".xlsx,.xls,.csv"
                #contactsFileInput
              />
              <p class="hint">Selecciona un archivo Excel con los teléfonos de los contactos que deseas agregar a la campaña.</p>
              <p class="hint">Formato esperado: Teléfono | Nombre | Email</p>
            </div>
            <div *ngIf="importContactsResult" class="import-result">
              <div class="alert" [class.alert-success]="importContactsResult.success" 
                   [class.alert-error]="!importContactsResult.success">
                <strong *ngIf="importContactsResult.success">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                  Importación exitosa:
                </strong>
                <strong *ngIf="!importContactsResult.success">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline; margin-right: 6px; vertical-align: middle;">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                  </svg>
                  Error en importación:
                </strong>
                <p *ngIf="importContactsResult.success">
                  {{ importContactsResult.found }} contactos encontrados de {{ importContactsResult.total_in_excel }} en el Excel
                  <span *ngIf="importContactsResult.not_found > 0">
                    <br>{{ importContactsResult.not_found }} números no están registrados en tus contactos
                  </span>
                </p>
                <p *ngIf="!importContactsResult.success">{{ importContactsResult.error }}</p>
                <ul *ngIf="importContactsResult.not_found_numbers && importContactsResult.not_found_numbers.length > 0">
                  <li *ngFor="let phone of importContactsResult.not_found_numbers.slice(0, 10)">{{ phone }}</li>
                  <li *ngIf="importContactsResult.not_found_numbers.length > 10">
                    ... y {{ importContactsResult.not_found_numbers.length - 10 }} más
                  </li>
                </ul>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="closeImportContactsModal()">Cancelar</button>
            <button class="btn btn-primary" (click)="importContactsFromExcel()" [disabled]="!selectedContactsFile">
              Importar y Seleccionar
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Detalles -->
      <div class="modal" *ngIf="selectedCampaign">
        <div class="modal-content large">
          <div class="modal-header">
            <h2>Detalles de Campaña: {{ selectedCampaign.name }}</h2>
            <button class="close-btn" (click)="selectedCampaign = null">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="details-grid">
              <div class="detail-item">
                <strong>Estado:</strong>
                <span class="badge" 
                      [class.badge-success]="selectedCampaign.status === 'completed'"
                      [class.badge-warning]="selectedCampaign.status === 'processing'">
                  {{ selectedCampaign.status }}
                </span>
              </div>
              <div class="detail-item">
                <strong>Total Contactos:</strong> {{ selectedCampaign.total_contacts }}
              </div>
              <div class="detail-item">
                <strong>Enviados:</strong> {{ selectedCampaign.sent_count }}
              </div>
              <div class="detail-item">
                <strong>Fallidos:</strong> {{ selectedCampaign.failed_count }}
              </div>
            </div>

            <div class="messages-table" *ngIf="selectedCampaign.messages">
              <h3>Mensajes ({{ selectedCampaign.messages.length }})</h3>
              <table>
                <thead>
                  <tr>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Enviado</th>
                    <th>Error</th>
                  </tr>
                </thead>
                <tbody>
                  <tr *ngFor="let message of selectedCampaign.messages">
                    <td>{{ message.phone_number }}</td>
                    <td>
                      <span class="badge badge-small"
                            [class.badge-success]="message.status === 'sent'"
                            [class.badge-error]="message.status === 'failed'"
                            [class.badge-warning]="message.status === 'pending'">
                        {{ message.status }}
                      </span>
                    </td>
                    <td>{{ message.sent_at | date:'short' }}</td>
                    <td>{{ message.error_message || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .campaigns-page {
      padding: 20px;
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      flex-wrap: wrap;
      gap: 20px;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 20px;
      flex-wrap: wrap;
    }

    .phone-selector {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .phone-selector label {
      font-weight: 500;
      color: #374151;
      white-space: nowrap;
      font-size: 0.9em;
    }

    .phone-selector select {
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      background-color: white;
      color: #374151;
      font-size: 0.9em;
      cursor: pointer;
      min-width: 200px;
      transition: all 0.2s;
    }

    .phone-selector select:hover {
      border-color: #2563eb;
    }

    .phone-selector select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .campaigns-list {
      display: grid;
      gap: 20px;
    }

    .campaign-card {
      background: white;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .campaign-header {
      display: flex;
      justify-content: space-between;
      align-items: start;
      margin-bottom: 15px;
    }

    .campaign-header h3 {
      margin: 0 0 5px 0;
      color: #111827;
    }

    .campaign-date {
      color: #6b7280;
      font-size: 0.9em;
      margin: 0;
    }

    .campaign-message {
      padding: 15px;
      background: #f9fafb;
      border-radius: 6px;
      margin-bottom: 15px;
      color: #374151;
    }

    .campaign-progress {
      margin-bottom: 15px;
    }

    .progress-bar-container {
      height: 24px;
      background: #e5e7eb;
      border-radius: 12px;
      overflow: hidden;
      position: relative;
    }

    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #10b981, #34d399);
      transition: width 0.5s ease;
      box-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
    }

    .progress-text {
      display: block;
      text-align: center;
      margin-top: 5px;
      font-size: 0.85em;
      color: #6b7280;
      font-weight: 600;
    }

    .campaign-stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 15px;
      margin-bottom: 15px;
    }

    .stat {
      text-align: center;
      padding: 15px;
      background: #f9fafb;
      border-radius: 6px;
    }

    .stat.success { border-left: 4px solid #22c55e; }
    .stat.error { border-left: 4px solid #ef4444; }
    .stat.warning { border-left: 4px solid #f59e0b; }

    .stat-value {
      display: block;
      font-size: 1.8em;
      font-weight: bold;
      color: #111827;
    }

    .stat-label {
      display: block;
      font-size: 0.9em;
      color: #6b7280;
      margin-top: 5px;
    }

    .campaign-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
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

    .btn-small {
      padding: 8px 16px;
      font-size: 0.9em;
      background: #e5e7eb;
      color: #374151;
    }

    .btn-small:hover {
      background: #d1d5db;
    }

    .btn-danger {
      background: #fee2e2;
      color: #dc2626;
    }

    .btn-danger:hover {
      background: #fca5a5;
    }

    .badge {
      padding: 6px 12px;
      border-radius: 12px;
      font-size: 0.85em;
      font-weight: 600;
    }

    .badge-success {
      background: #dcfce7;
      color: #16a34a;
    }

    .badge-warning {
      background: #fef3c7;
      color: #d97706;
    }

    .badge-info {
      background: #dbeafe;
      color: #2563eb;
    }

    .badge-error {
      background: #fee2e2;
      color: #dc2626;
    }

    .badge-small {
      padding: 4px 8px;
      font-size: 0.75em;
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
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
    }

    .modal-content.large {
      max-width: 800px;
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

    .form-group input,
    .form-group textarea,
    .form-group select,
    .form-select {
      width: 100%;
      padding: 10px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 1em;
      font-family: inherit;
    }

    .form-group small {
      color: #6b7280;
      font-size: 0.85em;
    }

    .checkbox-label {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
    }

    .checkbox-label input[type="checkbox"] {
      width: auto;
    }

    .template-preview {
      margin: 20px 0;
      padding: 15px;
      background: #f0fdf4;
      border: 1px solid #86efac;
      border-radius: 6px;
    }

    .template-preview h4 {
      margin: 0 0 10px 0;
      color: #16a34a;
      font-size: 1em;
    }

    .template-body {
      background: white;
      padding: 15px;
      border-radius: 6px;
      white-space: pre-wrap;
      font-family: monospace;
      color: #374151;
    }

    .alert {
      padding: 12px 15px;
      border-radius: 6px;
      margin: 15px 0;
    }

    .alert-warning {
      background: #fef3c7;
      border: 1px solid #fbbf24;
      color: #92400e;
    }

    .alert-success {
      background: #dcfce7;
      border: 1px solid #86efac;
      color: #16a34a;
    }

    .alert-error {
      background: #fee2e2;
      border: 1px solid #fca5a5;
      color: #dc2626;
    }

    .file-upload {
      text-align: center;
      padding: 30px;
      border: 2px dashed #d1d5db;
      border-radius: 8px;
      cursor: pointer;
      margin-bottom: 15px;
    }

    .file-upload:hover {
      border-color: #3b82f6;
      background: #f9fafb;
    }

    .hint {
      margin-top: 10px;
      font-size: 0.9em;
      color: #6b7280;
    }

    .import-result {
      margin-top: 20px;
    }

    .import-result ul {
      margin-top: 10px;
      padding-left: 20px;
      font-size: 0.9em;
    }

    .contacts-selection {
      border: 1px solid #d1d5db;
      border-radius: 6px;
      overflow: hidden;
    }

    .search-box input {
      width: 100%;
      padding: 10px;
      border: none;
      border-bottom: 1px solid #e5e7eb;
    }

    .contacts-list {
      max-height: 300px;
      overflow-y: auto;
    }

    .contact-item {
      padding: 10px 15px;
      border-bottom: 1px solid #f3f4f6;
    }

    .contact-item:hover {
      background: #f9fafb;
    }

    .contact-item label {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
    }

    .contact-name {
      color: #6b7280;
      font-size: 0.9em;
    }

    .selection-summary {
      padding: 15px;
      background: #f9fafb;
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .btn-link {
      background: none;
      border: none;
      color: #3b82f6;
      cursor: pointer;
      text-decoration: underline;
      font-size: 0.9em;
    }

    .details-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      margin-bottom: 20px;
    }

    .detail-item {
      padding: 15px;
      background: #f9fafb;
      border-radius: 6px;
    }

    .messages-table {
      margin-top: 20px;
    }

    .messages-table h3 {
      margin-bottom: 15px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      background: #f9fafb;
      padding: 10px;
      text-align: left;
      font-weight: 600;
      border-bottom: 2px solid #e5e7eb;
    }

    td {
      padding: 10px;
      border-bottom: 1px solid #e5e7eb;
    }
  `]
})
export class CampaignsComponent implements OnInit, OnDestroy {
  campaigns: Campaign[] = [];
  availableContacts: Contact[] = [];
  availableNumbers: WhatsAppNumber[] = [];
  selectedCampaign: Campaign | null = null;
  templates: WhatsAppTemplate[] = [];
  selectedTemplate: WhatsAppTemplate | null = null;
  templateParameters: string[] = [];
  
  showCreateModal = false;
  contactSearch = '';
  useTemplate = false;
  selectedPhoneNumberId: string = '';
  
  showImportContactsModal = false;
  selectedContactsFile: File | null = null;
  importContactsResult: any = null;
  
  private pollingSubscription?: Subscription;
  
  campaignForm = {
    name: '',
    phone_number_id: '',
    phone_number_name: '',
    message: '',
    template_name: '',
    template_parameters: [] as string[],
    contact_ids: [] as number[]
  };

  constructor(
    private campaignService: CampaignService,
    private contactService: ContactService,
    private templateService: TemplateService,
    private pollingService: CampaignPollingService,
    private notificationService: NotificationService,
    private authService: AuthService
  ) {}

  ngOnInit() {
    // Auto-seleccionar número si el usuario no es admin
    const user = this.authService.getCurrentUser();
    if (user && user.role !== 'admin' && user.phone_number_id) {
      this.selectedPhoneNumberId = user.phone_number_id;
    }
    
    this.loadCampaigns();
    this.loadContacts();
    this.loadTemplates();
    this.loadAvailableNumbers();
    this.startAutoRefresh();
  }

  ngOnDestroy() {
    if (this.pollingSubscription) {
      this.pollingSubscription.unsubscribe();
    }
    // NO detener el polling al salir, las notificaciones deben continuar
    // this.pollingService.stopAllPolling();
  }

  startAutoRefresh() {
    // Suscribirse a actualizaciones de campaña
    this.pollingSubscription = this.pollingService.campaignStatuses$.subscribe(statuses => {
      // Actualizar campañas con los nuevos estados
      statuses.forEach((status, campaignId) => {
        const campaign = this.campaigns.find(c => c.id === campaignId);
        if (campaign) {
          campaign.status = status.status as 'pending' | 'processing' | 'completed' | 'failed';
          campaign.sent_count = status.sent_count;
          campaign.failed_count = status.failed_count;
          campaign.pending_count = status.pending_count;
        }
      });
    });
  }

  loadCampaigns() {
    const phoneNumberId = this.selectedPhoneNumberId || null;
    this.campaignService.getCampaigns(1, 20, phoneNumberId).subscribe({
      next: (response) => {
        this.campaigns = response.data;
      },
      error: (error) => console.error('Error loading campaigns:', error)
    });
  }

  onPhoneNumberFilterChange() {
    this.loadCampaigns();
  }

  loadContacts() {
    this.contactService.getContacts(1, 1000).subscribe({
      next: (response) => {
        this.availableContacts = response.data;
      },
      error: (error) => console.error('Error loading contacts:', error)
    });
  }

  loadTemplates() {
    this.templateService.getTemplates().subscribe({
      next: (response) => {
        this.templates = response.templates;
      },
      error: (error) => console.error('Error loading templates:', error)
    });
  }

  loadAvailableNumbers() {
    this.campaignService.getAvailableNumbers().subscribe({
      next: (response) => {
        this.availableNumbers = response.numbers;
        if (this.availableNumbers.length > 0 && !this.campaignForm.phone_number_id) {
          this.campaignForm.phone_number_id = this.availableNumbers[0].id;
          this.campaignForm.phone_number_name = this.availableNumbers[0].name;
        }
      },
      error: (error) => console.error('Error loading available numbers:', error)
    });
  }

  onPhoneNumberSelected(event: any) {
    const numberId = event.target.value;
    const number = this.availableNumbers.find(n => n.id === numberId);
    if (number) {
      this.campaignForm.phone_number_name = number.name;
    }
  }

  onTemplateSelected(event: any) {
    const templateName = event.target.value;
    this.selectedTemplate = this.templates.find(t => t.name === templateName) || null;
    
    if (this.selectedTemplate) {
      this.campaignForm.template_name = this.selectedTemplate.name;
      const params = this.templateService.getTemplateParameters(this.selectedTemplate);
      this.templateParameters = params;
      this.campaignForm.template_parameters = new Array(params.length).fill('');
    }
  }

  getTemplateBodyText(): string {
    if (!this.selectedTemplate) return 'Sin contenido';
    const bodyComponent = this.selectedTemplate.components.find(c => c.type === 'BODY');
    return bodyComponent?.text || 'Sin contenido';
  }

  searchContacts() {
    this.contactService.getContacts(1, 100, this.contactSearch).subscribe({
      next: (response) => {
        this.availableContacts = response.data;
      }
    });
  }

  isContactSelected(id: number): boolean {
    return this.campaignForm.contact_ids.includes(id);
  }

  toggleContact(id: number) {
    const index = this.campaignForm.contact_ids.indexOf(id);
    if (index > -1) {
      this.campaignForm.contact_ids.splice(index, 1);
    } else {
      this.campaignForm.contact_ids.push(id);
    }
  }

  selectAll() {
    this.campaignForm.contact_ids = this.availableContacts.map(c => c.id);
  }

  deselectAll() {
    this.campaignForm.contact_ids = [];
  }

  canCreateCampaign(): boolean {
    if (this.useTemplate) {
      return !!(this.campaignForm.name && 
                this.campaignForm.phone_number_id &&
                this.campaignForm.template_name && 
                this.campaignForm.contact_ids.length > 0 &&
                this.campaignForm.template_parameters.every(p => p.trim() !== ''));
    } else {
      return !!(this.campaignForm.name && 
                this.campaignForm.phone_number_id &&
                this.campaignForm.message && 
                this.campaignForm.contact_ids.length > 0);
    }
  }

  createCampaign() {
    if (!this.canCreateCampaign()) return;

    this.campaignService.createCampaign(this.campaignForm).subscribe({
      next: (response) => {
        const campaignId = response.data.id;
        
        this.closeCreateModal();
        this.loadCampaigns();
        
        // Iniciar polling automático para esta campaña
        this.pollingService.startPolling(campaignId, 2000);
      },
      error: (error) => {
        console.error('Error creating campaign:', error);
        this.notificationService.show({
          type: 'error',
          title: 'Error',
          message: 'Error al crear la campaña: ' + (error.error?.message || 'Error desconocido')
        });
      }
    });
  }

  closeCreateModal() {
    this.showCreateModal = false;
    this.campaignForm = { 
      name: '', 
      phone_number_id: this.availableNumbers.length > 0 ? this.availableNumbers[0].id : '',
      phone_number_name: this.availableNumbers.length > 0 ? this.availableNumbers[0].name : '',
      message: '', 
      template_name: '',
      template_parameters: [],
      contact_ids: [] 
    };
    this.contactSearch = '';
    this.useTemplate = false;
    this.selectedTemplate = null;
    this.templateParameters = [];
  }

  viewDetails(campaign: Campaign) {
    this.campaignService.getCampaignDetails(campaign.id).subscribe({
      next: (data) => {
        this.selectedCampaign = data;
      }
    });
  }

  retryFailed(campaign: Campaign) {
    if (!confirm(`¿Reintentar envío de ${campaign.failed_count} mensajes fallidos?`)) return;

    this.campaignService.retryFailed(campaign.id).subscribe({
      next: () => {
        alert('Reintentando mensajes fallidos...');
        this.loadCampaigns();
      }
    });
  }

  deleteCampaign(campaign: Campaign) {
    if (!confirm(`¿Eliminar campaña "${campaign.name}"?`)) return;

    this.campaignService.deleteCampaign(campaign.id).subscribe({
      next: () => {
        this.loadCampaigns();
      }
    });
  }

  getProgress(campaign: Campaign): number {
    if (campaign.total_contacts === 0) return 0;
    const completed = campaign.sent_count + campaign.failed_count;
    return Math.round((completed / campaign.total_contacts) * 100);
  }

  onContactsFileSelected(event: any) {
    this.selectedContactsFile = event.target.files[0];
    this.importContactsResult = null;
  }

  importContactsFromExcel() {
    if (!this.selectedContactsFile) return;

    this.contactService.getContactsFromExcel(this.selectedContactsFile).subscribe({
      next: (result) => {
        this.importContactsResult = result;
        if (result.success && result.contacts) {
          // Seleccionar automáticamente los contactos encontrados
          result.contacts.forEach((contact: Contact) => {
            if (!this.campaignForm.contact_ids.includes(contact.id)) {
              this.campaignForm.contact_ids.push(contact.id);
            }
          });
          
          // Cerrar el modal después de 2 segundos si fue exitoso
          if (result.found > 0) {
            setTimeout(() => {
              this.closeImportContactsModal();
            }, 2000);
          }
        }
      },
      error: (error) => {
        this.importContactsResult = {
          success: false,
          error: error.error?.message || 'Error al procesar archivo'
        };
      }
    });
  }

  closeImportContactsModal() {
    this.showImportContactsModal = false;
    this.selectedContactsFile = null;
    this.importContactsResult = null;
  }
}
