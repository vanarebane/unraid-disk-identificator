<?php
/* Disk Identificator - shared helpers.
 *
 * Talks to LSI/Avago/Broadcom HBAs through sas3ircu (SAS3) and sas2ircu (SAS2),
 * maps Unraid disks to controller:enclosure:slot locations and drives the bay locate LEDs.
 *
 * Slot keys look like "sas3-0:1:3" = tool sas3, controller 0, enclosure 1, slot 3.
 * Controller numbers are per tool, so the tool is part of the key.
 */

const DI_PLUGIN    = 'disk.identificator';
const DI_CFG_DIR   = '/boot/config/plugins/disk.identificator';
const DI_CFG_FILE  = DI_CFG_DIR.'/settings.json';
const DI_RUN_DIR   = '/tmp/disk.identificator';
const DI_INV_FILE  = DI_RUN_DIR.'/inventory.json';
const DI_STATE_FILE= DI_RUN_DIR.'/leds.json';
const DI_INV_TTL   = 600; // seconds; bays change rarely, settings page can force a rescan
const DI_LABEL_MAX = 16;
const DI_TIMERS    = [0, 10, 20, 30, 300, 600]; // auto-off delays in seconds, 0 = never
const DI_COLORS    = ['red', 'green', 'blue', 'amber', 'white', 'purple'];
const DI_TOOLS     = ['sas3' => '/usr/local/bin/sas3ircu', 'sas2' => '/usr/local/bin/sas2ircu']; // shipped in the plugin package

/* Installed tools: ['sas3' => '/usr/local/bin/sas3ircu', ...] */
function di_tools(): array {
  return array_filter(DI_TOOLS, fn($path) => is_file($path) && is_executable($path));
}

/* Run sas3ircu/sas2ircu with the given arguments. Returns the combined output. */
function di_run(string $tool, array $args, ?int &$rc = null): string {
  $tool = di_tools()[$tool] ?? null;
  if (!$tool) { $rc = 127; return 'utility not found'; }
  $cmd = escapeshellarg($tool).' '.implode(' ', array_map('escapeshellarg', array_map('strval', $args))).' 2>&1';
  $out = [];
  exec($cmd, $out, $rc);
  return implode("\n", $out);
}

function di_ok(string $out, int $rc): bool {
  return $rc === 0 && stripos($out, 'Completed Successfully') !== false;
}

/* ---------- parsing ---------- */

/* `sas3ircu list` -> [['index'=>0,'type'=>'SAS3008','pci'=>'00h:01h:00h:00h'], ...] */
function di_parse_list(string $txt): array {
  $ctrls = [];
  foreach (preg_split('/\r?\n/', $txt) as $line) {
    if (preg_match('/^\s*(\d+)\s+(\S+)\s+[0-9a-f]+h\s+[0-9a-f]+h\s+(\S+)/i', $line, $m)) {
      $ctrls[] = ['index' => (int)$m[1], 'type' => $m[2], 'pci' => $m[3]];
    }
  }
  return $ctrls;
}

