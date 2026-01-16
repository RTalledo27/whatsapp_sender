import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable } from 'rxjs';

export type ThemeMode = 'light' | 'dark';

@Injectable({
  providedIn: 'root'
})
export class ThemeService {
  private readonly THEME_KEY = 'app-theme-mode';
  private themeSubject = new BehaviorSubject<ThemeMode>(this.loadTheme());
  
  public theme$: Observable<ThemeMode> = this.themeSubject.asObservable();

  constructor() {
    // Aplicar el tema al inicializar
    this.applyTheme(this.themeSubject.value);
  }

  private loadTheme(): ThemeMode {
    try {
      // Intenta recuperar del localStorage, si no, usa preferencia del sistema
      const savedTheme = localStorage.getItem(this.THEME_KEY) as ThemeMode | null;
      if (savedTheme && (savedTheme === 'light' || savedTheme === 'dark')) {
        return savedTheme;
      }

      // Usar preferencia del sistema operativo
      if (typeof window !== 'undefined' && window.matchMedia) {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
          return 'dark';
        }
      }
    } catch (error) {
      console.warn('Error loading theme preference:', error);
    }

    return 'light';
  }

  public getCurrentTheme(): ThemeMode {
    return this.themeSubject.value;
  }

  public toggleTheme(): void {
    const newTheme = this.themeSubject.value === 'light' ? 'dark' : 'light';
    this.setTheme(newTheme);
  }

  public setTheme(theme: ThemeMode): void {
    this.themeSubject.next(theme);
    try {
      localStorage.setItem(this.THEME_KEY, theme);
    } catch (error) {
      console.warn('Error saving theme to localStorage:', error);
    }
    this.applyTheme(theme);
  }

  private applyTheme(theme: ThemeMode): void {
    try {
      const htmlElement = document.documentElement;
      if (theme === 'dark') {
        htmlElement.classList.add('dark-theme');
        htmlElement.classList.remove('light-theme');
      } else {
        htmlElement.classList.add('light-theme');
        htmlElement.classList.remove('dark-theme');
      }
      console.log('Theme applied:', theme);
    } catch (error) {
      console.warn('Error applying theme:', error);
    }
  }
}
