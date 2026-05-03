<x-remedic-mail
    title="[ATTENZIONE] Eliminato movimento di cassa"
    :preheader="'Eliminato un movimento di cassa il '.$details['deleted_at_label'].'.'"
    eyebrow="Warning automatico"
    intro="E stato eliminato un movimento dalla sezione Cassa. Di seguito trovi il riepilogo operativo dell'azione."
    footer-note="Questa email viene inviata automaticamente ai destinatari di controllo configurati per la cassa."
>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="padding:14px 16px; border-radius:18px; background:rgba(242, 153, 74, 0.12); border:1px solid rgba(242, 153, 74, 0.22);">
                <p style="margin:0; font-size:13px; line-height:1.5; color:#9a5b0f; text-transform:uppercase; letter-spacing:0.08em; font-weight:700;">
                    Eliminazione registrata
                </p>
                <p style="margin:8px 0 0; font-size:22px; line-height:1.2; color:#1b4e65; font-weight:700;">
                    {{ $details['deleted_at_label'] }}
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="padding:0 0 12px;">
                <strong>Tipo movimento:</strong> {{ $details['movement_type_label'] }}<br>
                <strong>Cassa coinvolta:</strong> {{ $details['cash_box_label'] }}<br>
                <strong>Importo:</strong> {{ $details['amount_label'] }}<br>
                <strong>Causale:</strong> {{ $details['reason_label'] }}<br>
                <strong>Note:</strong> {{ $details['notes_label'] }}<br>
                <strong>Utente:</strong> {{ $details['actor_label'] }}<br>
                <strong>Riferimento tecnico:</strong> #{{ $details['movement_id'] }}
            </td>
        </tr>
    </table>
</x-remedic-mail>
