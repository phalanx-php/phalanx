#!/bin/bash
set -euo pipefail

# Build libphp.a with Swoole via SPC for use with ripht-php-sapi.
#
# NOTE: SPC 2.6 ships with "swoole" (not "openswoole") in its built-in
# registry. For the purposes of this POC, swoole proves the same thing:
# coroutines, channels, WaitGroup, and HTTP server all share the same
# C codebase. OpenSwoole support requires a custom SPC registry (the
# DoryBin module handles this in production builds).
#
# Prerequisites:
#   - SPC installed in PATH or provided through $SPC
#   - Build tools: autoconf, make, cmake, pkg-config, bison
#
# Output:
#   ~/.ripht/php/lib/libphp.a
#   ~/.ripht/php/include/php/...
#
# This only needs to run once per PHP/Swoole version change.

SPC="${SPC_PATH:-$(command -v spc 2>/dev/null || echo "$HOME/Code/Php/StaticPhp/spc")}"

if [ ! -x "$SPC" ]; then
    echo "SPC not found at: $SPC"
    echo "Set SPC_PATH or install static-php-cli"
    exit 1
fi

EXTENSIONS="swoole,openssl,curl,mbstring,pcntl,sockets,posix,filter,tokenizer,ctype,iconv"
PHP_VERSION="8.4"
BUILD_DIR="${BUILD_DIR:-/tmp/ripht-dory-build}"
OPENSSL_URL="https://github.com/openssl/openssl/releases/download/openssl-3.4.1/openssl-3.4.1.tar.gz"

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

echo "=== Phase 1: Download sources ==="
echo "Extensions: $EXTENSIONS"
echo "PHP: $PHP_VERSION"
echo "Build dir: $BUILD_DIR"
echo ""
"$SPC" download --for-extensions="$EXTENSIONS" --with-php="$PHP_VERSION" \
    --custom-url="openssl:$OPENSSL_URL"

echo ""
echo "=== Phase 2: Build embed SAPI ==="
"$SPC" build "$EXTENSIONS" \
    --build-embed \
    --with-config-file-path="$HOME/.config/dory" \
    --with-config-file-scan-dir="$HOME/.config/dory/conf.d"

echo ""
echo "=== Phase 3: Install to ~/.ripht/php ==="
RIPHT_PREFIX="$HOME/.ripht/php"
mkdir -p "$RIPHT_PREFIX/lib" "$RIPHT_PREFIX/include"

if [ ! -f "$BUILD_DIR/buildroot/lib/libphp.a" ]; then
    echo "ERROR: libphp.a not found at $BUILD_DIR/buildroot/lib/libphp.a"
    echo "Check the build output above for errors."
    exit 1
fi

cp "$BUILD_DIR/buildroot/lib/libphp.a" "$RIPHT_PREFIX/lib/libphp.a"

# Copy headers -- handle both possible layouts
if [ -d "$BUILD_DIR/buildroot/include/php" ]; then
    rm -rf "$RIPHT_PREFIX/include/php"
    cp -r "$BUILD_DIR/buildroot/include/php" "$RIPHT_PREFIX/include/php"
else
    echo "WARNING: PHP headers not found at expected location"
    echo "Searching for php headers..."
    find "$BUILD_DIR/buildroot/include" -name "php.h" -type f 2>/dev/null
fi

# Copy dependency static libraries that ripht's build.rs links conditionally
for lib in libz libssl libcrypto libcurl libxml2 libbz2 libsqlite3 libonig libnghttp2 libcares libbrotlidec libbrotlienc libbrotlicommon; do
    src="$BUILD_DIR/buildroot/lib/${lib}.a"
    if [ -f "$src" ]; then
        cp "$src" "$RIPHT_PREFIX/lib/"
        echo "  copied $lib"
    fi
done

echo ""
echo "=== Done ==="
echo "libphp.a: $RIPHT_PREFIX/lib/libphp.a"
ls -lh "$RIPHT_PREFIX/lib/libphp.a"
echo ""
echo "All libs:"
ls -lh "$RIPHT_PREFIX/lib/"*.a 2>/dev/null
echo ""
echo "Next: cd $PWD && cargo build"
