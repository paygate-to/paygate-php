<?php

/*
 * Prints every supported coin with its minimum and current price.
 *
 *   php examples/list-coins.php [EUR]
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$fiat = strtoupper($argv[1] ?? 'USD');
$crypto = paygate()->crypto();

foreach ($crypto->coins() as $id => $coin) {
    printf("%-24s %-28s min %s\n", $id, $coin->getLabel(), $coin->getMinimum());
}

printf("\n1 BTC = %s %s\n", $crypto->price('btc', $fiat), $fiat);
