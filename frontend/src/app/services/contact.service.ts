import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Contact {
  id: number;
  phone_number: string;
  name?: string;
  email?: string;
  contact_type: 'lead' | 'client';
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

@Injectable({
  providedIn: 'root'
})
export class ContactService {
  private apiUrl = `${environment.apiUrl}/contacts`;

  constructor(private http: HttpClient) {}

  getContacts(page: number = 1, perPage: number = 50, search: string = '', contactType?: string): Observable<PaginatedResponse<Contact>> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());
    
    if (search) {
      params = params.set('search', search);
    }

    if (contactType) {
      params = params.set('contact_type', contactType);
    }

    return this.http.get<PaginatedResponse<Contact>>(this.apiUrl, { params });
  }

  createContact(contact: Partial<Contact>): Observable<any> {
    return this.http.post(this.apiUrl, contact);
  }

  updateContact(id: number, contact: Partial<Contact>): Observable<any> {
    return this.http.put(`${this.apiUrl}/${id}`, contact);
  }

  deleteContact(id: number): Observable<any> {
    return this.http.delete(`${this.apiUrl}/${id}`);
  }

  importExcel(file: File, contactType?: string): Observable<any> {
    const formData = new FormData();
    formData.append('file', file);
    if (contactType) {
      formData.append('contact_type', contactType);
    }
    return this.http.post(`${this.apiUrl}/import-excel`, formData);
  }

  getContactsFromExcel(file: File): Observable<any> {
    const formData = new FormData();
    formData.append('file', file);
    return this.http.post(`${this.apiUrl}/get-from-excel`, formData);
  }

  getExcelFormat(): Observable<any> {
    return this.http.get(`${this.apiUrl}/excel-format`);
  }
}
