# DDNS for Route 53 in PHP
This script can query a website to reveal the client's external IPv4 address,
check if any change has occurred, then update a DNS record in Route 53.

There is an extra option to enable checking the IPv6 address of a
named interface, which is useful in stateless addressing environments.
When adding this configuration, the IAM user permission policy for Route 53
must allow updates to both DNS record types.

Code is minimal and could be expanded easily.  I wrote this because there were
no good examples in PHP.

## Requirements
- PHP
- curl
- AWS SDK for PHP v3

## Configuration
1. Place these files in an existing SDK project, or install the SDK using `composer require aws/aws-sdk-php`
1. Copy the `config-dist.php` to `config.php` and provide the DNS details.
1. Copy the `credentials-dist` to `~/.aws/credentials` and provide the AWS keys.
1. Run like `php -f ddns.php` or cron like `*/10 * * * * /usr/bin/php -f /path/to/ddns.php`
