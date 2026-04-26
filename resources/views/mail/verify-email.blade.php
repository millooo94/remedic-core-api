<x-remedic-mail
    title="Conferma il tuo indirizzo email"
    preheader="Conferma l'email per completare la richiesta di accesso a Remedic."
    eyebrow="Accesso Remedic"
    :intro="$recipientName !== '' ? 'Ciao '.$recipientName.',' : 'Ciao,'"
    action-text="Conferma email"
    :action-url="$verificationUrl"
    footer-note="Questa email viene inviata automaticamente per proteggere l'accesso al tuo account Remedic."
>
    <p style="margin:0 0 16px;">
        Abbiamo ricevuto una richiesta di accesso alla dashboard privata Remedic. Conferma questo indirizzo email
        per validare la tua identita e completare il primo passaggio di sicurezza.
    </p>

    <p style="margin:0 0 16px;">
        Il link di conferma resta valido per un periodo limitato. Se non hai creato tu l'account o non hai richiesto questa operazione,
        puoi ignorare il messaggio senza ulteriori azioni.
    </p>

    <p style="margin:0;">
        Dopo la conferma verrai reindirizzato alla pagina di accesso. L'ingresso alla dashboard restera comunque bloccato
        finche l'amministratore principale non approvera la richiesta.
    </p>
</x-remedic-mail>
