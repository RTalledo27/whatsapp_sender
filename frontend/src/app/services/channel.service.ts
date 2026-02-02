import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export interface Channel {
  id: 'whatsapp' | 'email' | 'sms';
  name: string;
}

@Injectable({
  providedIn: 'root'
})
export class ChannelService {
  private channels: Channel[] = [
    { id: 'whatsapp', name: 'WhatsApp' },
    { id: 'email', name: 'Email' },
    { id: 'sms', name: 'SMS' }
  ];

  private selectedChannelSubject = new BehaviorSubject<Channel>(this.channels[0]);
  selectedChannel$ = this.selectedChannelSubject.asObservable();

  constructor() {}

  getChannels(): Channel[] {
    return this.channels;
  }

  getSelectedChannel(): Channel {
    return this.selectedChannelSubject.value;
  }

  setChannel(channel: Channel): void {
    this.selectedChannelSubject.next(channel);
  }
}
