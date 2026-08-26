<x-remedic-mail title="Nuova candidatura ricevuta" :intro="$application->first_name.' '.$application->last_name">
    <p>Email: {{ $application->email }}</p>
    @if ($application->phone)<p>Telefono: {{ $application->phone }}</p>@endif
    <p>Tipo: {{ $application->application_type_name_snapshot }}</p>
    <p>Ricevuta: {{ $application->submitted_at?->format('d/m/Y H:i') }}</p>
    <p>Messaggio: {{ $application->message }}</p>
    <p><a href="{{ $careerUrl }}">Apri candidatura in Remedic Core</a></p>
</x-remedic-mail>
