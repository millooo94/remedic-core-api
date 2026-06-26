<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $exportData['document_title'] }}</title>
    <style>
        @page { margin: 28px 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #12384a; }
        .page { width: 100%; }
        .header-table, .records-table, .footer-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .logo-wrap { width: 170px; }
        .logo-wrap svg { width: 148px; height: auto; }
        .title-wrap { text-align: right; }
        .eyebrow {
            color: #5f7383;
            font-size: 8px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        h1 {
            margin: 6px 0 4px;
            font-size: 18px;
            line-height: 1.1;
        }
        .subtitle {
            margin: 0;
            color: #5f7383;
            font-size: 10px;
        }
        .top-strip {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid #dce8ed;
            background: #f8fbfc;
        }
        .top-strip-table {
            width: 100%;
            border-collapse: collapse;
        }
        .top-strip-table td {
            vertical-align: middle;
        }
        .top-strip-kicker {
            font-size: 8px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6b808d;
        }
        .top-strip-title {
            margin-top: 4px;
            font-size: 14px;
            font-weight: 700;
            color: #12384a;
        }
        .top-strip-period {
            margin-top: 3px;
            color: #5f7383;
            font-size: 10px;
        }
        .top-strip-filters {
            margin-top: 4px;
            color: #6b808d;
            font-size: 8px;
            line-height: 1.45;
        }
        .top-strip-pill {
            text-align: right;
        }
        .pill {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 8px;
            line-height: 1;
            font-weight: 700;
            color: #0f667d;
            background: #e7f5f8;
            border: 1px solid #c8e7ec;
        }
        .hero-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }
        .hero-main-card {
            padding: 16px 18px 18px;
            border-radius: 22px;
            background: linear-gradient(135deg, #12384a 0%, #1b556e 100%);
            color: #f4fbfc;
        }
        .hero-label {
            font-size: 8px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(244, 251, 252, 0.72);
            font-weight: 700;
        }
        .hero-amount {
            margin-top: 10px;
            font-size: 28px;
            line-height: 1;
            font-weight: 700;
            letter-spacing: -0.03em;
        }
        .hero-meta-table {
            width: 100%;
            margin-top: 14px;
            border-collapse: collapse;
            border-top: 1px solid rgba(244, 251, 252, 0.16);
        }
        .hero-meta-table td {
            width: 33.33%;
            padding-top: 10px;
            vertical-align: top;
        }
        .hero-meta-label {
            display: block;
            font-size: 8px;
            color: rgba(244, 251, 252, 0.72);
        }
        .hero-meta-value {
            display: block;
            margin-top: 3px;
            font-size: 12px;
            color: #ffffff;
            font-weight: 700;
        }
        .section {
            margin-top: 18px;
        }
        .section-heading {
            margin: 0 0 4px;
            font-size: 13px;
            color: #12384a;
        }
        .section-copy {
            margin: 0 0 10px;
            font-size: 9px;
            line-height: 1.45;
            color: #5f7383;
        }
        .breakdown-grid-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .breakdown-grid-table td {
            vertical-align: top;
        }
        .breakdown-card {
            padding: 13px 14px;
            border-radius: 18px;
            border: 1px solid #dde8ec;
            background: #ffffff;
        }
        .breakdown-card-head {
            width: 100%;
            border-collapse: collapse;
        }
        .breakdown-card-title {
            font-size: 10px;
            font-weight: 700;
            color: #12384a;
        }
        .breakdown-card-share {
            text-align: right;
            font-size: 8px;
            color: #5f7383;
            font-weight: 700;
        }
        .breakdown-card-amount {
            margin-top: 8px;
            font-size: 18px;
            font-weight: 700;
            color: #12384a;
        }
        .breakdown-card-meta {
            margin-top: 8px;
            font-size: 8px;
            color: #5f7383;
        }
        .professional-card {
            margin-top: 10px;
            padding: 13px 14px 14px;
            border-radius: 20px;
            border: 1px solid #dde8ec;
            background: #ffffff;
        }
        .professional-card-head {
            width: 100%;
            border-collapse: collapse;
        }
        .professional-name {
            font-size: 11px;
            font-weight: 700;
            color: #12384a;
        }
        .professional-meta {
            margin-top: 3px;
            font-size: 8px;
            color: #5f7383;
        }
        .professional-total-pill {
            text-align: right;
            font-size: 8px;
            font-weight: 700;
            color: #12384a;
        }
        .professional-total-pill span {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef4f6;
            border: 1px solid #d9e5ea;
        }
        .mini-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
        }
        .mini-table th,
        .mini-table td {
            padding: 7px 4px;
            font-size: 9px;
        }
        .mini-table th {
            font-size: 8px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #5f7383;
            border-bottom: 1px solid #dbe7eb;
        }
        .mini-table td {
            border-bottom: 1px solid #edf2f4;
        }
        .mini-table tr:last-child td {
            border-bottom: 0;
        }
        .mini-table .total-row td {
            padding-top: 9px;
            border-top: 1px solid #d5e1e6;
            font-weight: 700;
            color: #12384a;
            background: #f8fbfc;
        }
        .records-table {
            table-layout: fixed;
        }
        .records-table th,
        .records-table td {
            padding: 7px 8px;
            border: 1px solid #deeaee;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .records-table th {
            background: #ebf6f8;
            color: #12384a;
            font-size: 8px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .text-right { text-align: right; }
        .muted { color: #5f7383; }
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 7px;
            line-height: 1.1;
            font-weight: 700;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status-badge.pending {
            color: #6a4f11;
            background: #f8f1d5;
            border-color: #ead9a4;
        }
        .status-badge.liquidated {
            color: #0f667d;
            background: #e8f5f8;
            border-color: #c7e4eb;
        }
        .footer-table { margin-top: 16px; }
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
    @php
        $visibleFiscalTypes = $exportData['visible_fiscal_types'] ?? [];
        $breakdownRows = array_values(array_filter($exportData['fiscal_breakdown'] ?? [], fn ($row) => ($row['type'] ?? null) !== 'total'));
        $showSpeciali = in_array('black', $visibleFiscalTypes, true);
        $showProvvigione = in_array('provvigione', $visibleFiscalTypes, true);
        $breakdownHeading = $showSpeciali && $showProvvigione
            ? 'Ripartizione ordinarie / speciali / provvigioni'
            : ($showSpeciali
                ? 'Ripartizione ordinarie / speciali'
                : ($showProvvigione
                    ? (in_array('white', $visibleFiscalTypes, true) ? 'Ripartizione ordinarie / provvigioni' : 'Ripartizione provvigioni')
                    : 'Ripartizione quota professionista'));
        $breakdownCopy = $showSpeciali && $showProvvigione
            ? 'Ordinarie, speciali e provvigioni sono mostrate come ripartizione della quota professionista totale.'
            : ($showSpeciali
                ? 'Ordinarie e speciali sono mostrate come ripartizione della quota professionista totale.'
                : ($showProvvigione
                    ? (in_array('white', $visibleFiscalTypes, true)
                        ? 'Ordinarie e provvigioni sono mostrate come ripartizione della quota professionista totale.'
                        : 'Nel prospetto corrente sono presenti solo prestazioni in provvigione.')
                    : 'Nel prospetto corrente sono presenti solo prestazioni ordinarie.'));
        $professionalBreakdownCopy = $showSpeciali && $showProvvigione
            ? 'Ogni professionista mostra ordinarie, speciali, provvigioni e totale in una scheda ordinata, con maggiore enfasi sulla riga finale.'
            : ($showSpeciali
                ? 'Ogni professionista mostra ordinarie, speciali e totale in una scheda ordinata, con maggiore enfasi sulla riga finale.'
                : ($showProvvigione
                    ? (in_array('white', $visibleFiscalTypes, true)
                        ? 'Ogni professionista mostra ordinarie, provvigioni e totale in una scheda ordinata.'
                        : 'Ogni professionista mostra le provvigioni del periodo filtrato e il totale finale.')
                    : 'Ogni professionista mostra la quota professionista ordinaria e il totale del periodo filtrato.'));
    @endphp

    <table class="header-table">
        <tr>
            <td class="logo-wrap">{!! $logoSvg !!}</td>
            <td class="title-wrap">
                <div class="eyebrow">Remedic</div>
                <h1>{{ $exportData['document_title'] }}</h1>
                <p class="subtitle">Generato il {{ \Illuminate\Support\Carbon::parse($exportData['generated_at'])->format('d/m/Y H:i') }}</p>
            </td>
        </tr>
    </table>

    <div class="top-strip">
        <table class="top-strip-table">
            <tr>
                <td>
                    <div class="top-strip-kicker">Prestazioni filtrate</div>
                    <div class="top-strip-title">{{ $exportData['document_title'] }}</div>
                    <div class="top-strip-period">{{ $exportData['period']['label'] }}</div>
                    @if (!empty($exportData['applied_filters']))
                        <div class="top-strip-filters">{{ implode(' · ', $exportData['applied_filters']) }}</div>
                    @endif
                </td>
                <td class="top-strip-pill">
                    <span class="pill">Filtri tabella attivi</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="hero-table">
        <tr>
            <td style="width: 100%;">
                <div class="hero-main-card">
                    <div class="hero-label">Totale quota professionista filtrata</div>
                    <div class="hero-amount">&euro; {{ number_format($exportData['totals']['professional_amount'], 2, ',', '.') }}</div>
                    <table class="hero-meta-table">
                        <tr>
                            <td>
                                <span class="hero-meta-label">Prestazioni filtrate</span>
                                <span class="hero-meta-value">{{ number_format($exportData['totals']['performance_count'], 0, ',', '.') }}</span>
                            </td>
                            <td>
                                <span class="hero-meta-label">Professionisti coinvolti</span>
                                <span class="hero-meta-value">{{ number_format($exportData['totals']['professional_count'], 0, ',', '.') }}</span>
                            </td>
                            <td>
                                <span class="hero-meta-label">Righe esportate</span>
                                <span class="hero-meta-value">{{ number_format($exportData['totals']['records_count'], 0, ',', '.') }}</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    @if (!empty($breakdownRows))
        <div class="section">
            <h2 class="section-heading">{{ $breakdownHeading }}</h2>
            <p class="section-copy">{{ $breakdownCopy }}</p>
            <table class="breakdown-grid-table">
                <tr>
                    @foreach ($breakdownRows as $row)
                        <td style="width: {{ number_format(100 / max(count($breakdownRows), 1), 2, '.', '') }}%; {{ $loop->first ? 'padding-right: 6px;' : 'padding-left: 6px;' }}">
                            <div class="breakdown-card">
                                <table class="breakdown-card-head">
                                    <tr>
                                        <td class="breakdown-card-title">{{ $row['label'] }}</td>
                                        <td class="breakdown-card-share">
                                            {{ $exportData['totals']['professional_amount'] > 0 ? number_format(($row['professional_amount'] / $exportData['totals']['professional_amount']) * 100, 0, ',', '.') : 0 }}% del totale
                                        </td>
                                    </tr>
                                </table>
                                <div class="breakdown-card-amount">&euro; {{ number_format($row['professional_amount'], 2, ',', '.') }}</div>
                                <div class="breakdown-card-meta">
                                    {{ number_format($row['performance_count'], 0, ',', '.') }} prestazioni ·
                                    {{ number_format($row['records_count'], 0, ',', '.') }} righe
                                </div>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    <div class="section">
        <h2 class="section-heading">Quota professionista per professionista</h2>
        <p class="section-copy">{{ $professionalBreakdownCopy }}</p>
        @foreach ($exportData['professional_subtotals'] as $subtotal)
            <div class="professional-card">
                <table class="professional-card-head">
                    <tr>
                        <td>
                            <div class="professional-name">{{ $subtotal['professional_name'] }}</div>
                            <div class="professional-meta">{{ number_format($subtotal['total']['performance_count'], 0, ',', '.') }} prestazioni filtrate</div>
                        </td>
                        <td class="professional-total-pill">
                            <span>&euro; {{ number_format($subtotal['total']['professional_amount'], 2, ',', '.') }}</span>
                        </td>
                    </tr>
                </table>
                <table class="mini-table">
                    <thead>
                    <tr>
                        <th style="width: 40%;">Tipo</th>
                        <th style="width: 20%;" class="text-right">Prestazioni</th>
                        <th style="width: 40%;" class="text-right">Quota professionista</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($subtotal['fiscal_breakdown'] as $row)
                        @if (($row['type'] ?? null) === 'total' || in_array($row['type'], $visibleFiscalTypes, true))
                            <tr class="{{ $row['type'] === 'total' ? 'total-row' : '' }}">
                                <td>{{ $row['label'] }}</td>
                                <td class="text-right">{{ number_format($row['performance_count'], 0, ',', '.') }}</td>
                                <td class="text-right">&euro; {{ number_format($row['professional_amount'], 2, ',', '.') }}</td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <div class="section">
        <h2 class="section-heading">Prestazioni esportate</h2>
        <table class="records-table">
            <thead>
            <tr>
                <th style="width: 8%;">Data</th>
                <th style="width: 16%;">Professionista</th>
                <th style="width: 10%;">Area</th>
                <th style="width: 18%;">Prestazione</th>
                <th style="width: 6%;" class="text-right">Qta</th>
                <th style="width: 11%;" class="text-right">Importo</th>
                <th style="width: 11%;" class="text-right">Quota Prof.</th>
                <th style="width: 8%;">Liquidazione</th>
                <th style="width: 8%;">Fatturazione</th>
                <th style="width: 6%;">Tipo</th>
                <th style="width: 14%;">Note</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($exportData['records'] as $record)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($record['performed_at'])->format('d/m/Y') }}</td>
                    <td>{{ $record['professional_name'] }}</td>
                    <td>{{ $record['area_name'] }}</td>
                    <td>{{ $record['service_name'] }}</td>
                    <td class="text-right">{{ number_format((int) $record['quantity'], 0, ',', '.') }}</td>
                    <td class="text-right">&euro; {{ number_format($record['total_amount'], 2, ',', '.') }}</td>
                    <td class="text-right">&euro; {{ number_format($record['professional_amount'], 2, ',', '.') }}</td>
                    <td>
                        <span class="status-badge {{ $record['payment_status'] === 'pagata' ? 'liquidated' : 'pending' }}">
                            {{ $record['payment_status_label'] }}
                        </span>
                    </td>
                    <td>{{ $record['invoicing_status'] }}</td>
                    <td>{{ $record['fiscal_type_label'] }}</td>
                    <td>{{ $record['notes'] ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="muted">Nessuna prestazione corrisponde ai filtri applicati.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <table class="footer-table">
        <tr>
            <td>Documento amministrativo interno Remedic</td>
            <td class="footer-right">Humancare Telemedicine S.r.l.</td>
        </tr>
    </table>
</div>
</body>
</html>
