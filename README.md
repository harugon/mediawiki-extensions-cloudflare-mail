# CloudflareMail

MediaWiki が送信するすべてのメール（パスワード再設定、メールアドレス確認、ウォッチリスト通知、`Special:EmailUser`、Echo 通知など）を
[Cloudflare Email Service](https://developers.cloudflare.com/email-service/) の
[REST API](https://developers.cloudflare.com/email-service/api/send-emails/rest-api/) 経由で送信する拡張機能です。

- SMTP サーバーや `sendmail` が不要です（HTTPS 443 番ポートのみで送信できます）
- DKIM / ARC 署名は Cloudflare 側で自動付与されます
- 送信結果（Message ID、バウンス）をログに記録し、失敗時は MediaWiki の `Status` としてエラーを返します

## 要件

- MediaWiki 1.43 以降
- PHP 8.1 以降（`iconv`, `mbstring` 拡張）
- Cloudflare で Email Sending 用のドメインがオンボード済みであること

## インストール

1. このリポジトリを `extensions/CloudflareMail` に配置します。

   ```sh
   cd extensions
   git clone https://github.com/harugon/mediawiki-extensions-cloudflare-mail.git CloudflareMail
   ```

2. Cloudflare ダッシュボードで **Email Sending: Edit** 権限を持つ API トークンを作成します。

3. `LocalSettings.php` に以下を追加します。

   ```php
   wfLoadExtension( 'CloudflareMail' );

   $wgCloudflareMailAccountId = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
   // トークンはリポジトリに含めず、環境変数などから読み込むことを推奨します
   $wgCloudflareMailApiToken = getenv( 'CLOUDFLARE_EMAIL_API_TOKEN' );

   // 送信元アドレスは Cloudflare でオンボードしたドメインのものにしてください
   $wgPasswordSender = 'wiki@example.com';
   $wgEmergencyContact = 'wiki@example.com';
   ```

4. 送信テストを行います。

   ```sh
   php maintenance/run.php CloudflareMail:sendTestEmail --to=you@example.com
   php maintenance/run.php CloudflareMail:sendTestEmail --to=you@example.com --html
   ```

## 設定項目

| 変数 | 既定値 | 説明 |
| --- | --- | --- |
| `$wgCloudflareMailAccountId` | `''` | Cloudflare のアカウント ID |
| `$wgCloudflareMailApiToken` | `''` | **Email Sending: Edit** 権限付きの API トークン |
| `$wgCloudflareMailApiBaseUrl` | `https://api.cloudflare.com/client/v4` | API のベース URL（テスト用） |
| `$wgCloudflareMailTimeout` | `10` | 1 リクエストあたりのタイムアウト（秒） |
| `$wgCloudflareMailMaxRetries` | `2` | 通信エラー・HTTP 429・HTTP 5xx 時の再試行回数（`Retry-After` を尊重、最大 10 秒） |
| `$wgCloudflareMailFallbackOnError` | `false` | `true` にすると、Cloudflare への送信が失敗した場合に MediaWiki 標準の送信手段（`$wgSMTP` / `mail()`）へフォールバックします |

アカウント ID とトークンのどちらかが未設定の場合、この拡張は何もせず MediaWiki 標準の送信処理に任せます。

ログは `CloudflareMail` チャンネルに出力されます。

```php
$wgDebugLogGroups['CloudflareMail'] = '/var/log/mediawiki/cloudflaremail.log';
```

## 動作の詳細

### 仕組み

`UserMailer` が組み立てたメッセージを `UserMailerTransformMessage` フックで受け取り、Cloudflare に送信します。
送信に成功した場合は `AlternateUserMailer` フックで MediaWiki 標準の送信処理をスキップします。

本来は `AlternateUserMailer` フックだけで完結させるのが自然ですが、MediaWiki の `HookContainer` は
ハンドラーが真偽値以外を返すことを許可していないため、同フックからは送信失敗を呼び出し元に伝えられません。
`UserMailerTransformMessage` フックはエラーメッセージを返して送信を中断できるため、こちらで送信しています。

> [!NOTE]
> `UserMailerTransformMessage` フックでメール本文を加工する他の拡張機能（暗号化・署名など）と併用する場合は、
> `LocalSettings.php` でその拡張機能を **CloudflareMail より先に** `wfLoadExtension()` してください。

### 変換内容

| MediaWiki | Cloudflare REST API |
| --- | --- |
| 差出人 (`MailAddress`) | `from`（表示名付き。`$wgEnotifUseRealName` を考慮） |
| 宛先 | `to`（**1 宛先につき 1 リクエスト**。他の受信者にアドレスが漏れないようにするため） |
| `Reply-To` ヘッダー | `reply_to` |
| MIME 本文（text/plain、または `$wgAllowHTMLEmail` 有効時の multipart/alternative） | デコードして `text` / `html` |
| その他のヘッダー | Cloudflare の[許可リスト](https://developers.cloudflare.com/email-service/reference/headers/)に載っているもの（`List-Unsubscribe` など）と `X-*` のみ転送。`Date`、`Message-ID` などは Cloudflare 側で付与されます |

### エラーの扱い

- API がエラーを返した場合や、受信サーバーに恒久的に拒否された（`permanent_bounces`）場合は、
  `php-mail-error` として MediaWiki にエラーを返します（例: 「メールの送信に失敗しました」）。
- 抑制リストにより除外された宛先（`suppressed_recipients`）は警告ログのみ記録し、成功扱いにします。
- 複数宛先のうち一部だけ失敗した場合、重複送信を避けるためフォールバックは行わずエラーを返します。

## SDK ではなく REST API を直接使っている理由

Cloudflare は Email Service 用の公式 SDK を Node.js / Python / Go 向けにしか提供しておらず、PHP 版はありません。
エンドポイントは `POST /accounts/{account_id}/email/sending/send` の 1 つだけなので、
MediaWiki 本体が同梱している Guzzle（`HttpRequestFactory::createGuzzleClient()` 経由。プロキシ設定や User-Agent は MediaWiki の設定に従います）で直接呼び出しています。
追加の Composer 依存はありません。

## 参考: SMTP で送る場合

Cloudflare Email Service は SMTP 送信（`smtp.mx.cloudflare.net:465`）にも対応しているため、
拡張機能なしで `$wgSMTP` を設定する方法もあります。

```php
$wgSMTP = [
	'host' => 'ssl://smtp.mx.cloudflare.net',
	'IDHost' => 'example.com',
	'localhost' => 'example.com',
	'port' => 465,
	'auth' => true,
	'username' => 'api_token',
	'password' => getenv( 'CLOUDFLARE_EMAIL_API_TOKEN' ),
];
```

この拡張機能は、送信ポートが制限された環境でも HTTPS だけで送信できること、API の詳細なエラー内容・Message ID をログに残せること、
再試行やフォールバックを制御できることが利点です。

## 開発

```sh
composer install
composer test        # parallel-lint + phpcs + phpunit
```

`composer phpunit` は MediaWiki 本体なしで単体テストを実行します（`tests/standalone/` に最小限のスタブがあります）。
MediaWiki 本体のテストランナーからも実行できます。

```sh
# MediaWiki のルートディレクトリで
vendor/bin/phpunit extensions/CloudflareMail/tests/phpunit
```

## ライセンス

MIT
