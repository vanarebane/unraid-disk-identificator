# Disk Identificator for Unraid

Light up the identification (locate) LED of a drive bay from the Unraid web UI.
It works with LSI/Avago/Broadcom SAS3 HBAs (SAS3008, SAS3216, SAS3224 and similar) through Broadcom's `sas3ircu` utility.

- **Dashboard**: an **ID** column at the end of the Array, Pool and Unassigned tables.
- **Main**: an **ID** column between *Device* and *Identification*.
- The button is grey when the LED is off. When the LED is on, the button pulses in the LED colour you set for that enclosure.
- **Settings > User Utilities > Disk Identificator**:
  - **LED colour** for each controller and enclosure (red, green, blue, amber, white or purple), so the button matches the real LED.
  - **Slot to LED mapping**: if the backplane LED cables are not wired to the matching slots, pick which LED sits on each bay. Use **Test** to check it.
  - **Rescan** controllers, and **Turn all LEDs off**.

## Install

In Unraid, go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/vanarebane/unraid-disk-identificator/main/disk.identificator.plg
```

The plugin downloads `sas3ircu` from `http://images.45drives.com/tools/sas3ircu`, stores it in
`/boot/config/plugins/disk.identificator/`, and copies it to `/usr/local/bin` on every boot.
If `/usr/local/bin/sas3ircu` is already there, the plugin leaves it alone.

## How it works

1. `sas3ircu list` and `sas3ircu <n> display` give every drive's controller, enclosure, slot, SAS address and serial number.
   The result is cached in `/tmp/disk.identificator` for 10 minutes. **Rescan** refreshes it.
2. Each Unraid disk (`/var/local/emhttp/disks.ini`, `devs.ini`) is matched to a slot by `/sys/block/sdX/device/sas_address`.
   This works for SAS drives and for SATA drives behind the HBA. If that fails, the plugin falls back to the serial number and then the WWN.
3. A click runs `sas3ircu <ctrl> locate <encl>:<slot> ON|OFF` on the mapped LED.
   `sas3ircu` cannot read the LED state back, so the plugin remembers which LEDs it turned on. That state is lost on reboot.

Settings are stored in `/boot/config/plugins/disk.identificator/settings.json`.

## Limitations

- Only SAS3 HBAs (`sas3ircu`). SAS2 cards use `sas2ircu`, which this plugin does not support yet.
- Some backplanes refuse `LOCATE` for an empty bay, so mapping a slot to the LED of an empty bay may not work.
- LEDs switched on from the console are not shown as on. **Turn all LEDs off** in the settings still turns them off.

## Development

```
source/usr/local/emhttp/plugins/disk.identificator/
  DiskIdentificator.page          settings page (Settings > User Utilities)
  DiskIdentificatorButtons.page   invisible "Buttons" page that loads the JS/CSS on Dashboard and Main
  include/common.php              sas3ircu parsing, disk matching, settings, LED state
  include/api.php                 AJAX endpoint
  js/diskid.js                    adds the ID column and keeps it in place across nchan table refreshes
  css/diskid.css
tests/parse_test.php              parser tests using real sas3ircu output (php tests/parse_test.php)
```

To release, run `./build.sh [yyyy.mm.dd]`. It builds `archive/disk.identificator-<version>-x86_64-1.txz` and writes
the version and MD5 into `disk.identificator.plg`. Add a `<CHANGES>` entry, then commit the `.plg` and the archive together.
