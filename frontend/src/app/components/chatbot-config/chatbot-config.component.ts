import { Component, OnInit, AfterViewInit, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ChatbotService, BotFlow, BotStep } from '../../services/chatbot.service';
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
  newFlowName = '';
  mermaidDiagram = '';
  
  // Para pan y zoom
  zoomLevel = 100;
  currentScale = 1;
  isPanning = false;
  startX = 0;
  startY = 0;
  translateX = 0;
  translateY = 0;
  
  // Para crear nueva pregunta
  newQuestion = {
    question: '',
    buttons: [
      { id: '', title: '', nextState: '' },
      { id: '', title: '', nextState: '' }
    ]
  };

  constructor(private chatbotService: ChatbotService) {}

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
      flowchart: {
        useMaxWidth: true,
        htmlLabels: true,
        curve: 'basis'
      }
    });
  }

  loadFlows(): void {
    this.chatbotService.getFlows().subscribe({
      next: (flows: BotFlow[]) => {
        this.flows = flows;
        if (flows.length > 0 && !this.selectedFlow) {
          this.selectFlow(flows[0]);
        }
      },
      error: (error: any) => {
        console.error('Error al cargar flujos:', error);
      }
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
    this.selectedStep = { ...step };
    this.isEditingStep = false;
    this.isAddingNewQuestion = false;
  }

  toggleEditMode(): void {
    this.isEditMode = !this.isEditMode;
    if (!this.isEditMode) {
      this.selectedStep = null;
      this.isAddingNewQuestion = false;
      this.isEditingStep = false;
      // Regenerar y renderizar el diagrama al volver
      setTimeout(() => {
        this.generateMermaidDiagram();
        this.renderMermaid();
      }, 100);
    }
  }

  generateMermaidDiagram(): void {
    if (!this.selectedFlow || this.selectedFlow.steps.length === 0) {
      this.mermaidDiagram = '';
      return;
    }

    let diagram = 'graph TD\n';
    diagram += '    Start((Inicio)):::startNode\n';

    // Agregar nodos para cada pregunta
    this.selectedFlow.steps.forEach((step: BotStep, index: number) => {
      const nodeId = step.state.replace(/[^a-zA-Z0-9]/g, '_');
      const question = step.question.substring(0, 50).replace(/"/g, '');
      diagram += `    ${nodeId}["${question}..."]:::questionNode\n`;
    });

    // Nodos especiales
    diagram += '    Finished([✅ Calificado]):::successNode\n';
    diagram += '    Handoff([👤 Humano]):::handoffNode\n';

    // Conectar inicio con primera pregunta
    if (this.selectedFlow.steps.length > 0) {
      const firstNodeId = this.selectedFlow.steps[0].state.replace(/[^a-zA-Z0-9]/g, '_');
      diagram += `    Start --> ${firstNodeId}\n`;
    }

    // Conectar botones a siguiente estado
    this.selectedFlow.steps.forEach((step: BotStep) => {
      const nodeId = step.state.replace(/[^a-zA-Z0-9]/g, '_');
      
      step.buttons.forEach((btn: any) => {
        if (btn.nextState) {
          let nextNodeId = btn.nextState.replace(/[^a-zA-Z0-9]/g, '_');
          
          if (btn.nextState === 'finished') {
            nextNodeId = 'Finished';
          } else if (btn.nextState === 'handoff') {
            nextNodeId = 'Handoff';
          }
          
          const btnTitle = btn.title.substring(0, 20);
          diagram += `    ${nodeId} -->|"${btnTitle}"| ${nextNodeId}\n`;
        }
      });
    });

    // Estilos
    diagram += '    classDef startNode fill:#10b981,stroke:#059669,stroke-width:3px,color:#fff\n';
    diagram += '    classDef questionNode fill:#3b82f6,stroke:#2563eb,stroke-width:2px,color:#fff\n';
    diagram += '    classDef successNode fill:#22c55e,stroke:#16a34a,stroke-width:2px,color:#fff\n';
    diagram += '    classDef handoffNode fill:#f59e0b,stroke:#d97706,stroke-width:2px,color:#fff\n';

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

  editStep(): void {
    this.isEditingStep = true;
  }

  saveStep(): void {
    if (!this.selectedFlow || !this.selectedStep) return;

    this.chatbotService.updateStep(this.selectedFlow.id, this.selectedStep).subscribe({
      next: (updatedStep: BotStep) => {
        if (this.selectedFlow) {
          const index = this.selectedFlow.steps.findIndex((s: BotStep) => s.state === updatedStep.state);
          if (index !== -1) {
            this.selectedFlow.steps[index] = updatedStep;
          }
        }
        this.isEditingStep = false;
        this.generateMermaidDiagram();
        alert('Pregunta actualizada correctamente');
      },
      error: (error: any) => {
        console.error('Error al guardar pregunta:', error);
        alert('Error al guardar la pregunta');
      }
    });
  }

  cancelEditStep(): void {
    if (this.selectedFlow && this.selectedStep) {
      const originalStep = this.selectedFlow.steps.find((s: BotStep) => s.state === this.selectedStep!.state);
      if (originalStep) {
        this.selectedStep = { ...originalStep };
      }
    }
    this.isEditingStep = false;
  }

  addButton(): void {
    if (!this.selectedStep) return;
    this.selectedStep.buttons.push({
      id: `btn_${Date.now()}`,
      title: '',
      nextState: ''
    });
  }

  removeButton(index: number): void {
    if (!this.selectedStep) return;
    this.selectedStep.buttons.splice(index, 1);
  }

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
      error: (error: any) => {
        console.error('Error al crear flujo:', error);
        alert('Error al crear el flujo');
      }
    });
  }

  cancelNewFlow(): void {
    this.isEditingFlow = false;
    this.newFlowName = '';
  }

  startAddingQuestion(): void {
    this.selectedStep = null;
    this.isEditingStep = false;
    this.isAddingNewQuestion = true;
    this.resetNewQuestion();
  }

  addQuestion(): void {
    if (!this.selectedFlow) return;

    const newStep: BotStep = {
      state: `state_${Date.now()}`,
      question: this.newQuestion.question,
      buttons: this.newQuestion.buttons.filter(b => b.title.trim() !== ''),
      order: this.selectedFlow.steps.length + 1
    };

    this.chatbotService.addStep(this.selectedFlow.id, newStep).subscribe({
      next: (addedStep: BotStep) => {
        if (this.selectedFlow) {
          this.selectedFlow.steps.push(addedStep);
          this.isAddingNewQuestion = false;
          this.resetNewQuestion();
          this.generateMermaidDiagram();
          alert('Pregunta agregada correctamente');
        }
      },
      error: (error: any) => {
        console.error('Error al agregar pregunta:', error);
        alert('Error al agregar la pregunta');
      }
    });
  }

  resetNewQuestion(): void {
    this.newQuestion = {
      question: '',
      buttons: [
        { id: '', title: '', nextState: '' },
        { id: '', title: '', nextState: '' }
      ]
    };
  }

  deleteStep(step: BotStep): void {
    if (!this.selectedFlow) return;
    
    if (!confirm(`¿Estás seguro de eliminar la pregunta: "${step.question}"?`)) {
      return;
    }

    this.chatbotService.deleteStep(this.selectedFlow.id, step.state).subscribe({
      next: () => {
        if (this.selectedFlow) {
          this.selectedFlow.steps = this.selectedFlow.steps.filter((s: BotStep) => s.state !== step.state);
          if (this.selectedStep?.state === step.state) {
            this.selectedStep = null;
            this.isAddingNewQuestion = false;
          }
          this.generateMermaidDiagram();
          alert('Pregunta eliminada correctamente');
        }
      },
      error: (error: any) => {
        console.error('Error al eliminar pregunta:', error);
        alert('Error al eliminar la pregunta');
      }
    });
  }

  deleteFlow(flow: BotFlow): void {
    if (!confirm(`¿Estás seguro de eliminar el flujo: "${flow.name}"?`)) {
      return;
    }

    this.chatbotService.deleteFlow(flow.id).subscribe({
      next: () => {
        this.flows = this.flows.filter(f => f.id !== flow.id);
        if (this.selectedFlow?.id === flow.id) {
          this.selectedFlow = this.flows.length > 0 ? this.flows[0] : null;
          this.generateMermaidDiagram();
        }
        alert('Flujo eliminado correctamente');
      },
      error: (error: any) => {
        console.error('Error al eliminar flujo:', error);
        alert('Error al eliminar el flujo');
      }
    });
  }

  getStepLabel(state: string): string {
    if (!this.selectedFlow) return state;
    const step = this.selectedFlow.steps.find((s: BotStep) => s.state === state);
    return step ? `${step.question.substring(0, 30)}...` : state;
  }

  // Métodos para pan y zoom
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

  endPan(): void {
    this.isPanning = false;
  }

  onWheel(event: WheelEvent): void {
    event.preventDefault();
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    this.currentScale = Math.max(0.3, Math.min(3, this.currentScale + delta));
    this.zoomLevel = Math.round(this.currentScale * 100);
    this.updateTransform();
  }

  zoomIn(): void {
    this.currentScale = Math.min(3, this.currentScale + 0.2);
    this.zoomLevel = Math.round(this.currentScale * 100);
    this.updateTransform();
  }

  zoomOut(): void {
    this.currentScale = Math.max(0.3, this.currentScale - 0.2);
    this.zoomLevel = Math.round(this.currentScale * 100);
    this.updateTransform();
  }

  resetZoom(): void {
    this.currentScale = 1;
    this.zoomLevel = 100;
    this.translateX = 0;
    this.translateY = 0;
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
}
