#!/usr/bin/env bash
#
# pack.sh — собирает (упаковывает) плагин AneePay под конкретную CMS.
#
# Использование:
#   ./scripts/pack.sh <platform> [version]
#
#   platform  wc | oc | ps         (или "all" для всех трёх)
#   version   необязательно; по умолчанию берётся из ./VERSION
#
# Примеры:
#   ./scripts/pack.sh wc                       # → dist/wc/aneepay.zip
#   ./scripts/pack.sh oc 1.3.0                 # → dist/oc/aneepay-1.3.0.ocmod.zip
#   ./scripts/pack.sh ps                       # → dist/ps/ps_aneepay.zip
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLATFORM="${1:-}"
VERSION="${2:-$(tr -d '[:space:]' < VERSION 2>/dev/null || echo 'dev')}"
DIST="$ROOT/dist"

fail() { echo "ERROR: $*" >&2; exit 1; }
warn() { echo "WARN: $*" >&2; }

SRC_WC="plugins/woocommerce/aneepay"
SRC_OC="plugins/opencart/aneepay"
SRC_PS="plugins/prestashop/ps_aneepay"

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

php_lint() {
  local base="$1"
  command -v php >/dev/null 2>&1 || { warn "php не найден — линт пропущен"; return 0; }
  local found=0 errors=0 f
  while IFS= read -r -d '' f; do
    found=1
    if ! php -l "$f" >/dev/null 2>&1; then
      php -l "$f" || true
      errors=$((errors + 1))
    fi
  done < <(find "$base" -type f -name '*.php' -print0)
  [ "$found" = 1 ] || warn "нет .php файлов в $base"
  [ "$errors" -eq 0 ] || fail "php -l: $errors ошибок в $base"
}

check_platform() {
  local p="$1"
  case "$p" in
    wc)
      [ -f "$SRC_WC/aneepay.php" ] || fail "нет $SRC_WC/aneepay.php"
      grep -qE '^ \* Version:' "$SRC_WC/aneepay.php" || warn "нет WP-header 'Version:' в aneepay.php"
      ;;
    oc)
      [ -d "$SRC_OC/upload" ] || [ -d "$SRC_OC/admin" ] || fail "нет исходников расширения OC"
      [ -f "$SRC_OC/install.xml" ] \
        || warn "нет install.xml (для ocmod/загрузки расширения OC 3.x оба формата валидны)"
      ;;
    ps)
      local dir
      while IFS= read -r -d '' dir; do
        [ -f "$dir/index.php" ] || fail "нет index.php (требование PrestaShop): $dir"
      done < <(find "$SRC_PS" -type d -print0)
      [ -f "$SRC_PS/logo.png" ] || warn "нет logo.png в $SRC_PS"
      ;;
  esac
}

# Zip the *contents* of a staging directory (so the caller controls the root path).
zip_stage() {
  local stage="$1" out="$2"
  ( cd "$stage" && zip -qr "$out" . )
  rm -rf "$stage"
}

# ---------------------------------------------------------------------------
# per-platform assembly
# ---------------------------------------------------------------------------

build_wc() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/wc"; mkdir -p "$dest"
  mkdir -p "$stage/aneepay"
  cp -a "$SRC_WC/." "$stage/aneepay/"
  zip_stage "$stage" "$dest/aneepay.zip"
  echo "   -> $dest/aneepay.zip"
}

build_oc() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/oc"; mkdir -p "$dest"
  mkdir -p "$stage/upload/admin" "$stage/upload/catalog" "$stage/upload/system"
  cp -a "$SRC_OC/admin/." "$stage/upload/admin/"
  cp -a "$SRC_OC/catalog/." "$stage/upload/catalog/"
  cp -a "$SRC_OC/system/." "$stage/upload/system/"
  [ -f "$SRC_OC/install.xml" ] && cp "$SRC_OC/install.xml" "$stage/install.xml"
  zip_stage "$stage" "$dest/aneepay-${VERSION}.ocmod.zip"
  echo "   -> $dest/aneepay-${VERSION}.ocmod.zip"
}

build_ps() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/ps"; mkdir -p "$dest"
  mkdir -p "$stage/ps_aneepay"
  cp -a "$SRC_PS/." "$stage/ps_aneepay/"
  # config.xml is gitignored (**/config.xml): generate it from the canonical
  # VERSION so a fresh checkout always ships a matching module manifest. The
  # write happens after the copy so it wins over any stale local copy.
  write_ps_config "$stage/ps_aneepay/config.xml"
  zip_stage "$stage" "$dest/ps_aneepay.zip"
  echo "   -> $dest/ps_aneepay.zip"
}

# Generate the PrestaShop module manifest (config.xml) at the build version.
write_ps_config() {
  local out="$1"
  cat > "$out" <<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<module>
    <name>ps_aneepay</name>
    <displayName><![CDATA[AneePay Crypto Gateway]]></displayName>
    <version><![CDATA[${VERSION}]]></version>
    <description><![CDATA[Accept stablecoin payments (USDT, USDC, DAI) on Polygon (live) or Amoy (testnet). Non-custodial, 0.5% fee, no KYC.]]></description>
    <author><![CDATA[AneePay]]></author>
    <tab><![CDATA[payments_gateways]]></tab>
    <confirmUninstall><![CDATA[Are you sure you want to uninstall AneePay?]]></confirmUninstall>
    <is_configurable>1</is_configurable>
    <need_instance>0</need_instance>
</module>
EOF
}

# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------

[ -n "$PLATFORM" ] || fail "укажи платформу: wc | oc | ps | all"

platforms=("$PLATFORM")
[ "$PLATFORM" = "all" ] && platforms=(wc oc ps)

mkdir -p "$DIST"

for p in "${platforms[@]}"; do
  case "$p" in
    wc) src="$SRC_WC" ;;
    oc) src="$SRC_OC" ;;
    ps) src="$SRC_PS" ;;
    *) fail "неизвестная платформа: $p" ;;
  esac

  [ -d "$src" ] || fail "нет каталога $src"

  echo "==> Упаковка: $p (v$VERSION)"
  php_lint "$src"
  check_platform "$p"

  case "$p" in
    wc) build_wc ;;
    oc) build_oc ;;
    ps) build_ps ;;
  esac
done

echo "Готово. Артефакты в $DIST"
