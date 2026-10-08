# Disk Identificator for Unraid

Light up the identification (locate) LED of a drive bay from the Unraid web UI.
It works with LSI/Avago/Broadcom HBAs through Broadcom's utilities:
`sas3ircu` for SAS3 cards (SAS3008, SAS3216, SAS3224 and similar) and `sas2ircu` for SAS2 cards (SAS2008, SAS2308 and similar).

- **Dashboard**: an **ID** column at the end of the Array, Pool and Unassigned tables.
- **Main**: an **ID** column between *Device* and *Identification*.
- The button uses the theme's dashboard button colours when the LED is off. When the LED is on, the button pulses in the LED colour you set for that enclosure.
- **Settings > User Utilities > Disk Identificator**:
  - **LED colour** for each controller and enclosure (red, green, blue, amber, white or purple), so the button matches the real LED.
  - **Slot to LED mapping**: if the backplane LED cables are not wired to the matching slots, pick which LED sits on each bay. Use **Test** to check it.
  - **Location**: an optional label for each bay (a number or code printed on the case), shown left of the ID button.
    Empty by default. **Copy slot numbers** fills the fields with the slot numbers.
  - **Rescan** controllers, and **Turn all LEDs off**.

## Install

In Unraid, go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/vanarebane/unraid-disk-identificator/main/disk.identificator.plg
```

The plugin package installs both utilities to `/usr/local/bin`, so nothing is downloaded from third-party sites:

| File | Version | Source | MD5 |
|---|---|---|---|
| `sas3ircu` | P16 (17.00.00.00), Linux x64 | Broadcom [`SAS3IRCU_P16.zip`](https://docs.broadcom.com/docs-and-downloads/host-bus-adapters/host-bus-adapters-common-files/sas_sata_12g_p16_point_release/SAS3IRCU_P16.zip) | `12b7be81460097f58477d907e6124e50` |
| `sas2ircu` | P20 (20.00.00.00), Linux x86 | Broadcom [`SAS2IRCU_P20.zip`](https://docs.broadcom.com/docs-and-downloads/host-bus-adapters/host-bus-adapters-common-files/sas_sata_6g_p20/SAS2IRCU_P20.zip) | `0e9e4a67e11ead35112b7eaaf0c63885` |

## How it works

1. `sas3ircu list` / `sas2ircu list` and `<tool> <n> display` give every drive's controller, enclosure, slot, SAS address and serial number.
   The result is cached in `/tmp/disk.identificator` for 10 minutes. **Rescan** refreshes it.
2. Each Unraid disk (`/var/local/emhttp/disks.ini`, `devs.ini`) is matched to a slot by `/sys/block/sdX/device/sas_address`.
   This works for SAS drives and for SATA drives behind the HBA. If that fails, the plugin falls back to the serial number and then the WWN.
3. A click runs `<tool> <ctrl> locate <encl>:<slot> ON|OFF` on the mapped LED.
   Slots are identified as `sas3-0:1:3` (tool, controller, enclosure, slot), because controller numbers are per tool.
   The utilities cannot read the LED state back, so the plugin remembers which LEDs it turned on. That state is lost on reboot.

Settings are stored in `/boot/config/plugins/disk.identificator/settings.json`.

## Limitations

- SAS2 support uses the same output parser as SAS3. It is untested on real SAS2 hardware so far.
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
source/usr/local/bin/sas3ircu, sas2ircu   bundled Broadcom utilities
tests/parse_test.php              parser tests using real sas3ircu output (php tests/parse_test.php)
```

To release, run `./build.sh [yyyy.mm.dd]`. It builds `archive/disk.identificator-<version>-x86_64-1.txz` and writes
the version and MD5 into `disk.identificator.plg`. Add a `<CHANGES>` entry, then commit the `.plg` and the archive together.
