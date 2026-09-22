#!/usr/bin/env bash
set -euo pipefail
package_dir="$(cd "$(dirname "$0")" && pwd)"
output="${1:-/tmp/avyo-receiver.zip}"
case "$output" in /*) ;; *) output="$PWD/$output" ;; esac
stage="$(mktemp -d "${TMPDIR:-/tmp}/avyo-wordpress-package.XXXXXX")"
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/avyo-receiver"
cp "$package_dir/plugin/avyo-receiver.php" "$package_dir/plugin/receiver.php" "$package_dir/plugin/articles.php" "$stage/avyo-receiver/"
(cd "$stage" && zip -q "$output" avyo-receiver/avyo-receiver.php avyo-receiver/receiver.php avyo-receiver/articles.php)
printf '%s\n' "$output"
