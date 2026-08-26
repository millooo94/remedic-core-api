<x-remedic-mail
    title="Conferma la tua iscrizione"
    preheader="Conferma il tuo indirizzo per ricevere la newsletter Remedic."
    eyebrow="Newsletter Remedic"
    intro="Hai richiesto di ricevere aggiornamenti e contenuti dedicati alla salute. Conferma il tuo indirizzo email per completare l'iscrizione."
    footer-note="Se non hai richiesto l'iscrizione, puoi ignorare questa email."
>
    <p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#27485a;">Il link è valido per un periodo limitato, per proteggere la tua richiesta.</p>
    <p style="margin:0;"><a href="{{ $confirmationUrl }}" style="display:inline-block; padding:13px 20px; border-radius:999px; background:#157e98; color:#ffffff; text-decoration:none; font-weight:700;">Conferma iscrizione</a></p>
</x-remedic-mail>