/* `sas3ircu N display` -> ['info'=>[...], 'devices'=>[...], 'enclosures'=>[...]] */
function di_parse_display(string $txt): array {
  $res = ['info' => [], 'devices' => [], 'enclosures' => []];
  $section = '';
  $dev = null;
  $flush = function() use (&$dev, &$res) {
    if ($dev !== null && isset($dev['Enclosure #'], $dev['Slot #']) && stripos($dev['_type'], 'enclosure') === false) {
      $res['devices'][] = $dev;
    }
    $dev = null;
  };
  foreach (preg_split('/\r?\n/', $txt) as $line) {
    $t = trim($line);
    if ($t === '' || preg_match('/^-{5,}$/', $t) || preg_match('/^SAS\dIRCU:/i', $t)) continue;
    switch ($t) {
      case 'Controller information':      $flush(); $section = 'info'; continue 2;
      case 'IR Volume information':       $flush(); $section = 'ir';   continue 2;
      case 'Physical device information': $flush(); $section = 'phys'; continue 2;
      case 'Enclosure information':       $flush(); $section = 'encl'; continue 2;
    }
    if ($section === 'phys' && stripos($t, 'Device is a') === 0) {
      $flush();
      $dev = ['_type' => trim(substr($t, 11))];
      continue;
    }
    if (!preg_match('/^(.+?)\s*:\s*(.*)$/', $t, $m)) continue;
    [$key, $val] = [trim($m[1]), trim($m[2])];
    switch ($section) {
      case 'info': $res['info'][$key] = $val; break;
      case 'phys': if ($dev !== null) $dev[$key] = $val; break;
      case 'encl':
        if ($key === 'Enclosure#') $res['enclosures'][] = ['Enclosure#' => $val];
        elseif ($res['enclosures']) $res['enclosures'][count($res['enclosures'])-1][$key] = $val;
        break;
    }
  }
  $flush();
  return $res;
}

/* "5000039-9-683a-73ce" / "0x50000399683a73ce" -> "50000399683a73ce" */
function di_norm_sas(?string $addr): string {
  $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', preg_replace('/^0x/i', '', trim((string)$addr))));
  return ltrim($hex, '0') === '' ? '' : str_pad($hex, 16, '0', STR_PAD_LEFT);
}

function di_key(string $ctrl, int $encl, int $slot): string { return "$ctrl:$encl:$slot"; }

