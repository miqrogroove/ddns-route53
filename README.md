# DDNS for Route53 in PHP
This script can query a website to reveal the client's external IPv4 address,
check if any change has occurred, then if needed, use the Route53 API to update a DNS record.

Code is minimal and could be expanded easily.  I wrote this because there were
no good examples in PHP and I just wanted a workable solution.

## Requirements
- PHP
- curl
- AWS SDK for PHP v3

## Configuration
1. Place these files in an existing SDK project, or install the SDK using `composer require aws/aws-sdk-php`
1. Copy the `config-dist.php` to `config.php` and provide the DNS details.
1. Copy the `credentials-dist` to `~/.aws/credentials` and provide the AWS keys.
1. Run like `php -f ddns.php` or cron like `*/10 * * * * /usr/bin/php -f /path/to/ddns.php`
