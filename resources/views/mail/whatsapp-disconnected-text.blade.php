Remedic Core - WhatsApp scollegato

Il canale WhatsApp di Remedic Core risulta scollegato o non operativo.
Accedi al gestionale e vai in Integrazioni > WhatsApp per generare o scansionare un nuovo QR.

Stato precedente: {{ $details['previous_state_label'] ?? 'Non disponibile' }}
Stato attuale: {{ $details['current_state_label'] ?? 'Non disponibile' }}
Numero collegato: {{ $details['phone_number'] ?? 'Non disponibile' }}
Data/Ora evento: {{ $details['event_at_label'] ?? 'Non disponibile' }}
Dettaglio errore: {{ $details['last_error'] ?? 'Non disponibile' }}

@if(!empty($details['app_url']))
Gestionale: {{ $details['app_url'] }}
@endif
