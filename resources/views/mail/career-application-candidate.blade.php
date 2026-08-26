<x-remedic-mail :title="$copy['title']" :intro="$copy['intro']">
    <p>{{ $copy['type_label'] }}: {{ $application->application_type_name_snapshot }}</p>
    <p>{{ $copy['confirmation'] }}</p>
    <p>{{ $copy['closing'] }}</p>
</x-remedic-mail>
