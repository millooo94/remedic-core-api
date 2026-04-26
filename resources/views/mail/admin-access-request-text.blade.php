[ATTENZIONE] Nuova richiesta di accesso alla dashboard - Remedic

Un nuovo utente ha richiesto l'accesso alla dashboard privata Remedic.

Nome: {{ $user->name }}
Cognome: {{ $user->last_name }}
Email: {{ $user->email }}
Richiesta ricevuta il: {{ optional($requestedAt)->timezone(config('auth.access_approval.display_timezone', 'Europe/Rome'))->format('d/m/Y H:i') }}

Approva la richiesta:
{{ $approvalUrl }}

Rifiuta o disattiva la richiesta:
{{ $rejectUrl }}
