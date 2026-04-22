Conferma il tuo indirizzo email - Remedic

@if ($recipientName !== '')
Ciao {{ $recipientName }},
@else
Ciao,
@endif

abbiamo ricevuto una richiesta di verifica per questo indirizzo email.

Per completare l'attivazione del tuo account Remedic, apri questo link:
{{ $verificationUrl }}

Se non hai creato tu l'account o non hai richiesto questa operazione, puoi ignorare il messaggio.

Grazie,
Team Remedic
