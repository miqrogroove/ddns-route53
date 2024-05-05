<?php
/**
 * DDNS for Route53 in PHP
 *
 * @copyright 2023-2024 by Robert Chapin
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


/* Current Address Retrieval */

$curl = curl_init( IP_ADDR_FINDER );
curl_setopt_array( $curl, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_TIMEOUT => 5,
] );

$count = 1;
$limit = 2;
$raw_result = curl_exec( $curl );
while ( false === $raw_result && $count <= $limit ) {
    // Transient errors might occur.
    if ( $count >= $limit ) {
        // This should be rare.
        $errorno = curl_errno( $curl );
        $errormsg = curl_error( $curl );
        trigger_error( "Unable to contact IP address finder after $limit attempts.  cURL error $errorno: $errormsg", E_USER_ERROR );
    }

    sleep( 2 );
    $count++;
    $raw_result = curl_exec( $curl );
}

// Grab the first dotted decimal notation on the page.
$ipv4_regex = '/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/';
if ( preg_match( $ipv4_regex, $raw_result, $matches ) !== 1 ) {
    trigger_error( 'IP address finder returned an unexpected result.', E_USER_ERROR );
};

$newip = $matches[0];

// Check against last known address
$lastknown = false;
if ( is_readable( LOCAL_FILE ) ) {
    $lastknown = file_get_contents( LOCAL_FILE );
}

if ( $lastknown !== false ) {
    $oldip = trim( $lastknown );
    if ( $newip === $oldip ) {
        // No update needed.
        return;
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

$result = $client->changeResourceRecordSets([
    'ChangeBatch' => [ // REQUIRED
        'Changes' => [ // REQUIRED
            [
                'Action' => 'UPSERT', // REQUIRED
                'ResourceRecordSet' => [ // REQUIRED
                    'Name' => RECORD_NAME, // REQUIRED
                    'ResourceRecords' => [
                        [
                            'Value' => $newip, // REQUIRED
                        ],
                        // ...
                    ],
                    'TTL' => '600', // Required unless using AliasTarget or TrafficPolicyInstanceId.
                    'Type' => 'A', // REQUIRED
                ],
            ],
            // ...
        ],
        'Comment' => 'DDNS Push',
    ],
    'HostedZoneId' => HOSTED_ZONE_ID, // REQUIRED
]);


/* Remeber Current Address */

file_put_contents( LOCAL_FILE, $newip );
