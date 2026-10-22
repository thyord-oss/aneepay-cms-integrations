# AneePay CMS Integrations

AneePay plugins (crypto payments for USDT/USDC/DAI on Polygon + Amoy) for three
platforms in a single repository. Each plugin lives in its own folder and is
packaged into a separate artifact.

- **AneePay** — a non-custodial crypto payment system with a 0.5% fee, no KYC,
  hosted checkout, HMAC-SHA256-signed webhooks, and a sandbox (Amoy).
- API documentation and the payment model are in
  [`docs/INTEGRATION.md`](docs/INTEGRATION.md) (not tracked in git).

## Plugins

| Platform         | Folder                                                           | Build                  | Artifact                          | Roadmap                                                    |
| ---------------- | ---------------------------------------------------------------- | ---------------------- | --------------------------------- | ---------------------------------------------------------- |
| WooCommerce      | [`plugins/woocommerce/aneepay`](plugins/woocommerce/aneepay)     | `./scripts/pack.sh wc` | `dist/wc/aneepay.zip`             | [`docs/ROADMAP.md`](docs/ROADMAP.md)                       |
| OpenCart 3.x     | [`plugins/opencart/aneepay`](plugins/opencart/aneepay)           | `./scripts/pack.sh oc` | `dist/oc/aneepay-<ver>.ocmod.zip` | [`docs/ROADMAP-OPENCART.md`](docs/ROADMAP-OPENCART.md)     |
| PrestaShop 1.7/8 | [`plugins/prestashop/ps_aneepay`](plugins/prestashop/ps_aneepay) | `./scripts/pack.sh ps` | `dist/ps/ps_aneepay.zip`          | [`docs/ROADMAP-PRESTASHOP.md`](docs/ROADMAP-PRESTASHOP.md) |

Status: all three plugins are working implementations.

## Build

```bash
./scripts/pack.sh wc     # or oc, ps, all
```

The script lints PHP (`php -l`), validates the platform structure/manifest and
writes the artifact to `dist/<platform>/`. The version is read from `VERSION`
(or passed as an argument). Each platform is packaged with the correct root path.

## Local development

### WooCommerce

```bash
cd docker/woocommerce
docker compose up --watch
# WooCommerce: http://localhost:8081  (admin / admin)
```

### OpenCart

```bash
cd docker/opencart
docker compose up --build
# OpenCart: http://localhost:8082  (admin / admin)
```

Each container bind-mounts (WooCommerce) or installs (OpenCart, PrestaShop) the
matching plugin, so changes are picked up with a rebuild.

### PrestaShop

```bash
cd docker/prestashop
docker compose up --build
# PrestaShop: http://localhost:8084  (admin / admin)
```

## Structure

```
.
├── VERSION                            # shared release version
├── docs/                              # INTEGRATION.md + ROADMAPs (local, not tracked)
├── plugins/
│   ├── woocommerce/aneepay/           # WC plugin (PHP)
│   ├── opencart/aneepay/              # OC extension (PHP)
│   └── prestashop/ps_aneepay/         # PS module (PHP)
├── scripts/pack.sh                    # build / package artifacts
├── docker/                            # local test shops (woocommerce/, opencart/, prestashop/)
└── .github/workflows/release.yml      # CI: lint + package + release
```

> When working from [`docs/INTEGRATION.md`](docs/INTEGRATION.md), see §8 (the recipe for
> creating plugins for any CMS) — it is the common foundation for all three
> platforms.

> See [`TECHDEBT.md`](TECHDEBT.md) for the planned extraction of a shared
> platform-agnostic core (currently the pure logic is duplicated per plugin).
