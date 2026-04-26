<x-remedic-mail
    title="Nuova richiesta di accesso alla dashboard"
    preheader="Un nuovo utente ha richiesto l'accesso alla dashboard privata Remedic."
    eyebrow="Attenzione"
    intro="E stata registrata una nuova richiesta di accesso."
    action-text="Approva richiesta"
    :action-url="$approvalUrl"
    footer-note="Questa notifica e stata inviata automaticamente dal sistema di accesso Remedic."
>
    <p style="margin:0 0 16px;">
        Un nuovo utente ha completato la registrazione e attende la tua approvazione amministrativa.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin:0 0 20px;">
        <tr>
            <td style="padding:14px 16px; border:1px solid rgba(18, 56, 74, 0.12); border-radius:18px; background-color:#f7fbfc;">
                <p style="margin:0 0 10px;"><strong>Nome:</strong> {{ $user->name }}</p>
                <p style="margin:0 0 10px;"><strong>Cognome:</strong> {{ $user->last_name }}</p>
                <p style="margin:0 0 10px;"><strong>Email:</strong> {{ $user->email }}</p>
                <p style="margin:0;"><strong>Richiesta ricevuta il:</strong> {{ optional($requestedAt)->timezone(config('auth.access_approval.display_timezone', 'Europe/Rome'))->format('d/m/Y H:i') }}</p>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 18px;">
        Approva l'accesso solo dopo aver verificato che la richiesta sia legittima e coerente con l'uso della dashboard privata.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td style="padding-right:12px; padding-bottom:12px;">
                <a
                    href="{{ $rejectUrl }}"
                    style="display:inline-block; border-radius:999px; background:#ffffff; border:1px solid rgba(180, 35, 24, 0.26); color:#8f2f25; text-decoration:none; font-size:15px; font-weight:700; padding:13px 22px;"
                >
                    Rifiuta o disattiva
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:4px 0 0; font-size:13px; line-height:1.7; color:#647b8c;">
        Link di rifiuto/disattivazione:<br>
        <a href="{{ $rejectUrl }}" style="color:#8f2f25; text-decoration:none; word-break:break-all;">{{ $rejectUrl }}</a>
    </p>
</x-remedic-mail>
