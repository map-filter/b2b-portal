#!/bin/sh
set -e
mkdir -p "$HOME/.cache/b2b-go"
exec docker run --rm -u "$(id -u):$(id -g)" \
  -e GOCACHE=/cache/build -e GOPATH=/cache/gopath -e GOFLAGS=-modcacherw \
  -v "$(cd "$(dirname "$0")/.." && pwd):/repo" -v "$HOME/.cache/b2b-go:/cache" \
  -w /repo/exchange-go golang:1.23-alpine go "$@"
