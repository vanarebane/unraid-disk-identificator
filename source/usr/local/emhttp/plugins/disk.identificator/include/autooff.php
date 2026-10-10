<?php
/* Disk Identificator - turns a locate LED off after the "Turn off indicator timer".
 * Started in the background by di_set_led(): php autooff.php <slot key> <token>
 * Does nothing if the LED was switched off, or switched on again, in the meantime.
 */
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(1);
require_once __DIR__.'/common.php';

[, $target, $token] = $argv;
if ((string)(di_states()[$target] ?? '') === $token) di_set_led($target, false);
