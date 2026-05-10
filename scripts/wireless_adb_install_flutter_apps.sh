#!/usr/bin/env bash
# Wireless ADB + install Mezban consumer & business Flutter apps.
#
# Wireless (Android 11+): Settings → Developer options → Wireless debugging
#   • "Pair device with pairing code" → IP:PAIR_PORT + 6-digit code → run: adb pair IP:PAIR_PORT
#   • Main screen shows "IP address & port" → DEBUG_PORT (often NOT the same as pair port!) → adb connect IP:DEBUG_PORT
#
# USB: plug phone → leave args empty or pass: skip
#
set -euo pipefail

DEBUG_HOST_PORT="${1:-}"

if adb devices | grep -qE '^\S+\s+device\s*$'; then
  echo "Device already connected:"
  adb devices -l
else
  if [[ -z "${DEBUG_HOST_PORT}" || "${DEBUG_HOST_PORT}" == "skip" ]]; then
    echo "No device in 'adb devices'. Either:"
    echo "  • USB: enable USB debugging and plug in, then run: $0 skip"
    echo "  • Wi‑Fi: after adb pair ... run: $0 192.168.100.172:YOUR_DEBUG_PORT"
    exit 1
  fi
  echo "Connecting ${DEBUG_HOST_PORT} ..."
  adb connect "${DEBUG_HOST_PORT}"
  adb devices -l
fi

MEZBAN="/Users/mac/Documents/mezban"
BUSINESS="/Users/mac/Documents/mezban_business"

for APP in "$MEZBAN" "$BUSINESS"; do
  echo ">>> flutter install: $(basename "$APP")"
  (cd "$APP" && flutter pub get && flutter install)
done

echo "Done."
