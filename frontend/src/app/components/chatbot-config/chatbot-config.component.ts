import { Component, OnInit, AfterViewInit, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  ChatbotService, BotFlow, BotStep, BotAction, BotValidation, ActionType, ValidationType, normalizeStep
} from '../../services/chatbot.service';
import { BotStatsService, BotStats } from '../../services/bot-stats.service';
import {
  ClubStatsService,
  ClientStats,
  CommerceStats,
  ClientDetail,
  CommerceDetail,
  PaginationMeta
} from '../../services/club-stats.service';
import { ComercioService, Comercio, ComercioTelefono } from '../../services/comercio.service';
import mermaid from 'mermaid';

@Component({
  selector: 'app-chatbot-config',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './chatbot-config.component.html',
  styleUrls: ['./chatbot-config.component.scss']
})
export class ChatbotConfigComponent implements OnInit, AfterViewInit {
  @ViewChild('mermaidContainer') mermaidContainer!: ElementRef;

  flows: BotFlow[] = [];
  selectedFlow: BotFlow | null = null;
  selectedStep: BotStep | null = null;
  isEditingFlow = false;
  isEditingStep = false;
  isAddingNewQuestion = false;
  isEditMode = false;
  isSaving = false;
  newFlowName = '';
  mermaidDiagram = '';

  // Menú desplegable y vistas
  showDropdownMenu = false;
  currentView: 'config' | 'stats' | 'comercios' = 'config';

  // Estadísticas
  stats: BotStats | null = null;
  loadingStats = false;
  statsMode: 'crm' | 'club' = 'crm';
  clubView: 'clientes' | 'comercios' = 'clientes';
  clubClientStats: ClientStats | null = null;
  clubCommerceStats: CommerceStats | null = null;
  clubClientDetails: ClientDetail[] = [];
  clubCommerceDetails: CommerceDetail[] = [];
  clubClientMeta: PaginationMeta | null = null;
  clubCommerceMeta: PaginationMeta | null = null;
  loadingClubStats = false;
  loadingClubDetails = false;
  clientFilters = {
    startDate: '',
    endDate: '',
    dni: '',
    telefono: '',
    resultado: '',
    page: 1,
    perPage: 20,
    limit: 50
  };
  commerceFilters = {
    startDate: '',
    endDate: '',
    dni: '',
    comercioId: '',
    resultado: '',
    page: 1,
    perPage: 20,
    limit: 50
  };

  // Comercios
  comercios: Comercio[] = [];
  loadingComercios = false;
  selectedComercio: Comercio | null = null;
  isAddingComercio = false;
  isEditingComercio = false;
  newComercioNombre = '';
  editComercioNombre = '';
  editComercioEstado: 'activo' | 'inactivo' = 'activo';
  editComercioFlowId = '';
  newComercioFlowId = '';
  isAddingTelefono = false;
  newTelefono = '';
  newTelefonoFlujo = 'normal';
  comercioError = '';

  // Pan y zoom
  zoomLevel = 100;
  currentScale = 1;
  isPanning = false;
  startX = 0;
  startY = 0;
  translateX = 0;
  translateY = 0;

  // Opciones de tipo de acción para el selector
  readonly actionTypes: { value: ActionType; label: string; icon: string; description: string }[] = [
    { value: 'buttons',         label: 'Botones',          icon: '🔘', description: 'El usuario elige entre opciones predefinidas' },
    { value: 'free_text',       label: 'Texto libre',      icon: '✏️', description: 'El usuario escribe libremente (nombre, comentario, etc.)' },
    { value: 'validated_input', label: 'Entrada validada', icon: '🔍', description: 'El usuario escribe y se valida el formato (DNI, email, etc.)' },
    { value: 'link_button',     label: 'Enlace / CTA',     icon: '🔗', description: 'Muestra un botón que abre una URL en el navegador. Avanza automáticamente.' },
  ];

