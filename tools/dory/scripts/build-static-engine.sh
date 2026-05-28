#!/usr/bin/env bash
set -e

# Dory Static Engine Builder
# Orchestrates SPC (static-php-cli) to compile libphp.a based on dory.toml

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$DIR")"
BUILD_DIR="${BUILD_DIR:-/tmp/dory-spc-build}"
RIPHT_PREFIX="$HOME/.ripht/php"

echo "=== Dory Static Engine Builder ==="
echo "Reading configuration from $ROOT_DIR/dory.toml"

# Extract PHP version and extensions using robust PHP script
EVAL_OUT=$(php "$DIR/parse-toml.php" "$ROOT_DIR/dory.toml")
if [ $? -ne 0 ]; then
    echo "Failed to parse dory.toml"
    exit 1
fi
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

echo "=== Phase 3: Install to ~/.ripht/php ==="
mkdir -p "$RIPHT_PREFIX/lib" "$RIPHT_PREFIX/include"

cp "buildroot/lib/libphp.a" "$RIPHT_PREFIX/lib/libphp.a"

rm -rf "$RIPHT_PREFIX/include/php"
cp -r "buildroot/include/php" "$RIPHT_PREFIX/include/php"

# Copy dependency static libraries (e.g., cares, curl, ssl, crypto)
for src in buildroot/lib/*.a; do
    if [ -f "$src" ]; then
        cp "$src" "$RIPHT_PREFIX/lib/"
    fi
done

echo "=== Success ==="
echo "libphp.a installed to: $RIPHT_PREFIX/lib/libphp.a"
ls -lh "$RIPHT_PREFIX/lib/libphp.a"
