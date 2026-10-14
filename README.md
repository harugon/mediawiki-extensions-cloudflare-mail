# CloudflareMail

MediaWiki のメールを [Cloudflare Email Service](https://developers.cloudflare.com/email-service/) の
[REST API](https://developers.cloudflare.com/email-service/api/send-emails/rest-api/) 経由で送信する拡張機能です。

SMTP や `mail()` は使わず、HTTPS の API だけで送信します。

## 要件

- MediaWiki 1.43 以降
- Cloudflare で Email Sending 用のドメインを設定済みであること

## インストール

```php
wfLoadExtension( 'CloudflareMail' );

$wgCloudflareMailAccountId = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
// 「Email Sending: Edit」権限を持つ API トークン
$wgCloudflareMailApiToken = getenv( 'CLOUDFLARE_EMAIL_API_TOKEN' );

// Cloudflare に設定したドメインのアドレス
$wgPasswordSender = 'wiki@example.com';

// HTML メールには対応していません（MediaWiki の既定値も false です）
$wgAllowHTMLEmail = false;
```

## 動作

- `AlternateUserMailer` フックでメールを受け取り、`POST /accounts/{account_id}/email/sending/send` に送ります。
- 宛先が複数ある場合は 1 宛先ずつ送信します（受信者同士にアドレスが見えないようにするため）。
- `Reply-To`（`Special:EmailUser` で送信者に返信するためのアドレス）は `reply_to` として送ります。その他のヘッダーは送りません。
- 送信に失敗した場合は `CloudflareMail` ログチャンネルに記録し、例外を投げます。
  `mail()` や `$wgSMTP` へのフォールバックはしません（IP アドレスが漏れるのを防ぐため）。
  設定が未入力の場合や `$wgAllowHTMLEmail = true` の場合も同様にエラーになります。

## ライセンス

MIT
