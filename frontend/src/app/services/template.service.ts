import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface TemplateParameter {
  index: number;       // Position order (1-based) for the parameter
  name: string;        // The raw name inside {{ }}: could be "1", "first_name", etc.
  component: string;   // Which component it belongs to: HEADER, BODY, BUTTONS
  label: string;       // User-friendly label shown in the UI
}

export interface WhatsAppTemplate {
  id: string;
  name: string;
  language: string;
  status: string;
  category: string;
  components: Array<{
    type: string;
    format?: string;
    text?: string;
    buttons?: Array<{
      type: string;
      text?: string;
      url?: string;
      example?: string[];
    }>;
    example?: {
      body_text?: string[][];
      header_text?: string[];
    };
  }>;
}

@Injectable({
  providedIn: 'root'
})
export class TemplateService {
  private apiUrl = `${environment.apiUrl}/templates`;

  constructor(private http: HttpClient) { }

  getTemplates(phoneNumberId?: string): Observable<{ templates: WhatsAppTemplate[] }> {
    let params = new HttpParams();
    if (phoneNumberId) {
      params = params.set('phone_number_id', phoneNumberId);
    }
    return this.http.get<{ templates: WhatsAppTemplate[] }>(this.apiUrl, { params });
  }

  /**
   * Extract ALL parameters from a template across all component types.
   * Supports BOTH numbered ({{1}}, {{2}}) AND named ({{first_name}}, {{days}}) params.
   * Scans HEADER, BODY, FOOTER and BUTTONS for placeholders.
   */
  getTemplateParametersDetailed(template: WhatsAppTemplate): TemplateParameter[] {
    const params: TemplateParameter[] = [];
    const seen = new Set<string>();

    if (!template || !template.components) return params;

    // Regex matches both {{1}} and {{first_name}}, {{quota_number}}, etc.
    const paramRegex = /\{\{([^}]+)\}\}/g;
    let orderCounter = 1;

    for (const component of template.components) {
      const componentType = component.type;

      // Check text field for {{...}} patterns
      if (component.text) {
        const matches = component.text.matchAll(paramRegex);
        for (const match of matches) {
          const paramName = match[1].trim();
          const key = `${componentType}-${paramName}`;
          if (!seen.has(key)) {
            seen.add(key);
            params.push({
              index: orderCounter++,
              name: paramName,
              component: componentType,
              label: this.buildParamLabel(paramName, componentType)
            });
          }
        }
      }

      // Check buttons for {{...}} patterns in URL or text
      if (component.buttons && Array.isArray(component.buttons)) {
        for (const button of component.buttons) {
          const textsToScan = [button.text, button.url].filter(Boolean);
          for (const text of textsToScan) {
            const matches = text!.matchAll(paramRegex);
            for (const match of matches) {
              const paramName = match[1].trim();
              const key = `BUTTONS-${paramName}`;
              if (!seen.has(key)) {
                seen.add(key);
                params.push({
                  index: orderCounter++,
                  name: paramName,
                  component: 'BUTTONS',
                  label: this.buildParamLabel(paramName, 'BUTTONS')
                });
              }
            }
          }
        }
      }
    }

    // Sort by component priority (HEADER → BODY → BUTTONS) then by discovery order
    const componentOrder: Record<string, number> = { 'HEADER': 0, 'BODY': 1, 'FOOTER': 2, 'BUTTONS': 3 };
    params.sort((a, b) => {
      const orderA = componentOrder[a.component] ?? 99;
      const orderB = componentOrder[b.component] ?? 99;
      if (orderA !== orderB) return orderA - orderB;
      return a.index - b.index;
    });

    // Re-assign sequential index after sorting
    params.forEach((p, i) => p.index = i + 1);

    return params;
  }

  /**
   * Backward-compatible wrapper: returns a simple string[] of labels.
   */
  getTemplateParameters(template: WhatsAppTemplate): string[] {
    return this.getTemplateParametersDetailed(template).map(p => p.label);
  }

  /**
   * Build a human-friendly label for a parameter.
   * Converts snake_case names to readable text.
   */
  private buildParamLabel(paramName: string, component: string): string {
    const componentLabels: Record<string, string> = {
      'HEADER': 'Cabecera',
      'BODY': 'Cuerpo',
      'FOOTER': 'Pie',
      'BUTTONS': 'Botón'
    };
    const section = componentLabels[component] || component;

    // Make the parameter name human-readable
    const readableName = paramName
      .replace(/_/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());

    return `${readableName} — ${section}`;
  }

  /**
   * Get all text from all components for full preview.
   */
  getFullTemplatePreview(template: WhatsAppTemplate): string {
    if (!template || !template.components) return 'Sin contenido';
    const parts: string[] = [];
    for (const component of template.components) {
      if (component.text) {
        parts.push(component.text);
      }
    }
    return parts.join('\n\n') || 'Sin contenido';
  }
}
