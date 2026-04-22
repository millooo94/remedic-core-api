@props([
    'title',
    'preheader' => null,
    'eyebrow' => 'Remedic',
    'intro' => null,
    'actionText' => null,
    'actionUrl' => null,
    'closing' => 'Team Remedic',
    'footerNote' => null,
])

@php
    $productName = (string) config('mail.branding.product_name', config('app.name', 'Remedic'));
    $companyName = (string) config('mail.branding.company_name', 'Humancare Telemedicine S.r.l.');
    $websiteUrl = (string) config('mail.branding.website_url', config('app.frontend_url', config('app.url', 'http://localhost')));
    $logoUrl = (string) config('mail.branding.logo_url', rtrim((string) config('app.url', 'http://localhost'), '/').'/images/logo.svg');
    $supportEmail = (string) config('mail.branding.support_email', config('mail.from.address', 'humancaretelemedicine@gmail.com'));
    $resolvedFooterNote = $footerNote ?: 'Per assistenza puoi rispondere a questa email oppure scrivere a '.$supportEmail.'.';
@endphp

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#edf5f7; font-family:Arial, Helvetica, sans-serif; color:#16384a;">
    <span style="display:none!important; visibility:hidden; mso-hide:all; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">
        {{ $preheader ?: $title }}
    </span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; background:linear-gradient(180deg, #f6fbfc 0%, #edf5f7 100%);">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; max-width:640px; border-collapse:collapse;">
                    <tr>
                        <td align="center" style="padding-bottom:18px;">
                            <a href="{{ $websiteUrl }}" style="display:inline-block; text-decoration:none;">
                                <img src="{{ $logoUrl }}" alt="{{ $productName }}" width="176" style="display:block; width:176px; max-width:100%; height:auto; border:0;">
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#ffffff; border:1px solid rgba(22,56,74,0.08); border-radius:28px; padding:32px 34px; box-shadow:0 18px 44px rgba(12,37,51,0.12);">
                            <p style="margin:0 0 14px; font-size:12px; line-height:1.4; letter-spacing:0.16em; text-transform:uppercase; font-weight:700; color:#0f766e;">
                                {{ $eyebrow }}
                            </p>

                            <h1 style="margin:0; font-size:28px; line-height:1.18; letter-spacing:-0.03em; color:#1b4e65;">
                                {{ $title }}
                            </h1>

                            @if ($intro)
                                <p style="margin:16px 0 0; font-size:16px; line-height:1.65; color:#4f677c;">
                                    {{ $intro }}
                                </p>
                            @endif

                            <div style="margin-top:22px; font-size:15px; line-height:1.7; color:#24465a;">
                                {!! $slot !!}
                            </div>

                            @if ($actionText && $actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:28px; border-collapse:collapse;">
                                    <tr>
                                        <td>
                                            <a
                                                href="{{ $actionUrl }}"
                                                style="display:inline-block; border-radius:999px; background:linear-gradient(135deg, #1c9ebd 0%, #157e98 100%); color:#ffffff; text-decoration:none; font-size:15px; font-weight:700; padding:14px 24px;"
                                            >
                                                {{ $actionText }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#647b8c;">
                                    Se il pulsante non funziona, copia e incolla questo link nel browser:<br>
                                    <a href="{{ $actionUrl }}" style="color:#157e98; text-decoration:none; word-break:break-all;">{{ $actionUrl }}</a>
                                </p>
                            @endif

                            <p style="margin:28px 0 0; font-size:15px; line-height:1.65; color:#24465a;">
                                Grazie,<br>
                                <strong>{{ $closing }}</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 12px 0; text-align:center;">
                            <p style="margin:0; font-size:12px; line-height:1.7; color:#6c8292;">
                                {{ $companyName }} | {{ $productName }}<br>
                                {{ $resolvedFooterNote }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
