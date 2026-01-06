import { Routes } from '@angular/router';
import { DashboardComponent } from './components/dashboard/dashboard.component';
import { ContactsComponent } from './components/contacts/contacts.component';
import { CampaignsComponent } from './components/campaigns/campaigns.component';
import { ConversationsComponent } from './components/conversations/conversations.component';
import { LoginComponent } from './components/login/login.component';
import { authGuard } from './guards/auth.guard';

export const routes: Routes = [
  { path: 'login', component: LoginComponent },
  { path: '', redirectTo: '/dashboard', pathMatch: 'full' },
  { path: 'dashboard', component: DashboardComponent, canActivate: [authGuard] },
  { path: 'contacts', component: ContactsComponent, canActivate: [authGuard] },
  { path: 'campaigns', component: CampaignsComponent, canActivate: [authGuard] },
  { path: 'conversations', component: ConversationsComponent, canActivate: [authGuard] },
];
