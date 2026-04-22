@php
    $copyRecipient = (string) config('mail.reminder_copy_recipient', 'humancaretelemedicine@gmail.com');
@endphp

<x-remedic-mail
    :title="$subjectLine"
    :preheader="'Promemoria operativo Remedic del '.$reminderDate->format('d/m/Y').'.'"
    eyebrow="Promemoria automatico"
    intro="Ti inviamo il promemoria programmato per la gestione operativa del periodo."
    :footer-note="'Questo promemoria viene inviato automaticamente anche a '.$copyRecipient.'.'"
>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="padding:14px 16px; border-radius:18px; background:rgba(28, 158, 189, 0.08); border:1px solid rgba(28, 158, 189, 0.14);">
                <p style="margin:0; font-size:13px; line-height:1.5; color:#157e98; text-transform:uppercase; letter-spacing:0.08em; font-weight:700;">
                    Data promemoria
                </p>
                <p style="margin:8px 0 0; font-size:22px; line-height:1.2; color:#1b4e65; font-weight:700;">
                    {{ $reminderDate->format('d/m/Y') }}
                </p>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px;">
        {!! nl2br(e($bodyText)) !!}
    </p>

    <p style="margin:0;">
        Verifica i conteggi del periodo e prepara i prospetti da inviare a {{ $companyName }}.
    </p>
</x-remedic-mail>
