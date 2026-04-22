<x-remedic-mail
    title="Conferma il tuo indirizzo email"
    preheader="Conferma l'email per attivare il tuo account Remedic."
    eyebrow="Accesso Remedic"
    :intro="$recipientName !== '' ? 'Ciao '.$recipientName.',' : 'Ciao,'"
    action-text="Conferma email"
    :action-url="$verificationUrl"
    footer-note="Questa email viene inviata automaticamente per proteggere l'accesso al tuo account Remedic."
>
    <p style="margin:0 0 16px;">
        Abbiamo ricevuto una richiesta di verifica per questo indirizzo email. Confermalo per completare
        l'attivazione del tuo account e accedere in sicurezza alla piattaforma.
    </p>

    <p style="margin:0 0 16px;">
        Il link di conferma resta valido per un periodo limitato. Se non hai creato tu l'account o non hai richiesto questa operazione,
        puoi ignorare il messaggio senza ulteriori azioni.
    </p>

    <p style="margin:0;">
        Dopo la conferma verrai reindirizzato alla pagina di accesso e potrai iniziare a usare Remedic normalmente.
    </p>
</x-remedic-mail>
