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
| `al_bayan` | Al-Bayan (البيان) — pending schema confirmation |

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

## Admin Panel

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

## Developer

**محمد خلف · +963945235962**
