Riepilogo settimanale Remedic Core
Periodo: {{ $summary['period']['label'] }}

Prestazioni effettuate: {{ number_format($summary['kpis']['total_performances'], 0, ',', '.') }}
Fatturato totale prestazioni: € {{ number_format($summary['kpis']['total_revenue_amount'], 2, ',', '.') }}
Quota professionisti: € {{ number_format($summary['kpis']['total_professional_amount'], 2, ',', '.') }}
Quota centro: € {{ number_format($summary['kpis']['total_center_amount'], 2, ',', '.') }}
Costi fissi: € {{ number_format($summary['kpis']['total_fixed_costs'], 2, ',', '.') }}
Costi variabili: € {{ number_format($summary['kpis']['total_variable_costs'], 2, ',', '.') }}
Totale costi centro: € {{ number_format($summary['kpis']['total_center_costs'], 2, ',', '.') }}
Margine netto centro: € {{ number_format($summary['kpis']['net_center_margin'], 2, ',', '.') }}
Quota centro Black: € {{ number_format($summary['kpis']['black_center_net'], 2, ',', '.') }}

Report inviato automaticamente ogni domenica alle 10:30 (Europe/Rome).
Team Remedic

