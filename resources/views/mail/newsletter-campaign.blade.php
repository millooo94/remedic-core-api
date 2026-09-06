@php($paragraphs = preg_split("/\r\n|\n|\r/", trim((string) $bodyText)) ?: [])

<x-remedic-mail :title="$subjectLine" :preheader="$preheader" eyebrow="Newsletter Remedic" :footer-note="$isTest ? 'Questa è un’email di prova: non modifica la tua iscrizione alla newsletter.' : null">
    @foreach ($paragraphs as $paragraph)
        @if (trim($paragraph) !== '')
            <p style="margin:0 0 14px;">{{ $paragraph }}</p>
        @endif
    @endforeach

    @if ($unsubscribeUrl)
        <p style="margin:28px 0 0; font-size:13px; line-height:1.6; color:#647b8c;">
            Non desideri più ricevere la newsletter?
            <a href="{{ $unsubscribeUrl }}" style="color:#157e98; text-decoration:none;">Disiscriviti qui</a>.
        </p>
    @endif
</x-remedic-mail>
