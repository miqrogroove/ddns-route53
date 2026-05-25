<?php
/**
 * DDNS for Route 53 in PHP
 *
 * @copyright 2023-2026 by Robert Chapin
 * @license GPL
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

require './vendor/autoload.php';
require './config.php';

use Aws\Credentials\CredentialProvider;
use Aws\Route53\Route53Client;
//use RuntimeException;


/* Current Address Retrieval */

$curl = curl_init(IP_ADDR_FINDER);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_TIMEOUT => 5,
]);

$count = 1;
$limit = 2;
$raw_result = curl_exec($curl);
while (false === $raw_result && $count <= $limit) {
    // Transient errors might occur.
    if ($count >= $limit) {
        // This should be rare.
        $errorno = curl_errno($curl);
        $errormsg = curl_error($curl);
        throw new RuntimeException("Unable to contact IP address finder after $limit attempts.  cURL error $errorno: $errormsg");
    }

    sleep(2);
    $count++;
    $raw_result = curl_exec($curl);
}

// Grab the first dotted decimal notation on the page.
$ipv4_regex = '/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/';
if (preg_match($ipv4_regex, $raw_result, $matches) !== 1) {
    throw new RuntimeException('IP address finder returned an unexpected result.');
};

$newip4 = $matches[0];
$changed4 = true;

// Check against last known address
$lastknown = false;
if (is_readable(LOCAL_FILE)) {
    $lastknown = file_get_contents(LOCAL_FILE);
}

if ($lastknown !== false) {
    $oldip = trim($lastknown);
    if ($newip4 === $oldip) {
        $changed4 = false;
    }
}

// Grab the interface list to find this machine's public IPv6 address.
$changed6 = false;
if (defined('ENABLE_IP_V6') && ENABLE_IP_V6) {
    $ifs = net_get_interfaces();
    if (! isset($ifs[INTERFACE_NAME]['unicast'])) {
        throw new RuntimeException('Unable to find your interface named "' . INTERFACE_NAME . '"');
    };
    foreach ($ifs[INTERFACE_NAME]['unicast'] as $subnet) {
        // Skip any IPv4, link-local, or loopback results.
        if (! isset($subnet['family']) || ! isset($subnet['address'])) continue;
        if ($subnet['family'] !== AF_INET6) continue;
        $prefix = substr($subnet['address'], 0, 4);
        if ($prefix === '') continue;
        if ($prefix === '::1') continue;
        if ($prefix === 'fe80') continue;

        $newip6 = $subnet['address'];
        $changed6 = true;
        break;
    }
    if (! $changed6) {
        throw new RuntimeException('Unable to find your IPv6 address.');
    };

    // Check against last known address
    $lastknown = false;
    if (is_readable(LOCAL_FILE_V6)) {
        $lastknown = file_get_contents(LOCAL_FILE_V6);
    }

    if ($lastknown !== false) {
        $oldip = trim($lastknown);
        if ($newip6 === $oldip) {
            $changed6 = false;
        }
    }
}


/* Amazon Stuff */

// Use the default credential provider
$provider = CredentialProvider::defaultProvider();

// Pass the provider to the client
$client = new Route53Client([
    'region'      => 'us-east-2',  // Choices from https://docs.aws.amazon.com/general/latest/gr/rande.html
    'version'     => '2013-04-01', // Obtained by commeting this line and reading the error output.
    'credentials' => $provider,
]);

// Generate changes
$changes = [];
if ($changed4) {
    $changes[] = [
        'Action' => 'UPSERT', // REQUIRED
        'ResourceRecordSet' => [ // REQUIRED
            'Name' => RECORD_NAME, // REQUIRED
            'ResourceRecords' => [
                [
                    'Value' => $newip4, // REQUIRED
                ],
                // ...
            ],
            'TTL' => '600', // Required unless using AliasTarget or TrafficPolicyInstanceId.
            'Type' => 'A', // REQUIRED
        ],
    ];
}
if ($changed6) {
    $changes[] = [
        'Action' => 'UPSERT', // REQUIRED
        'ResourceRecordSet' => [ // REQUIRED
            'Name' => RECORD_NAME, // REQUIRED
            'ResourceRecords' => [
                [
                    'Value' => $newip6, // REQUIRED
                ],
                // ...
            ],
            'TTL' => '600', // Required unless using AliasTarget or TrafficPolicyInstanceId.
            'Type' => 'AAAA', // REQUIRED
        ],
    ];
}
if (empty($changes)) {
    return;
}

// Batch and send the changes.
$result = $client->changeResourceRecordSets([
    'ChangeBatch' => [ // REQUIRED
        'Changes' => $changes, // REQUIRED
        'Comment' => 'DDNS Push',
    ],
    'HostedZoneId' => HOSTED_ZONE_ID, // REQUIRED
]);


/* Remeber Current Address */

if ($changed4) {
    file_put_contents(LOCAL_FILE, $newip4);
}
if ($changed6) {
    file_put_contents(LOCAL_FILE_V6, $newip6);    
}
