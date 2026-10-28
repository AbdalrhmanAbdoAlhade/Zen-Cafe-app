<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine
        );
    }

    public function content(): Content
    {
        $logoUrl = 'https://api.zensacafe.com/storage/branches/V42n4aEcOg0MjNFFmyugfEPuI6SWGlhHgG07Ujc2.png';

        $branchNameAr = 'فرع الرياض الرئيسي';
        $branchAddress = 'شارع الملك فهد، الرياض، المملكة العربية السعودية';

        $subject = e($this->subjectLine);
        $html = nl2br(e($this->body));
        $year = date('Y');

        return new Content(
            htmlString: <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{$subject}</title>
</head>

<body style="margin:0;padding:0;background-color:#F4F5F0;font-family:Tahoma,Arial,sans-serif;color:#24382D;">

    <!-- Hidden preview text -->
    <div style="display:none;font-size:1px;color:#F4F5F0;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {$subject}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="width:100%;background-color:#F4F5F0;">
        <tr>
            <td align="center" style="padding:32px 12px;">

                <!-- Main container -->
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"
                       style="width:100%;max-width:560px;margin:0 auto;">

                    <!-- Centered brand -->
                    <tr>
                        <td align="center" style="padding:0 16px 26px;text-align:center;">

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="text-align:center;padding-bottom:12px;">
                                        <img
                                            src="{$logoUrl}"
                                            width="76"
                                            alt="Zen Cafe"
                                            style="display:block;width:76px;max-width:76px;height:auto;margin:0 auto;border:0;outline:none;text-decoration:none;"
                                        >
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" style="text-align:center;">
                                        <div style="font-family:Tahoma,Arial,sans-serif;font-size:22px;font-weight:700;line-height:1.6;color:#1B4332;text-align:center;">
                                            Zen Cafe
                                        </div>

                                        <div style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.8;color:#617267;text-align:center;">
                                            {$branchNameAr}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <!-- Email card -->
                    <tr>
                        <td style="background-color:#FFFFFF;border:1px solid #E3E8DF;border-radius:16px;overflow:hidden;">

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

                                <!-- Green title area -->
                                <tr>
                                    <td align="center"
                                        style="background-color:#1B4332;padding:30px 22px;text-align:center;">

                                        <div style="font-family:Tahoma,Arial,sans-serif;font-size:11px;font-weight:700;line-height:1.8;letter-spacing:1px;color:#E9B872;text-align:center;">
                                            ZEN CAFE
                                        </div>

                                        <div style="font-family:Tahoma,Arial,sans-serif;font-size:23px;font-weight:700;line-height:1.7;color:#FFFFFF;text-align:center;overflow-wrap:anywhere;word-break:break-word;">
                                            {$subject}
                                        </div>

                                        <div style="width:42px;height:3px;background-color:#D9A65A;margin:14px auto 0;font-size:1px;line-height:1px;">
                                            &nbsp;
                                        </div>

                                    </td>
                                </tr>

                                <!-- Message content -->
                                <tr>
                                    <td style="padding:32px 28px 28px;">

                                        <div dir="auto"
                                             style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:2;color:#34483C;text-align:start;overflow-wrap:anywhere;word-break:break-word;">
                                            {$html}
                                        </div>

                                    </td>
                                </tr>

                                <!-- Signature -->
                                <tr>
                                    <td style="padding:0 28px 26px;">

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="border-top:1px solid #E8EAE4;padding-top:20px;text-align:center;">

                                                    <div style="font-family:Tahoma,Arial,sans-serif;font-size:14px;font-weight:700;line-height:1.8;color:#1B4332;text-align:center;">
                                                        شكرًا لاختيارك زين كافيه
                                                    </div>

                                                    <div style="font-family:Tahoma,Arial,sans-serif;font-size:12px;line-height:1.8;color:#879187;text-align:center;">
                                                        نتمنى لك تجربة رائعة ☕
                                                    </div>

                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>

                            </table>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding:24px 12px 0;text-align:center;">

                            <div style="font-family:Tahoma,Arial,sans-serif;font-size:12px;line-height:2;color:#6E7B70;text-align:center;">
                                {$branchAddress}
                            </div>

                            <div style="font-family:Tahoma,Arial,sans-serif;font-size:11px;line-height:2;color:#9AA398;text-align:center;padding-top:12px;">
                                © {$year} Zen Cafe — جميع الحقوق محفوظة
                            </div>

                        </td>
                    </tr>

                </table>
                <!-- End main container -->

            </td>
        </tr>
    </table>

</body>
</html>
HTML
        );
    }
}
