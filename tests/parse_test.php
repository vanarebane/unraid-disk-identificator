<?php
/* Parser tests against real sas3ircu output. Run: php tests/parse_test.php */
require __DIR__.'/../source/usr/local/emhttp/plugins/disk.identificator/include/common.php';

$fail = 0;
function check(string $what, $got, $want): void {
  global $fail;
  if ($got === $want) { echo "ok   $what\n"; return; }
  $fail++;
  echo "FAIL $what\n     got:  ".var_export($got, true)."\n     want: ".var_export($want, true)."\n";
}

$list = di_parse_list(file_get_contents(__DIR__.'/fixtures/list.txt'));
check('list: one controller', count($list), 1);
check('list: controller 0 type', $list[0], ['index' => 0, 'type' => 'SAS3008', 'pci' => '00h:01h:00h:00h']);

$disp = di_parse_display(file_get_contents(__DIR__.'/fixtures/display.txt'));
check('display: firmware', $disp['info']['Firmware version'], '16.00.10.00');
check('display: 8 drives', count($disp['devices']), 8);
check('display: slot 3 serial', $disp['devices'][3]['Serial No'], '59E0A09LF1LF');
check('display: slot 7 type', $disp['devices'][7]['Drive Type'], 'SATA_HDD');
check('display: enclosure', $disp['enclosures'], [['Enclosure#' => '1', 'Logical ID' => '50030480:1a8bee02', 'Numslots' => '8', 'StartSlot' => '0']]);

check('sas: SAS drive', di_norm_sas('5000039-9-683a-73ce'), '50000399683a73ce');
check('sas: SATA behind HBA', di_norm_sas('4433221-1-0600-0000'), '4433221106000000');
check('sas: sysfs form', di_norm_sas("0x50000399683a73ce\n"), '50000399683a73ce');
check('sas: empty', di_norm_sas('0x0000000000000000'), '');

check('key: parse', di_parse_key('sas3-0:1:3'), ['sas3', 0, 1, 3]);
check('key: parse sas2', di_parse_key('sas2-1:2:7'), ['sas2', 1, 2, 7]);
check('key: reject old format', di_parse_key('0:1:3'), null);
check('key: reject injection', di_parse_key('sas3-0:1:3;rm'), null);

$cfg = ['map' => ['sas3-0:1:3' => 'sas3-0:1:5'], 'colors' => ['sas3-0:1' => 'blue']];
check('target: remapped', di_target('sas3-0:1:3', $cfg), 'sas3-0:1:5');
check('target: identity', di_target('sas3-0:1:4', $cfg), 'sas3-0:1:4');
check('color: per enclosure', di_color('sas3-0:1:5', $cfg), 'blue');
check('color: other tool same numbers', di_color('sas2-0:1:5', $cfg), 'red');
check('label: set', di_label('sas3-0:1:3', ['labels' => ['sas3-0:1:3' => 'A4']]), 'A4');
check('label: empty by default', di_label('sas3-0:1:3', di_defaults()), '');


echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
