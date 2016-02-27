# SilverStripe Mailgunner

A SilverStripe `Mailer` class to send emails via the [Mailgun](http://www.mailgun.com/) API.

## Installation:

```bash
$ composer require kinglozzer/silverstripe-mailgunner:^1.0
```

## Configuration:

Set your API key and domain name via the `Config` API:

```yml
---
After: 'silverstripe-mailgun'
---
Kinglozzer\SilverStripeMailgunner\Mailer:
  api_domain: 'samples.mailgun.org'
  api_key: 'key-3ax6xnjp29jd6fds4gc373sgvjxteol0'
---
After: 'silverstripe-mailgun'
Only:
  environment: 'dev'
---
# Get your own Postbin ID by visiting http://bin.mailgun.net
Kinglozzer\SilverStripeMailgunner\Mailer:
  api_endpoint: 'bin.mailgun.net'
  api_ssl: false
  api_version: '8932206e'
```

## SilverStripe version compatiblity:

This module has only been tested with SilverStripe 3.2, but may work with previous versions. In older versions of SilverStripe, you will need to specify the `Mailer` implementation to use in `_config.php`:

```php
<?php

Email::set_mailer(new Kinglozzer\SilverStripeMailgunner\Mailer);
```
