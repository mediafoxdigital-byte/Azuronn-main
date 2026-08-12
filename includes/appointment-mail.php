<?php

declare(strict_types=1);

// Appointment confirmation email — branded, premium, inline-styled HTML + plain
// text fallback. Uses PHP's native mail() (no library in the repo). The booking
// is already persisted before this runs, so a mail failure never loses data;
// the admin has a Resend action as the safety net.

require_once __DIR__ . '/appointments.php';

/** Build the confirmation email payload (to/subject/html/text) without sending. */
function appointment_confirmation_email(array $booking): array
{
    $to = (string) ($booking['email'] ?? '');
    $ref = (string) ($booking['ref'] ?? '');
    $serviceLabel = (string) ($booking['service_label'] ?? $booking['service'] ?? 'Appointment');
    $date = (string) ($booking['date'] ?? '');
    $time = (string) ($booking['time'] ?? '');
    $duration = (int) ($booking['duration'] ?? 60);
    $firstName = (string) ($booking['first_name'] ?? '');
    $lastName = (string) ($booking['last_name'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    $mobile = (string) ($booking['mobile'] ?? '');
    $countryCode = (string) ($booking['country_code'] ?? '');
    $notes = (string) ($booking['notes'] ?? '');

    $dateLong = $date !== '' ? appointment_format_date_long($date) : $date;
    $time12 = $time !== '' ? appointment_format_time_12($time) : $time;
    $durationLabel = $duration >= 60
        ? (intdiv($duration, 60) . ' hour' . (intdiv($duration, 60) > 1 ? 's' : '') . (($duration % 60) ? ' ' . ($duration % 60) . ' min' : ''))
        : ($duration . ' minutes');

    $brandName = defined('SITE_NAME') ? SITE_NAME : 'Azuronn';
    $storeEmail = defined('STORE_EMAIL') ? STORE_EMAIL : '';
    $storePhone = defined('STORE_PHONE') ? STORE_PHONE : '';
    $storeAddress = defined('STORE_ADDRESS') ? STORE_ADDRESS : '';
    $siteUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    $logoPath = defined('SITE_LOGO_PATH') ? SITE_LOGO_PATH : '';
    $confirmedUrl = $siteUrl . '/appointment/confirmed/?ref=' . urlencode($ref);

    $subject = 'Your ' . $brandName . ' appointment is confirmed — ' . $ref;

    // ── Plain-text fallback ──
    $textLines = [
        'Your ' . $brandName . ' appointment is confirmed.',
        '',
        'Reference: ' . $ref,
        'Service: ' . $serviceLabel,
        'Date & time: ' . $dateLong . ' at ' . $time12,
        'Duration: ' . $durationLabel,
        'Name: ' . $fullName,
    ];
    if ($mobile !== '') {
        $textLines[] = 'Mobile: ' . ($countryCode !== '' ? $countryCode . ' ' : '') . $mobile;
    }
    if ($notes !== '') {
        $textLines[] = 'Notes: ' . $notes;
    }
    $textLines[] = '';
    $textLines[] = 'View your confirmation: ' . $confirmedUrl;
    $textLines[] = '';
    $textLines[] = 'We look forward to seeing you.';
    if ($storeAddress !== '') {
        $textLines[] = $storeAddress;
    }
    if ($storePhone !== '') {
        $textLines[] = $storePhone;
    }
    if ($storeEmail !== '') {
        $textLines[] = $storeEmail;
    }
    $text = implode("\n", $textLines);

    // ── Inline-styled HTML (email clients strip <style> blocks) ──
    $gold = '#c9a96e';
    $green = '#143b32';
    $ink = '#1c1c1c';
    $muted = '#6b6b6b';
    $line = '#e8e8e6';
    $bg = '#faf8f3';

    $logoHtml = '';
    if ($logoPath !== '') {
        $logoSrc = str_starts_with($logoPath, 'http') ? $logoPath : $siteUrl . $logoPath;
        $logoHtml = '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES) . '" alt="' . htmlspecialchars($brandName, ENT_QUOTES) . '" style="max-height:42px; display:block; margin:0 auto;" />';
    } else {
        $logoHtml = '<span style="font-family:Georgia,serif; font-size:1.6rem; color:' . $ink . '; letter-spacing:0.04em;">' . htmlspecialchars($brandName, ENT_QUOTES) . '</span>';
    }

    $row = static function (string $label, string $value) use ($muted, $ink, $line): string {
        return '<tr><td style="padding:10px 0; border-bottom:1px solid ' . $line . '; font-family:Arial,sans-serif; font-size:0.85rem; color:' . $muted . '; width:38%;">' . htmlspecialchars($label, ENT_QUOTES) . '</td>'
            . '<td style="padding:10px 0; border-bottom:1px solid ' . $line . '; font-family:Arial,sans-serif; font-size:0.95rem; color:' . $ink . '; font-weight:600;">' . htmlspecialchars($value, ENT_QUOTES) . '</td></tr>';
    };

    $detailRows = $row('Reference', $ref)
        . $row('Service', $serviceLabel)
        . $row('Date', $dateLong)
        . $row('Time', $time12)
        . $row('Duration', $durationLabel)
        . $row('Name', $fullName);
    if ($mobile !== '') {
        $detailRows .= $row('Mobile', ($countryCode !== '' ? $countryCode . ' ' : '') . $mobile);
    }
    if ($notes !== '') {
        $detailRows .= $row('Notes', $notes);
    }

    $socialLinks = '';
    $socials = [
        'Facebook' => defined('SOCIAL_FACEBOOK') ? SOCIAL_FACEBOOK : '',
        'Instagram' => defined('SOCIAL_INSTAGRAM') ? SOCIAL_INSTAGRAM : '',
        'Twitter' => defined('SOCIAL_TWITTER') ? SOCIAL_TWITTER : '',
    ];
    foreach ($socials as $label => $url) {
        if ($url !== '' && $url !== '#') {
            $socialLinks .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="color:' . $gold . '; text-decoration:none; margin-right:14px; font-size:0.8rem;">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }
    }

    $footerContact = '';
    if ($storeAddress !== '') {
        $footerContact .= '<div style="margin-bottom:4px;">' . htmlspecialchars($storeAddress, ENT_QUOTES) . '</div>';
    }
    if ($storePhone !== '') {
        $footerContact .= '<div style="margin-bottom:4px;">' . htmlspecialchars($storePhone, ENT_QUOTES) . '</div>';
    }
    if ($storeEmail !== '') {
        $footerContact .= '<div>' . htmlspecialchars($storeEmail, ENT_QUOTES) . '</div>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
        . '<body style="margin:0; padding:0; background:' . $bg . '; font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';"><tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.06);">'
        // Header
        . '<tr><td style="background:' . $green . '; padding:28px 32px; text-align:center;">' . $logoHtml . '</td></tr>'
        // Body
        . '<tr><td style="padding:36px 32px 28px;">'
        . '<h1 style="margin:0 0 8px; font-family:Georgia,serif; font-size:1.7rem; font-weight:400; color:' . $ink . '; line-height:1.2;">Your appointment is confirmed</h1>'
        . '<p style="margin:0 0 24px; font-size:0.95rem; color:' . $muted . '; line-height:1.5;">Thank you, ' . htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES) . '. We look forward to welcoming you to ' . htmlspecialchars($brandName, ENT_QUOTES) . '. Please keep this confirmation for your records.</p>'
        // Details table
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $line . '; border-radius:6px;">'
        . '<tr><td style="padding:4px 20px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $detailRows . '</table>'
        . '</td></tr></table>'
        // CTA
        . '<div style="margin:28px 0 8px; text-align:center;">'
        . '<a href="' . htmlspecialchars($confirmedUrl, ENT_QUOTES) . '" style="display:inline-block; padding:13px 30px; background:linear-gradient(135deg,' . $gold . ',#b08a4f); color:#ffffff; text-decoration:none; border-radius:6px; font-size:0.85rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">View Confirmation</a>'
        . '</div>'
        . '<p style="margin:18px 0 0; font-size:0.8rem; color:' . $muted . '; text-align:center; line-height:1.5;">Show this page to your consultant on arrival, or quote reference <strong style="color:' . $ink . ';">' . htmlspecialchars($ref, ENT_QUOTES) . '</strong>.</p>'
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:#f4f4f3; padding:24px 32px; border-top:1px solid ' . $line . '; text-align:center;">'
        . '<div style="font-size:0.8rem; color:' . $muted . '; line-height:1.6; margin-bottom:10px;">' . $footerContact . '</div>'
        . ($socialLinks !== '' ? '<div style="margin-bottom:8px;">' . $socialLinks . '</div>' : '')
        . '<div style="font-size:0.72rem; color:#9a9a9a;">&copy; ' . date('Y') . ' ' . htmlspecialchars($brandName, ENT_QUOTES) . '. All rights reserved.</div>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [
        'to' => $to,
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

/** Send the confirmation email. Returns true on success, false on any failure.
 *  Never throws — the booking is already persisted; the admin can Resend. */
function appointment_send_confirmation(array $booking): bool
{
    $to = (string) ($booking['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $email = appointment_confirmation_email($booking);
        $subject = (string) ($email['subject'] ?? '');
        $html = (string) ($email['html'] ?? '');
        $text = (string) ($email['text'] ?? '');

        $fromName = defined('SITE_NAME') ? SITE_NAME : 'Azuronn';
        $fromEmail = defined('STORE_EMAIL') ? STORE_EMAIL : 'noreply@example.com';

        $boundary = 'az-apt-' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Azuronn/1.0',
        ];

        $body = "This is a multi-part message in MIME format.\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";

        $result = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        return (bool) $result;
    } catch (\Throwable $e) {
        return false;
    }
}
