import { Routes } from '@angular/router';
import { DashboardComponent } from './components/dashboard/dashboard.component';
import { ContactsComponent } from './components/contacts/contacts.component';
import { CampaignsComponent } from './components/campaigns/campaigns.component';
import { ConversationsComponent } from './components/conversations/conversations.component';
import { UsersComponent } from './components/users/users.component';
import { NotesComponent } from './components/notes/notes.component';
import { LoginComponent } from './components/login/login.component';
import { ChatbotConfigComponent } from './components/chatbot-config/chatbot-config.component';
import { authGuard } from './guards/auth.guard';

export const routes: Routes = [
  { path: 'login', component: LoginComponent },
  { path: '', redirectTo: '/dashboard', pathMatch: 'full' },
  { path: 'dashboard', component: DashboardComponent, canActivate: [authGuard] },
  { path: 'contacts', component: ContactsComponent, canActivate: [authGuard] },
  { path: 'campaigns', component: CampaignsComponent, canActivate: [authGuard] },
  { path: 'conversations', component: ConversationsComponent, canActivate: [authGuard] },
  { path: 'chatbot-config', component: ChatbotConfigComponent, canActivate: [authGuard] },
  { path: 'users', component: UsersComponent, canActivate: [authGuard] },
  { path: 'notes', component: NotesComponent, canActivate: [authGuard] },
];
