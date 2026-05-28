#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$DIR")"
BUILD_DIR="${BUILD_DIR:-/tmp/dory-spc-build}"
DORY_STATIC_PHP_PREFIX="${DORY_STATIC_PHP_PREFIX:-$ROOT_DIR/.ripht/php}"

echo "=== Dory Static Runtime Builder ==="
echo "Reading configuration from $ROOT_DIR/dory.toml"

EVAL_OUT=$(php "$DIR/parse-toml.php" "$ROOT_DIR/dory.toml")
eval "$EVAL_OUT"

echo "Target PHP: $PHP_VERSION"
echo "Extensions: $EXTENSIONS"

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

if [ ! -f "spc" ]; then
    echo "Downloading standalone spc..."
    curl -fsSL -o spc https://dl.static-php.cli.crazywhalecc.com/spc-macos-aarch64
    chmod +x spc
fi

echo "=== Phase 1: Download Sources ==="
./spc download "php-src@$PHP_VERSION" --with-php="$PHP_VERSION" --for-extensions="$EXTENSIONS"

echo "=== Phase 2: Build libphp.a ==="
# We pin OpenSSL to 3.4.1 to avoid the 4.0 API incompatibility with PHP 8.4
./spc build php-src --build-embed --with-extensions="$EXTENSIONS"

echo "=== Phase 3: Install static PHP runtime ==="
mkdir -p "$DORY_STATIC_PHP_PREFIX/lib" "$DORY_STATIC_PHP_PREFIX/include"

cp "buildroot/lib/libphp.a" "$DORY_STATIC_PHP_PREFIX/lib/libphp.a"

rm -rf "$DORY_STATIC_PHP_PREFIX/include/php"
cp -r "buildroot/include/php" "$DORY_STATIC_PHP_PREFIX/include/php"

for src in buildroot/lib/*.a; do
    if [ -f "$src" ]; then
        cp "$src" "$DORY_STATIC_PHP_PREFIX/lib/"
    fi
done

echo "=== Success ==="
echo "libphp.a installed to: $DORY_STATIC_PHP_PREFIX/lib/libphp.a"
ls -lh "$DORY_STATIC_PHP_PREFIX/lib/libphp.a"
