{{ $subjectLine }} - Remedic

Promemoria automatico del {{ $reminderDate->format('d/m/Y') }}

{{ $bodyText }}

Verifica i conteggi del periodo e prepara i prospetti da inviare a {{ $companyName }}.

Una copia di questa email viene inviata automaticamente anche a {{ config('mail.reminder_copy_recipient', 'humancaretelemedicine@gmail.com') }}.

Team Remedic
