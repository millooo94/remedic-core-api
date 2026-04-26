@php
    $kpis = $summary['kpis'];
@endphp

<x-remedic-mail
    title="Riepilogo settimanale Remedic Core"
    :preheader="'Riepilogo centro dal '.$summary['period']['label']"
    eyebrow="Report settimanale"
    intro="Questo riepilogo sintetizza i principali indicatori economici del centro per la settimana appena conclusa."
    :footer-note="'Email automatica settimanale inviata ogni domenica alle 10:30 (Europe/Rome).'"
>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="padding:14px 16px; border-radius:18px; background:rgba(28, 158, 189, 0.08); border:1px solid rgba(28, 158, 189, 0.14);">
                <p style="margin:0; font-size:13px; line-height:1.5; color:#157e98; text-transform:uppercase; letter-spacing:0.08em; font-weight:700;">
                    Periodo analizzato
                </p>
                <p style="margin:8px 0 0; font-size:22px; line-height:1.2; color:#1b4e65; font-weight:700;">
                    {{ $summary['period']['label'] }}
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Prestazioni effettuate</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">{{ number_format($kpis['total_performances'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Fatturato totale prestazioni</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_revenue_amount'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Quota professionisti</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_professional_amount'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Quota centro</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_center_amount'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Costi fissi</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_fixed_costs'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Costi variabili</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_variable_costs'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Totale costi centro</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['total_center_costs'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12);">Margine netto centro</td>
            <td align="right" style="padding:12px 0; border-bottom:1px solid rgba(22,56,74,0.12); font-weight:700;">€ {{ number_format($kpis['net_center_margin'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0;">Quota centro Black</td>
            <td align="right" style="padding:12px 0; font-weight:700;">€ {{ number_format($kpis['black_center_net'], 2, ',', '.') }}</td>
        </tr>
    </table>
</x-remedic-mail>

