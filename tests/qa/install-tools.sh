#!/usr/bin/env bash
# Installs the pinned code-quality tools into .qa-tools/ by cloning their release tags.
# Used by CI and locally, so both run the exact same versions. No Composer needed.
#
#   tests/qa/install-tools.sh
#   .qa-tools/phpcs/bin/phpcs          (uses phpcs.xml.dist)
#   php .qa-tools/phpstan/phpstan.phar analyse --memory-limit=1G   (uses phpstan.neon.dist)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="$ROOT/.qa-tools"
mkdir -p "$DIR"

clone() { # repo tag folder
  if [ -d "$DIR/$3/.git" ] && [ "$(git -C "$DIR/$3" describe --tags --exact-match 2>/dev/null)" = "$2" ]; then
    return
  fi
  rm -rf "${DIR:?}/$3"
  git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$2" "https://github.com/$1.git" "$DIR/$3"
  echo "installed $1 $2"
}

clone PHPCSStandards/PHP_CodeSniffer            3.13.6 phpcs
clone WordPress/WordPress-Coding-Standards      3.4.1  wpcs
clone PHPCSStandards/PHPCSUtils                 1.2.3  phpcsutils
clone PHPCSStandards/PHPCSExtra                 1.5.1  phpcsextra
# PHPCompatibility 10 (alpha): 9.3.5 is from 2019 and misses PHP 7.4+/8.x syntax such as
# fn() =>, ?->, match and ??=. CI also lints every file with a real PHP 7.2.
clone PHPCompatibility/PHPCompatibility         10.0.0-alpha2 phpcompatibility
clone PHPCompatibility/PHPCompatibilityParagonie 2.0.0-alpha2 phpcompatibility-paragonie
clone PHPCompatibility/PHPCompatibilityWP       3.0.0-alpha2 phpcompatibility-wp
clone phpstan/phpstan                           2.3.1  phpstan
clone php-stubs/wordpress-stubs                 v6.9.4 wordpress-stubs
clone php-stubs/woocommerce-stubs               v11.2.0 woocommerce-stubs

php "$DIR/phpcs/bin/phpcs" --config-set installed_paths \
  "$DIR/wpcs,$DIR/phpcsutils,$DIR/phpcsextra,$DIR/phpcompatibility,$DIR/phpcompatibility-paragonie,$DIR/phpcompatibility-wp" >/dev/null
echo "tools ready in $DIR"
