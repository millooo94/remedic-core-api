<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Prospetto professionista</title>
    <style>
        @page { margin: 32px 34px 42px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #12384a; }
        .page { width: 100%; }
        .header-table, .meta-table, .records-table, .footer-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .logo-wrap { width: 170px; }
        .logo-wrap svg { width: 150px; height: auto; }
        .title-wrap { text-align: right; }
        .eyebrow { color: #5f7383; font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; }
        h1 { margin: 6px 0 4px; font-size: 20px; line-height: 1.1; }
        .subtitle { margin: 0; color: #5f7383; font-size: 10px; }
        .summary {
            margin: 22px 0 18px;
            padding: 18px 20px;
            border: 1px solid #d8e5ea;
            border-radius: 18px;
            background: #f8fbfc;
        }
        .meta-table td {
            padding: 7px 0;
            border-bottom: 1px solid #e5eef2;
        }
        .meta-label { width: 170px; color: #5f7383; }
        .meta-value { font-weight: 700; text-align: right; }
        .message-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #edf7d2;
            color: #12384a;
            font-weight: 700;
        }
        .message-note {
            margin-top: 10px;
            color: #5f7383;
            font-size: 9px;
            line-height: 1.45;
        }
        h2 { margin: 0 0 10px; font-size: 14px; }
        .records-table th, .records-table td {
            padding: 9px 10px;
            border: 1px solid #deeaee;
            vertical-align: top;
        }
        .records-table th {
            background: #ebf6f8;
            color: #12384a;
            font-size: 9px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .text-right { text-align: right; }
        .muted { color: #5f7383; }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            line-height: 1.1;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .status-badge.pending {
            color: #0f667d;
            background: #e8f5f8;
            border-color: #c7e4eb;
        }
        .status-badge.invoiced {
            color: #6a4f11;
            background: #f8f1d5;
            border-color: #ead9a4;
        }
        .footer-table { margin-top: 18px; }
        .footer-table td {
            padding-top: 10px;
            font-size: 9px;
            color: #5f7383;
        }
        .footer-right { text-align: right; }
    </style>
</head>
<body>
<div class="page">
    <table class="header-table">
        <tr>
            <td class="logo-wrap">{!! $logoSvg !!}</td>
            <td class="title-wrap">
                <div class="eyebrow">Remedic</div>
                <h1>Prospetto professionista</h1>
                <p class="subtitle">Generato il {{ \Illuminate\Support\Carbon::parse($statement['generated_at'])->format('d/m/Y H:i') }}</p>
            </td>
        </tr>
    </table>

    <div class="summary">
        <table class="meta-table">
            <tr>
                <td class="meta-label">Professionista</td>
                <td class="meta-value">{{ $statement['professional']['full_name'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Area</td>
                <td class="meta-value">{{ $statement['professional']['area_name'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">IBAN</td>
                <td class="meta-value">{{ $statement['professional']['iban_display'] ?? 'Non indicato' }}</td>
            </tr>
            <tr>
                <td class="meta-label">Intervallo considerato</td>
                <td class="meta-value">{{ $statement['period']['label'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Prestazioni conteggiate</td>
                <td class="meta-value">{{ $statement['totals']['performance_count'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Gia fatturate</td>
                <td class="meta-value">{{ $statement['totals']['already_invoiced_count'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Totale da fatturare</td>
                <td class="meta-value">&euro; {{ number_format($statement['totals']['professional_amount'], 2, ',', '.') }}</td>
            </tr>
        </table>

        <div class="message-box">{{ $statement['message'] }}</div>
        @if (($statement['totals']['already_invoiced_count'] ?? 0) > 0)
            <div class="message-note">
                Prestazioni gia fatturate escluse dal calcolo: {{ $statement['totals']['already_invoiced_count'] }}
                per un totale di &euro; {{ number_format($statement['totals']['already_invoiced_amount'], 2, ',', '.') }}.
            </div>
        @endif
    </div>

    <h2>Prestazioni considerate</h2>
    <table class="records-table">
        <thead>
        <tr>
            <th style="width: 72px;">Data</th>
            <th>Prestazione</th>
            <th style="width: 64px;" class="text-right">Quantita</th>
            <th style="width: 110px;" class="text-right">Importo Prestazione</th>
            <th style="width: 110px;" class="text-right">Quota Professionista</th>
            <th style="width: 110px;">Fatturazione</th>
            <th style="width: 140px;">Note</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($statement['records'] as $record)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($record['performed_at'])->format('d/m/Y') }}</td>
                <td>{{ $record['service_name'] }}</td>
                <td class="text-right">{{ number_format((int) $record['quantity'], 0, ',', '.') }}</td>
                <td class="text-right">&euro; {{ number_format($record['total_amount'], 2, ',', '.') }}</td>
                <td class="text-right">&euro; {{ number_format($record['professional_amount'], 2, ',', '.') }}</td>
                <td>
                    <span class="status-badge {{ $record['is_invoiced'] ? 'invoiced' : 'pending' }}">
                        {{ $record['invoicing_status'] }}
                    </span>
                </td>
                <td>{{ $record['notes'] ?: '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="muted">Nessuna prestazione registrata nell'intervallo selezionato.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <table class="footer-table">
        <tr>
            <td>Documento amministrativo interno Remedic</td>
            <td class="footer-right">Humancare Telemedicine S.r.l.</td>
        </tr>
    </table>
</div>
</body>
</html>
