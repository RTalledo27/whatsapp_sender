import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { UserService, User } from '../../services/user.service';
import { CampaignService } from '../../services/campaign.service';

@Component({
  selector: 'app-users',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './users.component.html',
  styleUrls: ['./users.component.scss']
})
export class UsersComponent implements OnInit {
  users: User[] = [];
  loading = false;
  showModal = false;
  showConfigModal = false;
  isEditing = false;
  availableNumbers: any[] = [];

  currentUser: Partial<User> & { password?: string } = {
    name: '',
    email: '',
    password: '',
    role: 'user',
    phone_number_id: null,
    phone_number_name: null,
    visible_components: null
  };

  currentConfigUser: User | null = null;
  availableComponents = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'contacts', label: 'Contactos' },
    { id: 'campaigns', label: 'Campañas' },
    { id: 'conversations', label: 'Conversaciones' },
    { id: 'chatbot-config', label: 'Chatbot' },
    { id: 'notes', label: 'Notas' }
  ];
  selectedComponents: string[] = [];

  constructor(
    private userService: UserService,
    private campaignService: CampaignService
  ) {}

  ngOnInit() {
    this.loadUsers();
    this.loadAvailableNumbers();
  }

  loadUsers() {
    this.loading = true;
    this.userService.getUsers().subscribe({
      next: (data) => {
        this.users = data;
        this.loading = false;
      },
      error: (error) => {
        console.error('Error loading users:', error);
        this.loading = false;
      }
    });
  }

  loadAvailableNumbers() {
    this.campaignService.getAvailableNumbers().subscribe({
      next: (data) => {
        this.availableNumbers = data.numbers || [];
      },
      error: (error) => {
        console.error('Error loading numbers:', error);
      }
    });
  }

  openCreateModal() {
    this.isEditing = false;
    this.currentUser = {
      name: '',
      email: '',
      password: '',
      role: 'user',
      phone_number_id: null,
      phone_number_name: null,
      visible_components: null
    };
    this.showModal = true;
  }

  openEditModal(user: User) {
    this.isEditing = true;
    this.currentUser = { ...user, password: '' };
    this.showModal = true;
  }

  closeModal() {
    this.showModal = false;
  }

  onPhoneNumberChange() {
    const selected = this.availableNumbers.find(n => n.id === this.currentUser.phone_number_id);
    if (selected) {
      this.currentUser.phone_number_name = selected.name;
    }
  }

  saveUser() {
    if (this.isEditing && this.currentUser.id) {
      const updateData = { ...this.currentUser };
      if (!updateData.password) {
        delete updateData.password;
      }
      
      this.userService.updateUser(this.currentUser.id, updateData).subscribe({
        next: () => {
          this.loadUsers();
          this.closeModal();
        },
        error: (error) => {
          console.error('Error updating user:', error);
          alert('Error al actualizar usuario');
        }
      });
    } else {
      this.userService.createUser(this.currentUser as any).subscribe({
        next: () => {
          this.loadUsers();
          this.closeModal();
        },
        error: (error) => {
          console.error('Error creating user:', error);
          alert('Error al crear usuario');
        }
      });
    }
  }

  deleteUser(user: User) {
    if (confirm(`¿Estás seguro de eliminar al usuario ${user.name}?`)) {
      this.userService.deleteUser(user.id).subscribe({
        next: () => {
          this.loadUsers();
        },
        error: (error) => {
          console.error('Error deleting user:', error);
          alert('Error al eliminar usuario');
        }
      });
    }
  }

  getRoleBadgeClass(role: string): string {
    return role === 'admin' ? 'badge-admin' : 'badge-user';
  }

  openConfigModal(user: User) {
    this.currentConfigUser = user;
    if (user.visible_components && Array.isArray(user.visible_components)) {
      this.selectedComponents = [...user.visible_components];
    } else {
      this.selectedComponents = this.availableComponents.map(c => c.id);
    }
    this.showConfigModal = true;
  }

  closeConfigModal() {
    this.showConfigModal = false;
    this.currentConfigUser = null;
  }

  isComponentVisible(id: string): boolean {
    return this.selectedComponents.includes(id);
  }

  toggleComponent(id: string, event: Event) {
    const isChecked = (event.target as HTMLInputElement).checked;
    if (isChecked) {
      if (!this.selectedComponents.includes(id)) {
        this.selectedComponents.push(id);
      }
    } else {
      this.selectedComponents = this.selectedComponents.filter(c => c !== id);
    }
  }

  saveConfig() {
    if (this.currentConfigUser) {
      const updateData = { visible_components: this.selectedComponents };
      this.userService.updateUser(this.currentConfigUser.id, updateData).subscribe({
        next: () => {
          this.loadUsers();
          this.closeConfigModal();
        },
        error: (error) => {
          console.error('Error updating config:', error);
          alert('Error al guardar la configuración');
        }
      });
    }
  }
}
