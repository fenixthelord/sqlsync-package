# SqlSync — Laravel Package

**`sqlsync/laravel-sqlsync`**

Drop-in Laravel package that bridges the SqlSync Windows Agent to any Laravel application.
Receives synced data from local accounting software (Al-Ameen, Al-Bayan) and exposes it via a clean REST API for mobile apps.

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

---

## Installation

```bash
composer require sqlsync/laravel-sqlsync
php artisan sqlsync:install
```

Add to `.env`:
```env
SQLSYNC_AGENT_SECRET=your-strong-secret-here
SQLSYNC_MULTI_TENANT=false
```

---

## Endpoints

### Agent → Backend (authenticated by HMAC)

| Method | URL | Description |
|--------|-----|-------------|
| POST | `/sqlsync/agent/sync` | Receive a sync batch |
| POST | `/sqlsync/agent/heartbeat` | Agent heartbeat ping |
| GET  | `/sqlsync/agent/logs` | Sync logs for this agent |

**Required headers from Agent:**
```
X-Agent-ID:    {machine-fingerprint}
X-Agent-Token: {hmac-sha256-signature}
X-Timestamp:   {unix-timestamp}
```

**Payload:**
```json
{
  "preset": "al_ameen",
  "company_id": 1,
  "records": [ { "guid": "...", "name": "...", ... } ]
}
```

---

### Mobile API (React Native)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/sqlsync/api/v1/records` | List records (paginated) |
| GET | `/sqlsync/api/v1/records/{guid}` | Single record |
| GET | `/sqlsync/api/v1/stats` | Dashboard stats |

**Query parameters:**
- `preset` — filter by preset (`al_ameen`, `al_bayan`)
- `search` — search by name, barcode, or code
- `company_id` — required when multi-tenant is enabled
- `per_page` — items per page (default: 20)

---

## Supported Presets

| Key | Software |
|-----|----------|
| `al_ameen` | Al-Ameen (الأمين) — maps from `vwMtPrices` |
| `al_bayan` | Al-Bayan (البيان) — maps from `MatCard` + `Barcode` (joined by `Num`) |

> **Note on Al-Bayan pricing:** Al-Bayan exposes generic `Price1`–`Price35` columns whose meaning (retail, wholesale, etc.) is configured per business *inside* Al-Bayan itself — it isn't fixed. The preset passes every price column through into `extra_data` as-is; each installation then picks the right one from **Filament → SqlSync → Product Bridge → Field Mapping**. Don't hardcode a "the retail price" assumption for Al-Bayan.

---

## Custom Presets

Implement `PresetContract` and register in config:

```php
// config/sqlsync.php
'presets' => [
    'my_software' => \App\SqlSync\MyPreset::class,
],
```

```php
class MyPreset implements \SqlSync\LaravelSqlSync\Contracts\PresetContract
{
    public function map(array $raw): array
    {
        return [
            'source_guid' => $raw['id'],
            'name'        => $raw['product_name'],
            'extra_data'  => ['price' => $raw['price']],
        ];
    }
}
```

---

## Product Bridge

The package never writes to your app's own `products` table directly — every project has a different schema. Instead, `SyncedRecordBridgeObserver` (auto-registered, nothing to wire up) reads a `BridgeSetting` row configured visually from **Filament → SqlSync → Product Bridge**:

- **Target model** — your own `App\Models\Product` (or anything else)
- **Match column** — e.g. `barcode` (synced record) ↔ `sku` (your table)
- **Field mapping** — any column on your table ↔ any field on the synced record (dot notation into `extra_data` supported, e.g. `extra_data.price_4`)
- **Auto-category** — find-or-create a category from a synced field (e.g. `group_name`), so a required `category_id` never blocks product creation
- **Bridge Activity log** (`sqlsync_bridge_logs`) — every decision (created / updated / skipped + reason) is recorded and browsable from Filament

### Re-applying the bridge to already-synced data
If you change the Bridge mapping *after* records have already synced, existing rows won't automatically re-trigger (especially with incremental/`SinceColumn` syncing). Two ways to force a re-run:

```bash
# CLI — no execution time limit, best for large catalogs, for developers only
php artisan sqlsync:reapply-bridge
```

Or click **"إعادة تطبيق الربط على كل السجلات"** in Filament, which dispatches `ReapplyBridgeJob` to your queue (requires `QUEUE_CONNECTION=database` + a cron running `queue:work` — see `TROUBLESHOOTING.md`) so non-technical users never need a terminal.

---

This package is **API-only**. For an admin UI, install the optional Filament plugin:

```bash
composer require sqlsync/laravel-sqlsync-filament
php artisan sqlsync-filament:install
```

---

## Multi-Tenancy

Enable in `.env`:
```env
SQLSYNC_MULTI_TENANT=true
```

Every request must include `company_id`. All data is isolated per company automatically.

---

## Configuration

Publish and edit:
```bash
php artisan vendor:publish --tag=sqlsync-config
```

```php
// config/sqlsync.php
return [
    'agent' => [
        'secret'              => env('SQLSYNC_AGENT_SECRET'),
        'timestamp_tolerance' => 300, // seconds
    ],
    'multi_tenant' => false,
    'routes' => [
        'prefix'       => 'sqlsync',
        'middleware'   => ['api'],
        'agent_prefix' => 'agent',
        'api_prefix'   => 'api/v1',
    ],
    'sync' => [
        'log_enabled'        => true,
        'log_retention_days' => 30,
        'batch_size'         => 500,
    ],
];
```

---

---

## Troubleshooting

Common real-world issues (Opcache on shared hosting, WinForms quirks, mismatched preset columns, duplicate barcodes, etc.) are documented in **[`TROUBLESHOOTING.md`](./TROUBLESHOOTING.md)**.

Full docs (with screenshots and a step-by-step quick start): see the SqlSync website `docs/` folder.

---

## Developer

**محمد خلف · +963945235962**
