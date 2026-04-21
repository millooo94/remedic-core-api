<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Report mensile centro</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; }
        h1 { font-size: 20px; margin-bottom: 0; }
        p { margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Report mensile centro</h1>
    <p>Generato il {{ $generatedAt->format('d/m/Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Prestazioni</th>
                <th>Lordo</th>
                <th>Quota professionista</th>
                <th>Quota centro</th>
                <th>Da fatturare</th>
                <th>Fatturato</th>
                <th>Pagato</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['total_records'] }}</td>
                    <td>€ {{ number_format($row['gross_total'], 2, ',', '.') }}</td>
                    <td>€ {{ number_format($row['doctor_total'], 2, ',', '.') }}</td>
                    <td>€ {{ number_format($row['center_total'], 2, ',', '.') }}</td>
                    <td>€ {{ number_format($row['to_invoice_total'], 2, ',', '.') }}</td>
                    <td>€ {{ number_format($row['invoiced_total'], 2, ',', '.') }}</td>
                    <td>€ {{ number_format($row['paid_total'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