  // Opciones de tipo de validación
  readonly validationTypes: { value: ValidationType; label: string; placeholder: string }[] = [
    { value: 'dni',    label: 'DNI (8 dígitos)',       placeholder: 'Ej: 12345678' },
    { value: 'phone',  label: 'Teléfono',              placeholder: 'Ej: +51987654321' },
    { value: 'email',  label: 'Correo electrónico',    placeholder: 'Ej: usuario@correo.com' },
    { value: 'number', label: 'Número',                placeholder: 'Ej: 42' },
    { value: 'text',   label: 'Texto (no vacío)',      placeholder: 'Cualquier texto' },
    { value: 'regex',  label: 'Expresión regular',     placeholder: 'Ej: /^\\d{8}$/' },
  ];

  // Modelo para nueva pregunta
  newQuestion: BotStep = this.emptyQuestion();

  constructor(
    private chatbotService: ChatbotService,
    private statsService: BotStatsService,
    private clubStatsService: ClubStatsService,
    private comercioService: ComercioService
  ) {}

  ngOnInit(): void {
    this.initMermaid();
    this.loadFlows();
  }

  ngAfterViewInit(): void {
    this.renderMermaid();
  }

  initMermaid(): void {
    mermaid.initialize({
      startOnLoad: false,
      theme: 'default',
      flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' }
    });
  }

  loadFlows(): void {
    this.chatbotService.getFlows().subscribe({
      next: (flows: BotFlow[]) => {
        this.flows = flows.map(f => ({ ...f, steps: f.steps.map(normalizeStep) }));
        if (flows.length > 0 && !this.selectedFlow) {
          this.selectFlow(this.flows[0]);
        }
      },
      error: (err: any) => console.error('Error al cargar flujos:', err)
    });
  }

  selectFlow(flow: BotFlow): void {
    this.selectedFlow = flow;
    this.selectedStep = null;
    this.isEditingFlow = false;
    this.isEditingStep = false;
    this.isAddingNewQuestion = false;
    this.isEditMode = false;
    this.generateMermaidDiagram();
  }

  selectStep(step: BotStep): void {
    this.selectedStep = this.deepClone(normalizeStep(step));
    this.isEditingStep = false;
    this.isAddingNewQuestion = false;
  }

  toggleEditMode(): void {
    this.isEditMode = !this.isEditMode;
    if (!this.isEditMode) {
      this.selectedStep = null;
      this.isAddingNewQuestion = false;
      this.isEditingStep = false;
      setTimeout(() => { this.generateMermaidDiagram(); this.renderMermaid(); }, 100);
    }
  }

  // ==================== LÓGICA DE TIPO DE ACCIÓN ====================

  onActionTypeChange(step: BotStep): void {
    // Al cambiar el tipo de acción, resetear las acciones y validación
    switch (step.action_type) {
      case 'buttons':
        step.actions = [
          { id: '', title: '', next_state: '' },
          { id: '', title: '', next_state: '' },
        ];
        delete step.validation;
        break;

      case 'free_text':
        step.actions = [{ next_state: '' }];
        delete step.validation;
        break;

      case 'validated_input':
        step.actions = [{ next_state: '' }];
        step.validation = { type: 'dni', error_message: '' };
        break;

      case 'link_button':
        step.actions = [{ button_text: '', url: '', next_state: '' }];
        delete step.validation;
        break;
    }
  }

  getActionTypeLabel(step: BotStep): string {
    return this.actionTypes.find(t => t.value === step.action_type)?.label ?? 'Botones';
  }

  getActionTypeIcon(step: BotStep): string {
    return this.actionTypes.find(t => t.value === step.action_type)?.icon ?? '🔘';
  }

  isButtonsType(step: BotStep | null): boolean {
    if (!step) return false;
    return (step.action_type ?? 'buttons') === 'buttons';
  }

  isValidatedType(step: BotStep | null): boolean {
    return step?.action_type === 'validated_input';
  }

  isFreeTextType(step: BotStep | null): boolean {
    return step?.action_type === 'free_text';
  }

  isLinkButtonType(step: BotStep | null): boolean {
    return step?.action_type === 'link_button';
  }

