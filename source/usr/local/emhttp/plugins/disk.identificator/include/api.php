<?php
/* Disk Identificator - AJAX endpoint.
 *
 * GET  action=map               disk name -> slot/LED info for the Dashboard and Main buttons
 * GET  action=scan[&refresh=1]  full controller/enclosure/slot inventory for the settings page
 * POST action=locate name=disk1 on=1|0         toggle the LED of an Unraid disk (mapping applied)
 * POST action=test   key=c:e:s  on=1|0         toggle an LED directly (no mapping), used for remap testing
 * POST action=alloff[&all=1]                    turn off every LED the plugin switched on (all=1: every occupied or mapped slot)
 * POST action=save   cfg=<json>                 store settings
 * POST requests are CSRF-checked by Unraid's local_prepend.php.
 */
require_once __DIR__.'/common.php';

header('Content-Type: application/json');

function reply(array $data): void {
  echo json_encode($data);
  exit;
}

/* Unraid disk name -> slot info, for every disk found on a SAS controller. */
function disk_map(array $inv, array $cfg): array {
  $states = di_states();
  $disks = [];
  foreach (di_unraid_disks() as $name => $d) {
    $loc = di_locate_device($d['device'], $d['id'], $inv);
    if (!$loc) continue;
    $target = di_target($loc['key'], $cfg);
    $disks[$name] = [
      'device' => $d['device'],
      'key'    => $loc['key'],
      'target' => $target,
      'color'  => di_color($target, $cfg),
      'on'     => isset($states[$target]),
      'label'  => di_label($loc['key'], $cfg),
    ];
  }
  return $disks;
}

$action = $_REQUEST['action'] ?? '';
$cfg = di_config();

switch ($action) {
case 'map':
  $inv = di_inventory();
  reply(['ok' => true, 'tool' => (bool)$inv['tools'], 'dashboard' => $cfg['dashboard'], 'main' => $cfg['main'], 'disks' => disk_map($inv, $cfg)]);

case 'scan':
  $inv = di_inventory(!empty($_GET['refresh']));
  $names = [];
  foreach (disk_map($inv, $cfg) as $name => $d) $names[$d['key']] = $name;
  reply(['ok' => true, 'inventory' => $inv, 'names' => $names, 'states' => di_states(), 'config' => $cfg, 'colors' => DI_COLORS, 'timers' => DI_TIMERS]);

case 'locate':
  $name = (string)($_POST['name'] ?? '');
  $map = disk_map(di_inventory(), $cfg);
  if (!isset($map[$name])) reply(['ok' => false, 'error' => "Disk '$name' is not on a supported controller"]);
  $res = di_set_led($map[$name]['target'], !empty($_POST['on']), $cfg['timer']);
  $res['color'] = $map[$name]['color'];
  reply($res);

case 'test':
  reply(di_set_led((string)($_POST['key'] ?? ''), !empty($_POST['on']), $cfg['timer']));

case 'alloff':
  // all=1 also switches off LEDs turned on outside the plugin (e.g. from the console).
  $targets = array_keys(di_states());
  if (!empty($_POST['all'])) {
    foreach (di_inventory()['controllers'] as $c) foreach ($c['devices'] as $d) $targets[] = $d['key'];
    foreach ($cfg['map'] as $target) $targets[] = $target;
  }
  $tracked = di_states();
  $failed = [];
  foreach (array_unique($targets) as $target) {
    // Empty bays may refuse LOCATE; only report failures for LEDs we know are on.
    if (!di_set_led($target, false)['ok'] && isset($tracked[$target])) $failed[] = $target;
  }
  reply(['ok' => !$failed, 'error' => $failed ? 'Failed: '.implode(', ', $failed) : '']);

case 'save':
  $in = json_decode((string)($_POST['cfg'] ?? ''), true);
  if (!is_array($in)) reply(['ok' => false, 'error' => 'Invalid settings']);
  reply(['ok' => true, 'config' => di_save_config($in)]);

default:
  http_response_code(400);
  reply(['ok' => false, 'error' => 'Unknown action']);
}
