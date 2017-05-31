# SilverStripe Mailgunner

[![Build Status](http://img.shields.io/travis/kinglozzer/silverstripe-mailgunner.svg?style=flat-square)](https://travis-ci.org/kinglozzer/silverstripe-mailgunner)
[![Code Quality](http://img.shields.io/scrutinizer/g/kinglozzer/silverstripe-mailgunner.svg?style=flat-square)](https://scrutinizer-ci.com/g/kinglozzer/silverstripe-mailgunner)
[![Code Coverage](http://img.shields.io/scrutinizer/coverage/g/kinglozzer/silverstripe-mailgunner.svg?style=flat-square)](https://scrutinizer-ci.com/g/kinglozzer/silverstripe-mailgunner)
[![Version](http://img.shields.io/packagist/v/kinglozzer/silverstripe-mailgunner.svg?style=flat-square)](https://packagist.org/packages/kinglozzer/silverstripe-mailgunner)
[![License](http://img.shields.io/packagist/l/kinglozzer/silverstripe-mailgunner.svg?style=flat-square)](LICENSE.md)

A SilverStripe `Mailer` class to send emails via the [Mailgun](http://www.mailgun.com/) API.

## Installation:

```bash
$ composer require kinglozzer/silverstripe-mailgunner:^3.0 php-http/curl-client guzzlehttp/psr7
```

Note that the Mailgun PHP library uses HTTPlug, so you can switch out the HTTP adapter for another one if you desire. More info is available [here](http://docs.php-http.org/en/latest/httplug/users.html).

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

## Batch messages:

You can send an email to a group of recipients via a single API call (without using Cc or Bcc) using Mailgun’s [batch sending](https://documentation.mailgun.com/user_manual.html#batch-sending) functionality. This tells Mailgun to send each recipient an email with only their name in the `to` field. Not using this functionality would result in _all_ recipients’ email addresses being displayed in the `to` field.

To send a batch email, simply pass all the email addresses you want to send to as the `to` field, and add an `X-Mailgunner-Batch-Message` header:

```php
$email = Email::create(
	$from = 'noreply@example.com',
	$to = 'user1@example.com, user2@example.com',
	$subject = 'My awesome email',
	$content = 'Hello world!'
);

$email->addCustomHeader('X-Mailgunner-Batch-Message', true);
$email->send();
```

## SilverStripe version compatiblity:

This module has only been tested with SilverStripe 3.2+, but should work with previous versions. In older versions of SilverStripe, you will need to specify the `Mailer` implementation to use in `_config.php`:

```php
<?php

Email::set_mailer(new Kinglozzer\SilverStripeMailgunner\Mailer);
```
