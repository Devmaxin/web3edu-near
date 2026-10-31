<?php
/**
 * Wraps inner template HTML in a consistent branded shell. Deliberately a
 * LIGHT theme with inline styles and table-based layout — dark-mode HTML
 * emails have inconsistent support across clients (Outlook, older Gmail),
 * so this intentionally does not mirror the app's dark v2 theme.
 */
function renderEmailLayout(string $innerHtml, string $preheader = ''): string
{
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Web3Edu</title>
</head>
<body style="margin:0;padding:0;background:#F3F0FA;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;">
<span style="display:none;max-height:0;overflow:hidden;">{$preheader}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F0FA;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" width="100%" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(124,58,237,0.08);">
      <tr>
        <td style="background:linear-gradient(135deg,#4C1D95,#7C3AED,#C026D3);padding:28px 32px;">
          <table role="presentation" width="100%"><tr>
            <td style="color:#ffffff;font-size:18px;font-weight:700;font-family:Georgia,serif;">
              <img src="{$siteUrl}/assets/images/logo-wordmark.png" alt="Web3Edu" height="26" style="height:26px;width:auto;display:block;border:0;">
            </td>
          </tr></table>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;color:#1A1330;font-size:15px;line-height:1.6;">
          {$innerHtml}
        </td>
      </tr>
      <tr>
        <td style="padding:20px 32px;background:#FAF9FC;border-top:1px solid #EDE9FE;">
          <p style="margin:0;color:#8A81A3;font-size:12px;">
            Web3Edu &middot; <a href="{$siteUrl}" style="color:#7C3AED;text-decoration:none;">web3edu.name.ng</a>
          </p>
          <p style="margin:8px 0 0;color:#8A81A3;font-size:11px;">
            You're receiving this because you have a Web3Edu account. <a href="{$siteUrl}/profile.php?tab=settings" style="color:#7C3AED;">Manage email preferences</a>
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}
