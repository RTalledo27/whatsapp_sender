import { Component, OnInit } from '@angular/core';
import { NotesService, Note } from '../../services/notes.service';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-notes',
  standalone: true,
  imports: [FormsModule, CommonModule],
  templateUrl: './notes.component.html',
  styleUrls: ['./notes.component.scss']
})
export class NotesComponent implements OnInit {
  notes: Note[] = [];
  loading = false;
  error = '';
  newNote: Partial<Note> = { title: '', content: '', client_id: 1 };

  groupedNotes: { [clientKey: string]: Note[] } = {};
  expandedClients: Set<string> = new Set();

  filterText = '';
  filterClient = '';
  filterDate: string | null = null;
  sortOrder: 'asc' | 'desc' = 'desc';

  constructor(private notesService: NotesService) {}

  ngOnInit() {
    this.fetchNotes();
  }

  fetchNotes() {
    this.loading = true;
    this.notesService.getNotes().subscribe({
      next: notes => {
        this.notes = notes;
        this.groupNotesByClient();
        this.loading = false;
      },
      error: err => {
        this.error = 'Error al cargar notas';
        this.loading = false;
      }
    });
  }

  addNote() {
    if (!this.newNote.title || !this.newNote.content) return;
    this.notesService.addNote(this.newNote).subscribe({
      next: note => {
        this.notes.unshift(note);
        this.groupNotesByClient();
        this.newNote = { title: '', content: '', client_id: 1 };
      },
      error: err => {
        this.error = 'Error al guardar nota';
      }
    });
  }

  groupNotesByClient() {
    this.groupedNotes = {};
    for (const note of this.notes) {
      const key = note.client?.name || note.client_id?.toString() || 'Sin cliente';
      if (!this.groupedNotes[key]) this.groupedNotes[key] = [];
      this.groupedNotes[key].push(note);
    }
  }
  
  toggleClient(clientKey: string) {
    if (this.expandedClients.has(clientKey)) {
      this.expandedClients.delete(clientKey);
    } else {
      this.expandedClients.clear(); // Solo uno expandido a la vez
      this.expandedClients.add(clientKey);
    }
  }

  isExpanded(clientKey: string): boolean {
    return this.expandedClients.has(clientKey);
  }

  get filteredNotes(): Note[] {
    let filtered = this.notes;
    if (this.filterText) {
      const text = this.filterText.toLowerCase();
      filtered = filtered.filter(n =>
        (n.title?.toLowerCase().includes(text) || n.content?.toLowerCase().includes(text))
      );
    }
    if (this.filterClient) {
      const client = this.filterClient.toLowerCase();
      filtered = filtered.filter(n =>
        (n.client?.name?.toLowerCase().includes(client) || n.client_id?.toString().includes(client))
      );
    }
    if (this.filterDate) {
      filtered = filtered.filter(n => n.created_at && n.created_at.startsWith(this.filterDate!));
    }
    filtered = filtered.slice().sort((a, b) => {
      const da = new Date(a.created_at || '').getTime();
      const db = new Date(b.created_at || '').getTime();
      return this.sortOrder === 'asc' ? da - db : db - da;
    });
    return filtered;
  }

  get filteredGroupedNotes() {
    const grouped: { [clientKey: string]: Note[] } = {};
    for (const note of this.filteredNotes) {
      const key = note.client?.name || note.client_id?.toString() || 'Sin cliente';
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(note);
    }
    return grouped;
  }

  clearFilters() {
    this.filterText = '';
    this.filterClient = '';
    this.filterDate = null;
    this.sortOrder = 'desc';
  }
}
