#!/usr/bin/env bash
set -euo pipefail

# Dory Static Runtime Builder
# Orchestrates SPC (static-php-cli) to compile libphp.a based on dory.toml

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$DIR")"

# We run SPC from the monorepo root. This is critical because it allows SPC
# to reuse the existing `downloads/`, `source/`, and `buildroot/` directories
# from the overarching Phalanx project, skipping hours of recompilation.
WORKSPACE_ROOT="$(cd "$ROOT_DIR/../.." && pwd)"
DORY_STATIC_PHP_PREFIX="${DORY_STATIC_PHP_PREFIX:-$HOME/.ripht/php}"

echo "=== Dory Static Runtime Builder ==="
echo "Reading configuration from $ROOT_DIR/dory.toml"

EVAL_OUT=$(php "$DIR/parse-toml.php" "$ROOT_DIR/dory.toml")
eval "$EVAL_OUT"

echo "Target PHP: $PHP_VERSION"
echo "Extensions: $EXTENSIONS"

cd "$WORKSPACE_ROOT"

# Use global spc if available, otherwise fallback to local
if command -v spc >/dev/null 2>&1; then
    SPC_BIN="spc"
elif [ -f "spc" ]; then
    SPC_BIN="./spc"
else
    echo "Downloading standalone spc to workspace root..."
    curl -fsSL -o spc https://dl.static-php.cli.crazywhalecc.com/spc-macos-aarch64
    chmod +x spc
    SPC_BIN="./spc"
fi

echo "=== Phase 1: Download Sources ==="
# SPC will skip downloading if the archives already exist in downloads/
$SPC_BIN download "php-src@$PHP_VERSION" --with-php="$PHP_VERSION" --for-extensions="$EXTENSIONS"

echo "=== Phase 2: Build libphp.a ==="
# SPC will skip compiling C extensions that haven't changed
$SPC_BIN build php-src --build-embed --with-extensions="$EXTENSIONS"

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