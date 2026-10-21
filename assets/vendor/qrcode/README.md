# qrcode-generator (vendored)

`qrcode.js` is **qrcode-generator 1.4.4** by Kazuhiko Arase, MIT licensed.

- Upstream: https://github.com/kazuhikoarase/qrcode-generator
- Licence: MIT (see `LICENSE` in this folder)
- Used by: `application/views/attendance/board.php` — drawing the rotating
  site check-in code on the site screen.

It is vendored rather than loaded from a CDN for the same reason the Excel
writer and PDF generator in this repository are hand-written: the workshop
installation must keep working when the site has no outbound internet access.
Only `qrcode.js` is copied; the SJIS/UTF-8 byte-conversion variants and the
sample pages are not needed because the site code is pure ASCII.

The file is unmodified. Do not edit it here — replace it with a newer upstream
release and update the version in this note.