  /** Tipos que usan un solo next_state sin botones de respuesta */
  isSingleNextType(step: BotStep | null): boolean {
    return step?.action_type === 'free_text' ||
           step?.action_type === 'validated_input' ||
           step?.action_type === 'link_button';
  }

  getNonButtonNextState(step: BotStep): string {
    return step.actions?.[0]?.next_state ?? '';
  }

  setNonButtonNextState(step: BotStep, value: string): void {
    if (!step.actions || step.actions.length === 0) {
      step.actions = [{ next_state: value }];
    } else {
      step.actions[0].next_state = value;
    }
  }

  // ==================== BOTONES EN PASO DE TIPO BUTTONS ====================

  addButton(): void {
    if (!this.selectedStep) return;
    this.selectedStep.actions = this.selectedStep.actions ?? [];
    this.selectedStep.actions.push({ id: `btn_${Date.now()}`, title: '', next_state: '' });
  }

  removeButton(index: number): void {
    if (!this.selectedStep) return;
    this.selectedStep.actions.splice(index, 1);
  }

  addNewQuestionButton(): void {
    this.newQuestion.actions = this.newQuestion.actions ?? [];
    this.newQuestion.actions.push({ id: `btn_${Date.now()}`, title: '', next_state: '' });
  }

  removeNewQuestionButton(index: number): void {
    this.newQuestion.actions.splice(index, 1);
  }

  // ==================== EDICIÓN DE PASO EXISTENTE ====================

  editStep(): void {
    this.isEditingStep = true;
  }

  saveStep(): void {
    if (!this.selectedFlow || !this.selectedStep) return;
    this.isSaving = true;

    this.chatbotService.updateStep(this.selectedFlow.id, this.selectedStep).subscribe({
      next: (updatedStep: BotStep) => {
        this.chatbotService.getFlow(this.selectedFlow!.id).subscribe({
          next: (freshFlow: BotFlow) => {
            freshFlow.steps = freshFlow.steps.map(normalizeStep);
            const flowIndex = this.flows.findIndex((f: BotFlow) => f.id === freshFlow.id);
            if (flowIndex !== -1) this.flows[flowIndex] = freshFlow;
            this.selectedFlow = freshFlow;
            this.selectedStep = freshFlow.steps.find((s) => s.state === updatedStep.state) ?? null;
            this.isEditingStep = false;
            this.isSaving = false;
            this.generateMermaidDiagram();
            alert('Pregunta actualizada correctamente');
          },
          error: () => {
            this.isEditingStep = false;
            this.isSaving = false;
            this.generateMermaidDiagram();
            alert('Pregunta actualizada correctamente');
          }
        });
      },
      error: (err: any) => {
        console.error('Error al guardar pregunta:', err);
        this.isSaving = false;
        alert('Error al guardar la pregunta');
      }
    });
  }

  cancelEditStep(): void {
    if (this.selectedFlow && this.selectedStep) {
      const orig = this.selectedFlow.steps.find((s) => s.state === this.selectedStep!.state);
      if (orig) this.selectedStep = this.deepClone(normalizeStep(orig));
    }
    this.isEditingStep = false;
  }

  // ==================== NUEVA PREGUNTA ====================

  addQuestion(): void {
    if (!this.selectedFlow) return;
    if (!this.newQuestion.question.trim()) {
      alert('Por favor ingresa el texto de la pregunta');
      return;
    }

    const step: BotStep = {
      state:       `state_${Date.now()}`,
      question:    this.newQuestion.question,
      action_type: this.newQuestion.action_type,
      actions:     this.newQuestion.actions.filter(a => (a.next_state ?? '').trim() !== ''),
      order:       this.selectedFlow.steps.length + 1,
    };

    if (this.newQuestion.action_type === 'validated_input' && this.newQuestion.validation) {
      step.validation = this.newQuestion.validation;
    }

    this.isSaving = true;
    this.chatbotService.addStep(this.selectedFlow.id, step).subscribe({
      next: (addedStep: BotStep) => {
        if (this.selectedFlow) {
          this.selectedFlow.steps.push(normalizeStep(addedStep));
          this.isAddingNewQuestion = false;
          this.isSaving = false;
          this.newQuestion = this.emptyQuestion();
          this.generateMermaidDiagram();
          alert('Pregunta agregada correctamente');
        }
      },
      error: (err: any) => {
        console.error('Error al agregar pregunta:', err);
        this.isSaving = false;
        alert('Error al agregar la pregunta');
      }
    });
  }

