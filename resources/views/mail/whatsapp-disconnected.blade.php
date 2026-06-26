<x-remedic-mail
    title="WhatsApp scollegato"
    :preheader="'Il canale WhatsApp di Remedic Core non risulta piu operativo.'"
    eyebrow="Avviso automatico"
    intro="Il canale WhatsApp di Remedic Core risulta scollegato o non operativo. Accedi al gestionale e vai in Integrazioni > WhatsApp per generare o scansionare un nuovo QR."
    :action-text="$details['app_url'] ? 'Apri il gestionale' : null"
    :action-url="$details['app_url']"
    footer-note="Questa email viene inviata solo quando il canale passa da operativo a non operativo."
>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="padding:14px 16px; border-radius:18px; background:rgba(182, 54, 49, 0.08); border:1px solid rgba(182, 54, 49, 0.18);">
                <p style="margin:0; font-size:13px; line-height:1.5; color:#9d342f; text-transform:uppercase; letter-spacing:0.08em; font-weight:700;">
                    Evento rilevato
                </p>
                <p style="margin:8px 0 0; font-size:22px; line-height:1.2; color:#1b4e65; font-weight:700;">
                    {{ $details['event_at_label'] ?? 'Non disponibile' }}
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="padding:0 0 12px;">
                <strong>Stato precedente:</strong> {{ $details['previous_state_label'] ?? 'Non disponibile' }}<br>
                <strong>Stato attuale:</strong> {{ $details['current_state_label'] ?? 'Non disponibile' }}<br>
                <strong>Numero collegato:</strong> {{ $details['phone_number'] ?? 'Non disponibile' }}<br>
                <strong>Dettaglio errore:</strong> {{ $details['last_error'] ?? 'Non disponibile' }}
            </td>
        </tr>
    </table>
</x-remedic-mail>
