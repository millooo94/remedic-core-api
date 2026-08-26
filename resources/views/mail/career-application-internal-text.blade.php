Nuova candidatura: {{ $application->first_name }} {{ $application->last_name }}
Email: {{ $application->email }}
@if ($application->phone)Telefono: {{ $application->phone }}
@endif
Tipo: {{ $application->application_type_name_snapshot }}
Ricevuta: {{ $application->submitted_at?->format('d/m/Y H:i') }}
Messaggio: {{ $application->message }}

Apri candidatura in Remedic Core: {{ $careerUrl }}