  resetNewQuestion(): void {
    this.newQuestion = this.emptyQuestion();
  }

  private emptyQuestion(): BotStep {
    return {
      state:       '',
      question:    '',
      action_type: 'buttons',
      actions:     [
        { id: '', title: '', next_state: '' },
        { id: '', title: '', next_state: '' },
      ],
      order: 0,
    };
  }

  // ==================== DIAGRAMA MERMAID ====================

  generateMermaidDiagram(): void {
    if (!this.selectedFlow || this.selectedFlow.steps.length === 0) {
      this.mermaidDiagram = '';
      return;
    }

    let diagram = 'graph TD\n';
    diagram += '    Start((Inicio)):::startNode\n';

    this.selectedFlow.steps.forEach((step: BotStep) => {
      const nodeId   = step.state.replace(/[^a-zA-Z0-9]/g, '_');
      const question = step.question.substring(0, 45).replace(/"/g, '');
      const icon     = this.getActionTypeIcon(step);
      diagram += `    ${nodeId}["${icon} ${question}..."]:::questionNode\n`;
    });

    diagram += '    Finished([✅ Califica]):::successNode\n';
    diagram += '    noFinished([❌ No califica]):::noFinishedNode\n';

    if (this.selectedFlow.steps.length > 0) {
      const firstId = this.selectedFlow.steps[0].state.replace(/[^a-zA-Z0-9]/g, '_');
      diagram += `    Start --> ${firstId}\n`;
    }

    this.selectedFlow.steps.forEach((step: BotStep) => {
      const nodeId  = step.state.replace(/[^a-zA-Z0-9]/g, '_');
      const actions = step.actions ?? [];

      if (step.action_type === 'buttons') {
        actions.forEach((action: BotAction) => {
          if (action.next_state) {
            let nextId = action.next_state.replace(/[^a-zA-Z0-9]/g, '_');
            if (action.next_state === 'finished')   nextId = 'Finished';
            if (action.next_state === 'nofinished') nextId = 'noFinished';
            const label = (action.title ?? '').substring(0, 20);
            diagram += `    ${nodeId} -->|"${label}"| ${nextId}\n`;
          }
        });
      } else {
        // Para free_text y validated_input: flecha sin etiqueta de botón
        const nextState = actions[0]?.next_state;
        if (nextState) {
          let nextId = nextState.replace(/[^a-zA-Z0-9]/g, '_');
          if (nextState === 'finished')   nextId = 'Finished';
          if (nextState === 'nofinished') nextId = 'noFinished';
          const label = step.action_type === 'validated_input' ? '🔍 validar' : '✏️ respuesta';
          diagram += `    ${nodeId} -->|"${label}"| ${nextId}\n`;
        }
      }
    });

    diagram += '    classDef startNode fill:#10b981,stroke:#059669,stroke-width:3px,color:#fff\n';
    diagram += '    classDef questionNode fill:#3b82f6,stroke:#2563eb,stroke-width:2px,color:#fff\n';
    diagram += '    classDef successNode fill:#22c55e,stroke:#16a34a,stroke-width:2px,color:#fff\n';
    diagram += '    classDef noFinishedNode fill:#f59e0b,stroke:#d97706,stroke-width:2px,color:#fff\n';

    this.mermaidDiagram = diagram;
    setTimeout(() => this.renderMermaid(), 100);
  }

  async renderMermaid(): Promise<void> {
    if (!this.mermaidContainer || !this.mermaidDiagram) return;
    try {
      const element = this.mermaidContainer.nativeElement;
      element.innerHTML = '';
      const { svg } = await mermaid.render('mermaidGraph', this.mermaidDiagram);
      element.innerHTML = svg;
    } catch (error) {
      console.error('Error renderizando Mermaid:', error);
    }
  }

  // ==================== GESTIÓN DE FLUJOS ====================

  createFlow(): void {
    this.isEditingFlow = true;
    this.newFlowName = '';
  }

  saveNewFlow(): void {
    if (!this.newFlowName.trim()) {
      alert('Por favor ingresa un nombre para el flujo');
      return;
    }
    this.chatbotService.createFlow(this.newFlowName).subscribe({
      next: (newFlow: BotFlow) => {
        this.flows.push(newFlow);
        this.selectFlow(newFlow);
        this.isEditingFlow = false;
        this.newFlowName = '';
      },
      error: (err: any) => { console.error('Error al crear flujo:', err); alert('Error al crear el flujo'); }
    });
  }

  cancelNewFlow(): void {
    this.isEditingFlow = false;
    this.newFlowName = '';
  }

  deleteStep(step: BotStep): void {
    if (!this.selectedFlow) return;
    if (!confirm(`¿Estás seguro de eliminar la pregunta: "${step.question}"?`)) return;

    this.chatbotService.deleteStep(this.selectedFlow.id, step.state).subscribe({
      next: () => {
        if (this.selectedFlow) {
          this.selectedFlow.steps = this.selectedFlow.steps.filter((s) => s.state !== step.state);
          if (this.selectedStep?.state === step.state) {
            this.selectedStep = null;
            this.isAddingNewQuestion = false;
          }
          this.generateMermaidDiagram();
          alert('Pregunta eliminada correctamente');
        }
      },
      error: (err: any) => { console.error('Error al eliminar pregunta:', err); alert('Error al eliminar la pregunta'); }
    });
  }

  deleteFlow(flow: BotFlow): void {
    if (!confirm(`¿Estás seguro de eliminar el flujo: "${flow.name}"?`)) return;

    this.chatbotService.deleteFlow(flow.id).subscribe({
      next: () => {
        this.flows = this.flows.filter(f => f.id !== flow.id);
        if (this.selectedFlow?.id === flow.id) {
          this.selectedFlow = this.flows.length > 0 ? this.flows[0] : null;
          this.generateMermaidDiagram();
        }
        alert('Flujo eliminado correctamente');
      },
      error: (err: any) => { console.error('Error al eliminar flujo:', err); alert('Error al eliminar el flujo'); }
    });
  }

  getStepLabel(state: string): string {
    if (!this.selectedFlow) return state;
    const step = this.selectedFlow.steps.find((s) => s.state === state);
    return step ? `${step.question.substring(0, 30)}...` : state;
  }

  // ==================== EXTERNAL VALIDATION (3 RUTAS) ====================

  /**
   * Obtiene el next_state de una acción por su campo 'resultado'
   */
  getResultadoNextState(step: BotStep, resultado: string): string {
    const action = step.actions.find(a => a.resultado === resultado);
    return action?.next_state || '';
  }

  /**
   * Asigna el next_state de una acción por su campo 'resultado'
   */
  setResultadoNextState(step: BotStep, resultado: string, nextState: string): void {
    let action = step.actions.find(a => a.resultado === resultado);
    if (action) {
      action.next_state = nextState;
    } else {
      step.actions.push({ resultado, next_state: nextState });
    }
  }

  /**
   * Cuando se activa/desactiva external_validation, inicializa o limpia las 3 rutas
   */
  onExternalValidationToggle(step: BotStep): void {
    if (step.validation?.external_validation) {
      // Activado: crear 3 acciones con resultado
      const resultados = ['apto', 'no_apto', 'no_encontrado'];
      const existingActions = step.actions || [];
      step.actions = resultados.map(r => {
        const existing = existingActions.find(a => a.resultado === r);
        return existing || { resultado: r, next_state: '' };
      });
    } else {
      // Desactivado: volver a 1 acción simple
      const firstNext = step.actions[0]?.next_state || '';
      step.actions = [{ next_state: firstNext }];
    }
  }

  // ==================== PAN & ZOOM ====================

  startPan(event: MouseEvent): void {
    this.isPanning = true;
    this.startX = event.clientX - this.translateX;
    this.startY = event.clientY - this.translateY;
  }

  onPan(event: MouseEvent): void {
    if (!this.isPanning) return;
    this.translateX = event.clientX - this.startX;
    this.translateY = event.clientY - this.startY;
    this.updateTransform();
  }

  endPan(): void { this.isPanning = false; }

  onWheel(event: WheelEvent): void {
    event.preventDefault();
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    this.currentScale = Math.max(0.3, Math.min(3, this.currentScale + delta));
    this.zoomLevel = Math.round(this.currentScale * 100);
    this.updateTransform();
  }

  zoomIn(): void  { this.currentScale = Math.min(3, this.currentScale + 0.2); this.zoomLevel = Math.round(this.currentScale * 100); this.updateTransform(); }
  zoomOut(): void { this.currentScale = Math.max(0.3, this.currentScale - 0.2); this.zoomLevel = Math.round(this.currentScale * 100); this.updateTransform(); }

  resetZoom(): void {
    this.currentScale = 1; this.zoomLevel = 100;
    this.translateX = 0; this.translateY = 0;
    this.updateTransform();
  }

  private updateTransform(): void {
    if (!this.mermaidContainer) return;
    const svg = this.mermaidContainer.nativeElement.querySelector('svg');
    if (svg) {
      svg.style.transform = `translate(${this.translateX}px, ${this.translateY}px) scale(${this.currentScale})`;
      svg.style.transformOrigin = 'center center';
      svg.style.transition = 'none';
    }
  }

  // ==================== VISTAS Y STATS ====================

  toggleDropdownMenu(): void { this.showDropdownMenu = !this.showDropdownMenu; }

  switchView(view: 'config' | 'stats' | 'comercios'): void {
    this.currentView = view;
    this.showDropdownMenu = false;
    if (view === 'stats') { this.loadStatsView(); }
    else if (view === 'comercios') { this.loadComercios(); }
    else { setTimeout(() => this.renderMermaid(), 100); }
  }

  setStatsMode(mode: 'crm' | 'club'): void {
    this.statsMode = mode;
    this.loadStatsView();
  }

  setClubView(view: 'clientes' | 'comercios'): void {
    this.clubView = view;
    if (view === 'comercios' && this.comercios.length === 0) {
      this.loadComercios();
    }
    this.loadClubStats();
  }

  loadStatsView(): void {
    if (this.statsMode === 'crm') {
      this.loadCrmStats();
    } else {
      this.loadClubStats();
    }
  }

  loadStats(): void {
    this.loadStatsView();
  }

  loadCrmStats(): void {
    this.loadingStats = true;
    this.statsService.getStats().subscribe({
      next: (stats: BotStats) => { this.stats = stats; this.loadingStats = false; },
      error: () => { this.loadingStats = false; }
    });
  }

  loadClubStats(): void {
    if (this.clubView === 'clientes') {
      this.loadClientStats();
      this.loadClientDetails();
    } else {
      this.loadCommerceStats();
      this.loadCommerceDetails();
    }
  }

  applyClientFilters(): void {
    this.clientFilters.page = 1;
    this.loadClientStats();
    this.loadClientDetails();
  }

  applyCommerceFilters(): void {
    this.commerceFilters.page = 1;
    this.loadCommerceStats();
    this.loadCommerceDetails();
  }

  loadClientStats(): void {
    this.loadingClubStats = true;
    this.clubStatsService.getClientStats({
      start_date: this.clientFilters.startDate,
      end_date: this.clientFilters.endDate,
      dni: this.clientFilters.dni,
      telefono: this.clientFilters.telefono,
      resultado: this.clientFilters.resultado,
      limit: this.clientFilters.limit
    }).subscribe({
      next: (stats) => {
        this.clubClientStats = stats;
        this.loadingClubStats = false;
      },
      error: () => { this.loadingClubStats = false; }
    });
  }

  loadClientDetails(): void {
    this.loadingClubDetails = true;
    this.clubStatsService.getClientDetails({
      start_date: this.clientFilters.startDate,
      end_date: this.clientFilters.endDate,
      dni: this.clientFilters.dni,
      telefono: this.clientFilters.telefono,
      resultado: this.clientFilters.resultado,
      page: this.clientFilters.page,
      per_page: this.clientFilters.perPage
    }).subscribe({
      next: (response) => {
        this.clubClientDetails = response.data;
        this.clubClientMeta = response.meta;
        this.loadingClubDetails = false;
      },
      error: () => { this.loadingClubDetails = false; }
    });
  }

  loadCommerceStats(): void {
    this.loadingClubStats = true;
    this.clubStatsService.getCommerceStats({
      start_date: this.commerceFilters.startDate,
      end_date: this.commerceFilters.endDate,
      dni: this.commerceFilters.dni,
      comercio_id: this.commerceFilters.comercioId,
      resultado: this.commerceFilters.resultado,
      limit: this.commerceFilters.limit
    }).subscribe({
      next: (stats) => {
        this.clubCommerceStats = stats;
        this.loadingClubStats = false;
      },
      error: () => { this.loadingClubStats = false; }
    });
  }

  loadCommerceDetails(): void {
    this.loadingClubDetails = true;
    this.clubStatsService.getCommerceDetails({
      start_date: this.commerceFilters.startDate,
      end_date: this.commerceFilters.endDate,
      dni: this.commerceFilters.dni,
      comercio_id: this.commerceFilters.comercioId,
      resultado: this.commerceFilters.resultado,
      page: this.commerceFilters.page,
      per_page: this.commerceFilters.perPage
    }).subscribe({
      next: (response) => {
        this.clubCommerceDetails = response.data;
        this.clubCommerceMeta = response.meta;
        this.loadingClubDetails = false;
      },
      error: () => { this.loadingClubDetails = false; }
    });
  }

  nextClientPage(): void {
    if (!this.clubClientMeta) return;
    if (this.clubClientMeta.current_page >= this.clubClientMeta.last_page) return;
    this.clientFilters.page += 1;
    this.loadClientDetails();
  }

  prevClientPage(): void {
    if (!this.clubClientMeta) return;
    if (this.clubClientMeta.current_page <= 1) return;
    this.clientFilters.page -= 1;
    this.loadClientDetails();
  }

  nextCommercePage(): void {
    if (!this.clubCommerceMeta) return;
    if (this.clubCommerceMeta.current_page >= this.clubCommerceMeta.last_page) return;
    this.commerceFilters.page += 1;
    this.loadCommerceDetails();
  }

  prevCommercePage(): void {
    if (!this.clubCommerceMeta) return;
    if (this.clubCommerceMeta.current_page <= 1) return;
    this.commerceFilters.page -= 1;
    this.loadCommerceDetails();
  }

  // ==================== COMERCIOS ====================

  loadComercios(): void {
    this.loadingComercios = true;
    this.comercioError = '';
    this.comercioService.getAll().subscribe({
      next: (data: Comercio[]) => {
        this.comercios = data;
        this.loadingComercios = false;
      },
      error: (err: any) => {
        console.error('Error al cargar comercios:', err);
        this.comercioError = 'Error al cargar comercios';
        this.loadingComercios = false;
      }
    });
  }

  selectComercio(comercio: Comercio): void {
    this.selectedComercio = comercio;
    this.isAddingComercio = false;
    this.isEditingComercio = false;
    this.isAddingTelefono = false;
    this.editComercioNombre = comercio.nombre;
    this.editComercioEstado = comercio.estado;
    this.editComercioFlowId = comercio.flow_id ?? '';
  }

  startAddComercio(): void {
    this.isAddingComercio = true;
    this.newComercioNombre = '';
    this.newComercioFlowId = '';
    this.comercioError = '';
  }

  cancelAddComercio(): void {
    this.isAddingComercio = false;
    this.newComercioNombre = '';
  }

  saveNewComercio(): void {
    if (!this.newComercioNombre.trim()) {
      this.comercioError = 'El nombre es obligatorio';
      return;
    }
    this.comercioError = '';
    this.comercioService.create({
      nombre: this.newComercioNombre.trim(),
      flow_id: this.newComercioFlowId || undefined
    } as any).subscribe({
      next: (comercio: Comercio) => {
        this.comercios.push(comercio);
        this.isAddingComercio = false;
        this.newComercioNombre = '';
        this.newComercioFlowId = '';
        this.selectComercio(comercio);
      },
      error: (err: any) => {
        this.comercioError = err.error?.errors?.nombre?.[0] || 'Error al crear comercio';
      }
    });
  }

  startEditComercio(): void {
    if (!this.selectedComercio) return;
    this.isEditingComercio = true;
    this.editComercioNombre = this.selectedComercio.nombre;
    this.editComercioEstado = this.selectedComercio.estado;
    this.editComercioFlowId = this.selectedComercio.flow_id ?? '';
  }

  cancelEditComercio(): void {
    this.isEditingComercio = false;
  }

  saveEditComercio(): void {
    if (!this.selectedComercio) return;
    this.comercioError = '';
    this.comercioService.update(this.selectedComercio.id, {
      nombre: this.editComercioNombre.trim(),
      estado: this.editComercioEstado,
      flow_id: this.editComercioFlowId || null
    } as any).subscribe({
      next: (updated: Comercio) => {
        const idx = this.comercios.findIndex(c => c.id === updated.id);
        if (idx >= 0) { this.comercios[idx] = updated; }
        this.selectedComercio = updated;
        this.isEditingComercio = false;
      },
      error: (err: any) => {
        this.comercioError = 'Error al actualizar comercio';
      }
    });
  }

  deleteComercio(comercio: Comercio): void {
    if (!confirm(`¿Eliminar el comercio "${comercio.nombre}"? Se eliminarán también sus teléfonos.`)) return;
    this.comercioService.delete(comercio.id).subscribe({
      next: () => {
        this.comercios = this.comercios.filter(c => c.id !== comercio.id);
        if (this.selectedComercio?.id === comercio.id) {
          this.selectedComercio = null;
        }
      },
      error: () => { this.comercioError = 'Error al eliminar comercio'; }
    });
  }

  startAddTelefono(): void {
    this.isAddingTelefono = true;
    this.newTelefono = '';
    this.newTelefonoFlujo = 'normal';
    this.comercioError = '';
  }

  cancelAddTelefono(): void {
    this.isAddingTelefono = false;
    this.newTelefono = '';
  }

  saveNewTelefono(): void {
    if (!this.selectedComercio || !this.newTelefono.trim()) {
      this.comercioError = 'El número es obligatorio';
      return;
    }
    this.comercioError = '';
    this.comercioService.addTelefono(this.selectedComercio.id, {
      telefono: this.newTelefono.trim(),
      tipo_flujo: this.newTelefonoFlujo
    }).subscribe({
      next: (tel: ComercioTelefono) => {
        if (this.selectedComercio?.telefonos) {
          this.selectedComercio.telefonos.push(tel);
        }
        this.isAddingTelefono = false;
        this.newTelefono = '';
      },
      error: (err: any) => {
        this.comercioError = err.error?.error || 'Error al agregar teléfono';
      }
    });
  }

  removeTelefono(tel: ComercioTelefono): void {
    if (!this.selectedComercio) return;
    if (!confirm(`¿Eliminar el teléfono ${tel.telefono}?`)) return;
    this.comercioService.removeTelefono(this.selectedComercio.id, tel.id).subscribe({
      next: () => {
        if (this.selectedComercio?.telefonos) {
          this.selectedComercio.telefonos = this.selectedComercio.telefonos.filter(t => t.id !== tel.id);
        }
      },
      error: () => { this.comercioError = 'Error al eliminar teléfono'; }
    });
  }

  // ==================== HELPERS ====================

  getFlowName(flowId: string): string {
    if (!flowId) return 'Sin asignar';
    const flow = this.flows.find(f => f.id === flowId);
    return flow ? flow.name : 'Flujo no encontrado';
  }

  private deepClone<T>(obj: T): T {
    return JSON.parse(JSON.stringify(obj));
  }
}
