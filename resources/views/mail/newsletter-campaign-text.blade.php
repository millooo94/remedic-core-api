{{ $subjectLine }}

{{ $bodyText }}

@if ($unsubscribeUrl)
Per non ricevere più la newsletter, visita questo link:
{{ $unsubscribeUrl }}
@endif

@if ($isTest)
Questa è un’email di prova e non modifica la tua iscrizione alla newsletter.
@endif
