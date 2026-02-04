import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Note {
  id?: number;
  user_id?: number;
  client_id: number;
  title: string;
  content: string;
  tag?: string;
  created_at?: string;
  updated_at?: string;
  client?: any;
}

@Injectable({ providedIn: 'root' })
export class NotesService {
  private apiUrl = `${environment.apiUrl}/notes`;

  constructor(private http: HttpClient) {}

  getNotes(): Observable<Note[]> {
    return this.http.get<Note[]>(this.apiUrl);
  }

  getNote(id: number): Observable<Note> {
    return this.http.get<Note>(`${this.apiUrl}/${id}`);
  }

  addNote(note: Partial<Note>): Observable<Note> {
    return this.http.post<Note>(this.apiUrl, note);
  }
}
