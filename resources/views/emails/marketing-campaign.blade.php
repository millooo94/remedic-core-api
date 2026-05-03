@php
  $paragraphs = preg_split("/\r\n|\n|\r/", trim((string) $bodyText)) ?: [];
@endphp

<!DOCTYPE html>
<html lang="it">
  <head>
    <meta charset="utf-8" />
    <title>{{ $subjectLine }}</title>
  </head>
  <body style="margin:0;padding:24px;background:#f4f8fa;font-family:Arial,sans-serif;color:#12384a;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:18px;border:1px solid rgba(18,56,74,0.08);overflow:hidden;">
      <tr>
        <td style="padding:28px 28px 22px;">
          <h1 style="margin:0 0 16px;font-size:24px;line-height:1.2;color:#157e98;">{{ $subjectLine }}</h1>
          @foreach ($paragraphs as $paragraph)
            @if (trim($paragraph) !== '')
              <p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#12384a;">{{ $paragraph }}</p>
            @endif
          @endforeach
        </td>
      </tr>
    </table>
  </body>
</html>