/* "sas3-0:1:3" -> ['sas3', 0, 1, 3] */
function di_parse_key(string $key): ?array {
  if (!preg_match('/^(sas[23])-(\d{1,3}):(\d{1,5}):(\d{1,5})$/', $key, $m)) return null;
  return [$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
}

/* ---------- inventory (controllers, enclosures, drives) ---------- */

function di_inventory(bool $refresh = false): array {
  if (!$refresh && is_file(DI_INV_FILE) && time() - filemtime(DI_INV_FILE) < DI_INV_TTL) {
    $inv = json_decode((string)file_get_contents(DI_INV_FILE), true);
    if (is_array($inv)) return $inv;
  }
  $inv = ['time' => time(), 'tools' => di_tools(), 'controllers' => [], 'error' => ''];
  if (!$inv['tools']) {
    $inv['error'] = 'sas3ircu / sas2ircu is not installed';
    return $inv;
  }
  foreach (array_keys($inv['tools']) as $tool) foreach (di_parse_list(di_run($tool, ['list'])) as $ctrl) {
    $ctrl['tool'] = $tool;
    $ctrl['id'] = "$tool-$ctrl[index]";
    $disp = di_parse_display(di_run($tool, [$ctrl['index'], 'display']));
    $ctrl['firmware'] = $disp['info']['Firmware version'] ?? '';
    $ctrl['enclosures'] = [];
    foreach ($disp['enclosures'] as $e) {
      $start = (int)($e['StartSlot'] ?? 0);
      $ctrl['enclosures'][(int)$e['Enclosure#']] = [
        'encl'  => (int)$e['Enclosure#'],
        'id'    => $e['Logical ID'] ?? '',
        'start' => $start,
        'slots' => (int)($e['Numslots'] ?? 0),
      ];
    }
    $ctrl['devices'] = [];
    foreach ($disp['devices'] as $d) {
      $encl = (int)$d['Enclosure #'];
      $slot = (int)$d['Slot #'];
      $ctrl['devices'][] = [
        'key'    => di_key($ctrl['id'], $encl, $slot),
        'encl'   => $encl,
        'slot'   => $slot,
        'sas'    => di_norm_sas($d['SAS Address'] ?? ''),
        'guid'   => strtolower(trim($d['GUID'] ?? '')),
        'serial' => trim($d['Serial No'] ?? ''),
        'vpd'    => trim($d['Unit Serial No(VPD)'] ?? ''),
        'model'  => trim(($d['Manufacturer'] ?? '').' '.($d['Model Number'] ?? '')),
        'type'   => $d['Drive Type'] ?? ($d['Protocol'] ?? ''),
      ];
      // Direct-attach setups may report drives in an enclosure that has no enclosure record.
      if (!isset($ctrl['enclosures'][$encl])) $ctrl['enclosures'][$encl] = ['encl' => $encl, 'id' => '', 'start' => 0, 'slots' => 0];
      $e = &$ctrl['enclosures'][$encl];
      $e['slots'] = max($e['slots'], $slot - $e['start'] + 1);
      unset($e);
    }
    ksort($ctrl['enclosures']);
    $ctrl['enclosures'] = array_values($ctrl['enclosures']);
    $inv['controllers'][] = $ctrl;
  }
  if (!$inv['controllers']) $inv['error'] = 'No supported SAS controllers found';
  @mkdir(DI_RUN_DIR, 0755, true);
  file_put_contents(DI_INV_FILE, json_encode($inv), LOCK_EX);
  return $inv;
}

/* ---------- Unraid disks ---------- */

/* Unraid devices: name => ['name','device','id'] (array/pool disks plus unassigned devices). */
function di_unraid_disks(): array {
  $list = [];
  foreach (['/var/local/emhttp/disks.ini', '/var/local/emhttp/devs.ini'] as $ini) {
    if (!is_file($ini)) continue;
    foreach ((array)@parse_ini_file($ini, true) as $section => $d) {
      $dev = $d['device'] ?? '';
      if (!preg_match('/^[a-z0-9]+$/', $dev)) continue;
      $name = $d['name'] ?? $dev;
      if ($name === '' || str_ends_with($ini, 'devs.ini')) $name = $dev;
      $list[$name] = ['name' => $name, 'device' => $dev, 'id' => $d['id'] ?? (string)$section];
    }
  }
  return $list;
}

function di_sysfs(string $dev, string $attr): string {
  $file = "/sys/block/$dev/device/$attr";
  return is_readable($file) ? trim((string)@file_get_contents($file)) : '';
}

/* Find the controller:enclosure:slot of a block device (sdX). */
function di_locate_device(string $dev, string $id, array $inv): ?array {
  $sas  = di_norm_sas(di_sysfs($dev, 'sas_address'));
  $wwid = strtolower(di_sysfs($dev, 'wwid'));
  foreach ($inv['controllers'] as $ctrl) foreach ($ctrl['devices'] as $d) {
    if ($sas !== '' && $sas === $d['sas']) return $d;
  }
  // Fallbacks for drivers that do not expose sas_address: serial number, then WWN.
  foreach ($inv['controllers'] as $ctrl) foreach ($ctrl['devices'] as $d) {
    foreach ([$d['serial'], $d['vpd']] as $serial) {
      if (strlen($serial) >= 6 && $id !== '' && (str_ends_with($id, "_$serial") || str_ends_with($id, $serial))) return $d;
    }
    if ($d['guid'] !== '' && $wwid !== '' && str_contains($wwid, $d['guid'])) return $d;
  }
  return null;
}

/* ---------- settings ---------- */

function di_defaults(): array {
  return ['dashboard' => true, 'main' => true, 'timer' => 0, 'map' => [], 'colors' => [], 'labels' => []];
}

function di_config(): array {
  $cfg = is_file(DI_CFG_FILE) ? json_decode((string)file_get_contents(DI_CFG_FILE), true) : null;
  $cfg = array_merge(di_defaults(), is_array($cfg) ? $cfg : []);
  $cfg['map']    = is_array($cfg['map']) ? $cfg['map'] : [];
  $cfg['colors'] = is_array($cfg['colors']) ? $cfg['colors'] : [];
  $cfg['labels'] = is_array($cfg['labels']) ? $cfg['labels'] : [];
  $cfg['timer']  = in_array((int)$cfg['timer'], DI_TIMERS, true) ? (int)$cfg['timer'] : 0;
  return $cfg;
}

function di_save_config(array $in): array {
  $cfg = di_defaults();
  $cfg['dashboard'] = !empty($in['dashboard']);
  $cfg['main']      = !empty($in['main']);
  $cfg['timer']     = in_array((int)($in['timer'] ?? 0), DI_TIMERS, true) ? (int)$in['timer'] : 0;
  foreach ((array)($in['map'] ?? []) as $from => $to) {
    if (di_parse_key((string)$from) && di_parse_key((string)$to) && $from !== $to) $cfg['map'][$from] = $to;
  }
  foreach ((array)($in['colors'] ?? []) as $encl => $color) {
    if (preg_match('/^sas[23]-\d{1,3}:\d{1,5}$/', (string)$encl) && in_array($color, DI_COLORS, true)) $cfg['colors'][$encl] = $color;
  }
  // Location label per slot (e.g. "A3" or "12"), shown next to the ID button.
  foreach ((array)($in['labels'] ?? []) as $key => $label) {
    $label = trim((string)preg_replace('/[[:cntrl:]]/u', '', (string)$label));
    if (di_parse_key((string)$key) && preg_match('/^.{1,'.DI_LABEL_MAX.'}/u', $label, $m)) $cfg['labels'][$key] = $m[0];
  }
  @mkdir(DI_CFG_DIR, 0755, true);
  file_put_contents(DI_CFG_FILE, json_encode($cfg, JSON_PRETTY_PRINT), LOCK_EX);
  return $cfg;
}

/* Logical slot -> slot whose LED is physically on that bay. */
function di_target(string $key, array $cfg): string {
  return $cfg['map'][$key] ?? $key;
}

function di_label(string $key, array $cfg): string {
  return (string)($cfg['labels'][$key] ?? '');
}

function di_color(string $target, array $cfg): string {
  $k = di_parse_key($target);
  return $k ? ($cfg['colors']["$k[0]-$k[1]:$k[2]"] ?? 'red') : 'red';
}

/* ---------- LED state ---------- */
/* sas3ircu cannot read back the locate LED state, so the plugin remembers what it switched on. */

function di_states(): array {
  $s = is_file(DI_STATE_FILE) ? json_decode((string)file_get_contents(DI_STATE_FILE), true) : [];
  return is_array($s) ? $s : [];
}

/* Switch a locate LED. With $timer > 0 a background job turns it off again after that many seconds. */
function di_set_led(string $target, bool $on, int $timer = 0): array {
  $k = di_parse_key($target);
  if (!$k) return ['ok' => false, 'error' => 'Invalid slot'];
  $out = di_run($k[0], [$k[1], 'locate', "$k[2]:$k[3]", $on ? 'ON' : 'OFF'], $rc);
  $ok = di_ok($out, $rc);
  if ($ok) {
    @mkdir(DI_RUN_DIR, 0755, true);
    $fp = fopen(DI_STATE_FILE, 'c+');
    flock($fp, LOCK_EX);
    $states = json_decode((string)stream_get_contents($fp), true) ?: [];
    // Each switch-on gets a unique token, so a pending auto-off only fires for the switch-on that scheduled it.
    $token = sprintf('%.6f', microtime(true));
    if ($on) $states[$target] = $token; else unset($states[$target]);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($states));
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($on && $timer > 0) {
      $job = 'sleep '.(int)$timer.'; /usr/bin/php '.escapeshellarg(__DIR__.'/autooff.php').' '.escapeshellarg($target).' '.escapeshellarg($token);
      exec('nohup /bin/sh -c '.escapeshellarg($job).' >/dev/null 2>&1 &');
    }
  }
  return ['ok' => $ok, 'target' => $target, 'on' => $on, 'timer' => $on && $ok ? $timer : 0, 'error' => $ok ? '' : $out];
}
