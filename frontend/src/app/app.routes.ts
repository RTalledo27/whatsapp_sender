import { Routes } from '@angular/router';
import { DashboardComponent } from './components/dashboard/dashboard.component';
import { ContactsComponent } from './components/contacts/contacts.component';
import { CampaignsComponent } from './components/campaigns/campaigns.component';
import { ConversationsComponent } from './components/conversations/conversations.component';

export const routes: Routes = [
  { path: '', redirectTo: '/dashboard', pathMatch: 'full' },
  { path: 'dashboard', component: DashboardComponent },
  { path: 'contacts', component: ContactsComponent },
  { path: 'campaigns', component: CampaignsComponent },
  { path: 'conversations', component: ConversationsComponent },
];
