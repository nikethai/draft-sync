#!/usr/bin/env sh
set -eu
# Cross-compile for Linux amd64 (typical Proxmox LXC).
# CGO_ENABLED=0 is safe: modernc.org/sqlite is pure Go — static binary, no libc deps.
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" \
  -o dist/draftsync-bridge ./
