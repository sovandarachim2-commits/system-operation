# LuckyBox — Full Technical Specification (Rewrite Reference)

This document is a complete technical breakdown of the current LuckyBox system for the purpose of planning a full rewrite. It covers every layer: languages, architecture, database schema, business rules, API patterns, and known issues.

---

## 1. Technology Stack (Current)

| Layer | Technology |
|---|---|
| **Language** | PHP 8.x |
| **Web Server** | Apache (XAMPP) |
| **Database** | MySQL / MariaDB via PDO (utf8mb4, timezone +07:00) |
| **Secondary DB Driver** | mysqli (scanner module only — raw procedural style) |
| **Local cache DB** | SQLite (`scanner/data_base.db`) — legacy, mostly unused |
| **HTML** | Embedded in PHP, no templating engine |
| **CSS** | Bootstrap 5.3.3 (CDN) + `public/css/app.css` + heavy inline `<style>` per page |
| **JavaScript** | Vanilla JS, inline `<script>` blocks + `public/js/admin-shell.js` |
| **UI Icons** | Bootstrap Icons 1.11 (admin/seller/cashier) · Google Material Icons (scanner) |
| **Charts** | Chart.js (admin dashboard — likely CDN) |
| **QR Scanning** | html5-qrcode (unpkg CDN) |
| **OCR** | Tesseract.js 4.1.1 (cdnjs CDN) — scanner phone number OCR |
| **Excel Export** | SheetJS/xlsx 0.18.5 (cdnjs CDN) |
| **File Storage** | Local filesystem OR Cloudflare R2 (scanner images only) |
| **Notifications** | Telegram Bot API (HTTP POST via `file_get_contents`) |
| **Session** | PHP native sessions (`$_SESSION`) |
| **Password hash** | `SHA2(password, 256)` — ⚠️ NOT bcrypt |
| **Timezone** | Asia/Bangkok (UTC+7), enforced via `SET time_zone='+07:00'` on every PDO connection |

---

## 2. Architecture Overview

The project is a **monolithic MVC-lite PHP application** with:

- **No framework** — plain PHP files, each page is self-contained
- **No ORM** — raw PDO/mysqli queries inline on every page
- **No front-end build system** — CSS/JS referenced from CDN or `public/`
- **No routing** — URLs map directly to `.php` files
- **No API versioning** — JSON responses mixed with HTML pages in same directory
- **Schema migrations done inline** — every page runs `ALTER TABLE IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS` on load (see section 6)

### Request Flow

```
Browser
  └─► Apache (XAMPP)
        └─► PHP page (e.g. admin/statistics.php)
              ├── require_once auth.php       → starts session, checks login
              ├── require_role_or_permission  → RBAC gate
              ├── require_once db.php         → singleton PDO connection
              ├── require_once config.php     → loads $DB_*, $TELEGRAM_*, $BASE_URL, etc.
              ├── Business logic (inline SQL)
              └── HTML output (echo / mixed PHP+HTML)
```

### Directory Layout

```
/ (project root)
├── config.php              ← ALL config: DB, Telegram, storage, base URL
├── db.php                  ← PDO singleton: get_db_connection()
├── auth.php                ← RBAC, session, require_login/role/permission
├── helpers.php             ← Telegram send, order code gen, receipt helpers
├── user_activity_lib.php   ← Activity log (append-only table)
├── logout.php
├── receipt.php             ← Printable receipt view
├── maintenance.php         ← Maintenance toggle UI
├── layout/
│   ├── header.php          ← Bootstrap + sidebar nav (used by admin pages)
│   └── footer.php
├── public/
│   ├── css/app.css
│   └── js/admin-shell.js
├── admin/                  ← 128 PHP files
├── seller/                 ← 5 PHP files
├── cashier/                ← 9 PHP files
└── scanner/                ← 52 PHP files
```

---

## 3. Configuration (`config.php`)

All configuration lives in one file at the root. Key variables:

```php
// Database
$DB_HOST, $DB_NAME, $DB_USER, $DB_PASS

// App
$BASE_URL      // e.g. '/LuckyBox'
$DOMAIN        // e.g. 'http://localhost/LuckyBox'

// Telegram (main orders)
$TELEGRAM_BOT_TOKEN
$TELEGRAM_CHAT_ID
$TELEGRAM_TARGETS  // array of ['chat_id' => '...', 'thread_id' => '...']

// Telegram (marketing takes - optional override)
$MARKETING_TELEGRAM_BOT_TOKEN
$MARKETING_TELEGRAM_CHAT_ID
$MARKETING_TELEGRAM_TARGETS

// Scanner Telegram (hardcoded in scanner/config.php)
// TELEGRAM_CHAT_ID = '-1002942067666'

// Scanner image storage
$SCANNER_STORAGE_DRIVER          // 'local' or 'r2'
$SCANNER_R2_ACCOUNT_ID
$SCANNER_R2_BUCKET
$SCANNER_R2_ACCESS_KEY_ID
$SCANNER_R2_SECRET_ACCESS_KEY
$SCANNER_R2_PUBLIC_BASE_URL
$SCANNER_R2_OBJECT_PREFIX        // default: 'scanner'
```

---

## 4. Database Schema (Full)

### Core Tables

#### `users`
```sql
id              INT PK AUTO_INCREMENT
username        VARCHAR(100) UNIQUE NOT NULL
password_hash   VARCHAR(64)   -- SHA2(password, 256) ⚠️
name            VARCHAR(255)
role            VARCHAR(32)   -- legacy: 'admin'|'seller'|'cashier'|'scanner'
phone           VARCHAR(50)
telegram_chat_id   VARCHAR(100) NULL
telegram_thread_id VARCHAR(100) NULL
profile_image   VARCHAR(500) NULL
active          TINYINT(1) DEFAULT 1
last_seen_at    DATETIME NULL   -- added dynamically by auth.php
created_at      TIMESTAMP
```

#### `roles`
```sql
id    INT PK AUTO_INCREMENT
name  VARCHAR(32) UNIQUE    -- 'admin','seller','cashier','scanner' + custom
label VARCHAR(100)
created_at TIMESTAMP
```

#### `user_roles`
```sql
user_id  INT FK → users.id  ON DELETE CASCADE
role_id  INT FK → roles.id  ON DELETE CASCADE
PRIMARY KEY (user_id, role_id)
```

#### `permissions`
```sql
id          INT PK AUTO_INCREMENT
perm_key    VARCHAR(100) UNIQUE
label       VARCHAR(200)
description TEXT
```

#### `role_permissions`
Two schemas exist (backwards compatible):
- **Old**: `role VARCHAR(32)` + `permission_id INT FK → permissions.id`
- **New**: `role_id INT FK → roles.id` + `permission_id INT FK → permissions.id`

---

#### `products`
```sql
id            INT PK AUTO_INCREMENT
name          VARCHAR(255) NOT NULL
cost          DECIMAL(10,2) DEFAULT 0   -- base cost (overridden by product_costs)
product_type  ENUM('normal','set','General') DEFAULT 'General'
brand_id      INT NULL FK → brands.id
sku           VARCHAR(100) NULL
active        TINYINT(1) DEFAULT 1
blocked_month VARCHAR(7) NULL   -- 'YYYY-MM': hide from auto-creation that month
blocked_reason VARCHAR(255) NULL
created_at    TIMESTAMP
updated_at    TIMESTAMP
```

#### `product_costs` (monthly pricing)
```sql
id              INT PK AUTO_INCREMENT
product_id      INT FK → products.id  ON DELETE CASCADE
month_year      VARCHAR(7) NOT NULL   -- 'YYYY-MM'
selling_price   DECIMAL(10,2)
original_cost   DECIMAL(10,2) DEFAULT 0
supplier_cost   DECIMAL(10,2) NULL
shipping_cost   DECIMAL(10,2) NULL
other_costs     DECIMAL(10,2) NULL
commission_rate DECIMAL(5,2) DEFAULT 0
commission_amount DECIMAL(10,2) DEFAULT 0
total_cost      DECIMAL(10,2) GENERATED ALWAYS AS (original_cost + supplier_cost + shipping_cost + other_costs) STORED
notes           TEXT NULL
updated_by      INT NULL FK → users.id
cost_updated_at TIMESTAMP
UNIQUE KEY (product_id, month_year)
```

> **Key rule**: Sellers see `COALESCE(pc.selling_price, p.cost)` for current month. Products only appear in seller picker if they have a `product_costs` row for `month_year <= current_month`.

#### `brands`
```sql
id       INT PK AUTO_INCREMENT
name     VARCHAR(255) NOT NULL
color    VARCHAR(7) NULL   -- hex color for UI badges
active   TINYINT(1) DEFAULT 1
```

---

#### `pages`
```sql
id    INT PK AUTO_INCREMENT
name  VARCHAR(255) NOT NULL
slug  VARCHAR(255)
```

#### `delivery_types`
```sql
id    INT PK AUTO_INCREMENT
name  VARCHAR(255) NOT NULL
```

#### `delivery_costs`
```sql
id      INT PK AUTO_INCREMENT
label   VARCHAR(255) NOT NULL
amount  DECIMAL(10,2) DEFAULT 0
```

#### `logos`
```sql
id          INT PK AUTO_INCREMENT
name        VARCHAR(255)
image_path  VARCHAR(500)
is_default  TINYINT(1) DEFAULT 0
```

---

#### `orders`
```sql
id                INT PK AUTO_INCREMENT
order_code        VARCHAR(50) UNIQUE   -- format: E-SHA-YYYYMMDD#### (e.g. E-SHA-202605260001)
seller_id         INT FK → users.id
customer_name     VARCHAR(255)
phone             VARCHAR(100)
location          TEXT
page_id           INT FK → pages.id
delivery_type_id  INT FK → delivery_types.id
delivery_cost_id  INT FK → delivery_costs.id
total_amount      DECIMAL(10,2)
discount          DECIMAL(10,2) DEFAULT 0
status            VARCHAR(20)   -- 'paid' | 'unpaid'
payment_method    VARCHAR(100) NULL
paid_note         TEXT NULL
payment_date      DATE NULL
is_cancelled      TINYINT(1) DEFAULT 0
is_returned       TINYINT(1) DEFAULT 0
cancel_reason     TEXT NULL
telegram_message_id       INT NULL    -- first Telegram message ID
telegram_last_message_id  INT NULL    -- most recent Telegram message ID
created_at        TIMESTAMP
updated_at        TIMESTAMP
```

#### `order_items`
```sql
id           INT PK AUTO_INCREMENT
order_id     INT FK → orders.id  ON DELETE CASCADE
product_id   INT FK → products.id
quantity     INT
line_total   DECIMAL(10,2)
is_lucky_box TINYINT(1) DEFAULT 0   -- 1 = part of Lucky Box, merged on receipt
```

#### `print_jobs`
```sql
id          INT PK AUTO_INCREMENT
order_id    INT FK → orders.id
cashier_id  INT FK → users.id
printed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

> **Key rule**: An order is "unprinted" if it has no row in `print_jobs`.
> `COUNT(orders) WHERE NOT EXISTS (SELECT 1 FROM print_jobs WHERE order_id = orders.id)`

---

#### `product_sets`
```sql
id               INT PK AUTO_INCREMENT
set_name         VARCHAR(255) NOT NULL
set_description  TEXT
total_cost       DECIMAL(10,2)
selling_price    DECIMAL(10,2)
profit_margin    DECIMAL(5,2)
commission_rate  DECIMAL(5,2)
commission_amount DECIMAL(10,2)
available_stock  INT DEFAULT 0
total_created    INT DEFAULT 0
is_active        BOOLEAN DEFAULT 1
is_lucky_box     TINYINT(1) DEFAULT 0   -- marks as Lucky Box for seller picker
created_at       TIMESTAMP
updated_at       TIMESTAMP
```

#### `product_set_items`
```sql
id              INT PK AUTO_INCREMENT
product_set_id  INT FK → product_sets.id  ON DELETE CASCADE
product_id      INT FK → products.id  ON DELETE CASCADE
quantity        DECIMAL(8,2) DEFAULT 1
unit_cost       DECIMAL(10,2)
total_cost      DECIMAL(10,2)
UNIQUE KEY (product_set_id, product_id)
```

#### `product_set_audit_log`
```sql
id              INT PK AUTO_INCREMENT
product_set_id  INT FK → product_sets.id  ON DELETE CASCADE
action_type     VARCHAR(50)   -- 'created'|'updated'|'stock_added'|'deleted'
user_id         INT NULL
user_name       VARCHAR(255) NULL
action_details  TEXT NULL
old_values      JSON NULL
new_values      JSON NULL
created_at      TIMESTAMP
```

#### `product_set_qr_label_print_history`
```sql
id          INT PK
label_code  VARCHAR(100)   -- the unique QR code printed on the physical label
set_name    VARCHAR(255)
-- + print metadata
```

---

#### `purchase_vendors`
```sql
id        INT PK AUTO_INCREMENT
name      VARCHAR(255) NOT NULL
is_active TINYINT(1) DEFAULT 1
-- + contact info fields
```

#### `purchase_orders`
```sql
id            INT PK AUTO_INCREMENT
order_number  VARCHAR(50)   -- format: PO-YYYYMMDD-####
vendor_id     INT FK → purchase_vendors.id
order_date    DATE
expected_date DATE NULL
notes         TEXT NULL
status        VARCHAR(30)
total_amount  DECIMAL(10,2)
created_at    TIMESTAMP
```

#### `purchase_order_items`
```sql
id                INT PK
purchase_order_id INT FK → purchase_orders.id
product_id        INT NULL FK → products.id
stock_item_id     INT NULL FK → stock_items.id
quantity          DECIMAL(10,2)
unit_cost         DECIMAL(10,2)
total_cost        DECIMAL(10,2)
```

#### `purchase_payments`
```sql
id                INT PK
purchase_order_id INT FK → purchase_orders.id
payment_date      DATE
amount            DECIMAL(10,2)
payment_method    VARCHAR(100)
notes             TEXT NULL
created_by        INT FK → users.id
```

---

#### `marketing_takes`
```sql
id                  INT PK AUTO_INCREMENT
take_code           VARCHAR(50)   -- format: MT-YYYYMMDD-####
event_name          VARCHAR(255)
event_date          DATE
location            VARCHAR(500) NULL
notes               TEXT NULL
status              VARCHAR(30)   -- 'pending'|'approved'|'reconciled'|'cancelled'
storage_location_id INT NULL FK → storage_locations.id
created_by          INT FK → users.id
approved_by         INT NULL FK → users.id
updated_by          INT NULL FK → users.id
telegram_message_id INT NULL
telegram_chat_id    VARCHAR(100) NULL
telegram_thread_id  INT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

#### `marketing_take_items`
```sql
id             INT PK
take_id        INT FK → marketing_takes.id
product_id     INT FK → products.id
quantity_taken DECIMAL(10,2)
quantity_sold  DECIMAL(10,2) NULL
quantity_returned DECIMAL(10,2) NULL
-- + cost fields
```

---

#### `storage_locations`
```sql
id             INT PK AUTO_INCREMENT
location_code  VARCHAR(50) UNIQUE
location_name  VARCHAR(255)
location_type  VARCHAR(50)   -- 'warehouse' | other
description    TEXT NULL
capacity       DECIMAL(10,2) DEFAULT 0
is_active      BOOLEAN DEFAULT 1
is_default     BOOLEAN DEFAULT 0   -- one location is the default for inventory returns
created_at     TIMESTAMP
```

#### `current_inventory`
```sql
id                  INT PK AUTO_INCREMENT
item_name           VARCHAR(255)
sku                 VARCHAR(100) NULL
storage_location_id INT FK → storage_locations.id
quantity_on_hand    DECIMAL(10,2)
unit_cost           DECIMAL(10,2)
last_updated        TIMESTAMP
updated_by          INT FK → users.id
```

#### `stock_items`
```sql
id               INT PK
name             VARCHAR(255)
unit             VARCHAR(50) NULL
current_quantity DECIMAL(10,2) DEFAULT 0
is_active        TINYINT(1) DEFAULT 1
```

---

#### `finance_spending`
```sql
id             INT PK AUTO_INCREMENT
spending_date  DATE
amount         DECIMAL(10,2)
category       VARCHAR(100)
subcategory    VARCHAR(100) NULL
description    TEXT NULL
payment_method VARCHAR(100) NULL
user_id        INT FK → users.id
created_at     TIMESTAMP
```

#### `finance_categories`
```sql
id              INT PK
name            VARCHAR(100)
type            VARCHAR(20)   -- 'main' | 'sub'
parent_category VARCHAR(100) NULL
```

#### `finance_topups` (top-up records)
```sql
id           INT PK
topup_date   DATE
amount       DECIMAL(10,2)
bank         VARCHAR(100) NULL
notes        TEXT NULL
created_by   INT FK → users.id
```

#### `cashflow` (cash flow tracking)
```sql
id              INT PK
date            DATE
type            VARCHAR(20)   -- 'income' | 'expense'
category_id     INT FK → cashflow_categories.id
amount          DECIMAL(10,2)
payment_method  VARCHAR(100) NULL
description     TEXT NULL
-- order reference, bank transfer, etc.
```

#### `cashflow_categories`
```sql
id     INT PK
name   VARCHAR(255)
type   VARCHAR(20)   -- 'income' | 'expense'
color  VARCHAR(7) NULL
```

#### `bank_transfers`
```sql
id              INT PK
transfer_date   DATE
from_account    VARCHAR(100)
to_account      VARCHAR(100)
amount          DECIMAL(10,2)
notes           TEXT NULL
created_by      INT FK → users.id
```

---

#### `note_options` (configurable dropdown options)
```sql
id               INT PK AUTO_INCREMENT
option_text      VARCHAR(255)
is_active        TINYINT(1) DEFAULT 1
is_seller_active TINYINT(1) DEFAULT 1
is_admin_active  TINYINT(1) DEFAULT 1
is_finance_default TINYINT(1) DEFAULT 0
sort_order       INT DEFAULT 0
```

> **Used for**: Payment methods in seller orders AND finance dashboard bank/payment dropdowns.
> Seller sees options where `is_seller_active = 1`. Admin/finance sees `is_admin_active = 1`.

---

#### Scanner-specific tables (used by `scanner/` module)

| Table | Purpose |
|---|---|
| `prepare_items` | Records of items being prepared for packing (invoke, phone, status, amount, set_type, set_qr, images) |
| `out_items` | Items dispatched/sent out |
| `prepare_set` | Set preparation records (maps invoice → set QR codes) |
| `return_items` | Items returned from customers |
| `confirm_items` | Confirmation step records |
| `scanner_out_items_delivery_by` | Configurable list of delivery staff names |

> Scanner uses **mysqli** (not PDO). Connection created in `scanner/config.php`.

---

#### Other tables

| Table | Purpose |
|---|---|
| `app_settings` | Key-value store: `qr_effective_date` etc. |
| `invoice_settings` | Company info for purchase invoice PDF |
| `user_activity_log` | Append-only activity log (page_view, create, edit, delete, login, lockout) |
| `stock_movements` | Movement audit trail for inventory |
| `stock_categories` | Product categories for inventory |
| `payment_methods` | Payment method master table |
| `logos` | Receipt card logo images |
| `order_edit_audit` | Audit log for order edits |
| `product_set_qr_code_settings` | QR code generation settings per product set |

---

## 5. Authentication & RBAC (auth.php)

### Session model
- Login stores `$_SESSION['user_id']`
- Every protected page calls `require_login()` which:
  1. Checks `.maintenance` file → show maintenance page (unless bypass perm)
  2. Checks `$_SESSION['user_id']`
  3. Loads user from DB: `SELECT * FROM users WHERE id = ? AND active = 1`
  4. Updates `users.last_seen_at` (throttled: max once per 90 seconds)
  5. Logs a `page_view` activity event

### Two RBAC modes (auto-detected)

**Legacy mode** (if `permissions` or `role_permissions` tables missing):
- Single role per user stored in `users.role`
- Only `admin` can access admin pages

**RBAC mode** (if both tables exist):
- Users can have multiple roles via `user_roles` table
- Permissions assigned to roles via `role_permissions`
- `has_permission(permKey)` checks the junction

### Permission key format
All keys follow `resource.action`:
```
orders.view              marketing_take.view        finance_dashboard.view
users.view               users.create               users.delete
inventory.view           stock_operations.view      storage_locations.view
purchase_orders.view     purchase_receiving.view     purchase_payments.view
scanner_home.view        scanner_home.create         print_orders.view
seller_orders.view       seller_orders.create        cashier_date.view
maintenance_bypass.view  role_permissions.view       ...
```

### Key functions
```php
require_login()                              // Enforce session + maintenance
require_role(array $roles)                   // Enforce role(s) — with perm fallback
require_permission(string $permKey)          // Enforce specific permission
require_role_or_permission(array $r, ...$p)  // Allow if role OR permission match
has_permission(string $permKey): bool
current_user(bool $refresh = false): ?array  // Cached DB user row
user_role_names(PDO, array $user): array     // All role names for user
user_permissions(PDO, array $user): array    // All permission keys for user
auth_touch_user_last_seen(PDO, array $user)  // Update last_seen_at (throttled)
```

### Password hashing ⚠️
```php
// Current (INSECURE — SHA2 is not a password hash):
SHA2($password, 256)

// INSERT:
INSERT INTO users (password_hash) VALUES (SHA2(?, 256))

// Verify (login.php):
SELECT * FROM users WHERE username = ? AND password_hash = SHA2(?, 256) AND active = 1
```
> **Rewrite must use `password_hash()` / `password_verify()`**

---

## 6. Inline Schema Migration Pattern ⚠️

Every page runs schema checks on every request:
```php
// Example from order_management.php:
$cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('payment_date', $cols)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN payment_date DATE NULL AFTER payment_method");
}
```

This pattern appears on **most** admin pages. It means:
- No migration files
- Schema state is implied by what has been visited
- Race conditions possible under concurrent load
- Very slow to audit what the DB actually looks like

> **Rewrite should use a proper migration system (Flyway, Phinx, or custom numbered migrations)**

---

## 7. Module Detail: Seller Order Creation

**File**: `seller/order_new.php`

### Form fields
| Field | Validation |
|---|---|
| `customer_name` | Required |
| `phone` | Cambodian phone validator: 9-digit (most prefixes) or 10-digit (076/096/031/071/088/097/038/018) |
| `location` | Required |
| `page_id` | FK → `pages.id`, required |
| `delivery_type_id` | FK → `delivery_types.id`, required |
| `delivery_cost_id` | FK → `delivery_costs.id`, required |
| `status` | `'paid'` or `'unpaid'`, required |
| `payment_method` | Required if `status = 'paid'` — from `note_options` |
| `payment_date` | Required if `status = 'paid'` |
| `discount` | Optional decimal ≥ 0 |
| `product_id[]` | Array of product IDs |
| `quantity[]` | Array of quantities (min 1) |
| `line_mode[]` | `'general'` or `'lucky'` — marks Lucky Box lines |

### Product pricing logic
```sql
SELECT p.id, p.name,
    COALESCE(pc.selling_price, p.cost) AS cost
FROM products p
LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
WHERE p.active = 1
AND p.id IN (SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?)
```
- Products only appear if they have at least one `product_costs` row
- Selling price = current month's `selling_price` or falls back to `products.cost`

### Lucky Box logic
- Lucky Box sets are `product_sets.is_lucky_box = 1`
- On the order form, they appear in a separate "Lucky Box" section
- Items submitted with `line_mode = 'lucky'` get `order_items.is_lucky_box = 1`
- On receipts/Telegram, all lucky items are merged into one "Lucky box x N = $X.XX" line

### Order code generation
```php
// Format: E-SHA-YYYYMMDD0001
$prefix = 'E-SHA-' . $useDate->format('Ymd');
// Find last code with this prefix, increment suffix
// Padded to 4 digits: E-SHA-202605260001
```
- The date in the code is controlled by `app_settings.qr_effective_date` (set by cashier dashboard)
- Cashier can set it to "today" or "tomorrow" for pre-printing next day

### Duplicate product handling
- Duplicate `product_id` + `line_mode` entries are **merged** (quantities added)
- Different `line_mode` for the same product_id = different rows (one general, one lucky)

### After save
1. Insert into `orders`
2. Insert each into `order_items`
3. Call `send_order_to_telegram()` → posts to Telegram

---

## 8. Module Detail: Scanner

**Location**: `scanner/`
**Stack**: PHP + mysqli + Vanilla JS + html5-qrcode + Tesseract.js

### Flow overview
```
scanner/home.php
  ├── prepare_items.php      → Scan barcode + phone + image → save_prepare_items.php
  ├── out_items.php          → Scan barcode + delivery by → save_out_items.php
  ├── prepare_set.php        → Scan order + set QR labels → save_prepare_set.php
  ├── return_items.php       → Scan barcode + reason → save_return_items.php
  ├── sourcedata.php         → Report: join prepare_items + out_items
  └── report_menu.php        → Report navigation
```

### prepare_items (វេចខ្ចប់ឥវ៉ាន់)
- Scans: barcode/QR (invoice code), phone, status, amount, set_type, image
- `set_type` = `'non-set'` | `'set'`
- **If `set_type = 'set'`**: validates that:
  - Order exists and has `is_lucky_box` items
  - Number of set QR labels scanned = lucky box quantity on order
  - No duplicate label codes
  - Label codes exist in `product_set_qr_label_print_history`
  - Set name from label matches allowed lucky product names on the order
- Images: uploaded to `scanner/uploads/YYYY/MM/` (local) or Cloudflare R2
- Image type: `inv_` prefix for invoice photos, `full_` prefix for full photos

### prepare_set (រៀបចំឥវ៉ាន់Set)
- Creates/manages physical product set boxes
- Looks up set by QR code: `scanner/lookup_set_by_qr.php`
- QR code format from `product_set_qr_code_settings`

### out_items (ដាក់ឥរ៉ាន់ចេញ)
- Records items going out for delivery
- Field: "Delivery By" — from `scanner_out_items_delivery_by` table
- Configurable at `scanner/out_items_config/out_items_delivery_by.php`

### return_items
- Records returns
- Quick return shortcut from `home.php?quickscan=1`

### Image storage abstraction (`scanner/storage.php`)
```php
scanner_storage_driver()          // 'local' or 'r2'
scanner_storage_upload_subdir_from_datetime(string $dt)  // 'YYYY/MM'
scanner_storage_build_public_url(string $storedPath)     // resolve local or R2 URL
```

### Cron
`scanner/cron_send_status.php` — sends delivery status summary to Telegram (runs externally via cron/scheduler)

---

## 9. Module Detail: Admin

### Dashboard (`admin/dashboard.php`)
- Monthly revenue & order count chart (by `print_jobs.printed_at`)
- Excludes cancelled (`is_cancelled = 1`) and returned (`is_returned = 1`) orders
- Finance section only shown if user has `finance_dashboard.view` permission

### Statistics (`admin/statistics.php`)
- Date range filter (from/to)
- Seller breakdown
- Product breakdown

### Order Management (`admin/order_management.php`)
Contains the most complex business logic in the system:

**Order return inventory logic**:
```php
function applyReturnedOrderInventory(PDO $pdo, $orderId, $userId, $isReversal = false)
```
- When an order is marked as returned, stock is added back to `current_inventory`
- For `product_type = 'set'`: expands to components via `product_set_items`
- Uses `storage_locations.is_default = 1` as the target location
- `$isReversal = true` reverses the return (subtracts stock back)

**`current_inventory` upsert**:
```php
function upsertInventoryQuantity(PDO $pdo, $productId, $productName, $quantityDelta, $locationId, $userId)
```

### Product Costs (`admin/product_costs.php`)
- Monthly pricing system: each product has separate costs per `YYYY-MM`
- `product_costs.total_cost` is a generated column: `original_cost + supplier_cost + shipping_cost + other_costs`
- Products can be "blocked" for a specific month (`products.blocked_month`)

### Marketing Takes (`admin/marketing_take_*.php`)
- Code format: `MT-YYYYMMDD-####`
- Lifecycle: `pending` → `approved` → `reconciled` (or `cancelled`)
- Functions in `admin/marketing_take_functions.php`
- Telegram: uses separate `$MARKETING_TELEGRAM_*` config or falls back to global
- Per-take Telegram tracking: stores `telegram_message_id`, `telegram_chat_id`, `telegram_thread_id`

### Purchase Orders (`admin/purchase_orders.php`)
- Code format: `PO-YYYYMMDD-####`
- Items can reference `products` OR `stock_items`
- Status flow: draft → ordered → partially_received → received → cancelled
- `admin/purchase_receiving.php` → receive items → updates inventory
- `admin/purchase_returns.php` → return to vendor
- `admin/purchase_payments.php` → track payments
- Invoice PDF: `admin/generate_order_invoice.php`, `admin/generate_payment_invoice.php`

### Finance Dashboard (`admin/finance_dashboard.php`)
- Shows spending total, top-up total, net cash
- Bank/payment method dropdown from `note_options WHERE is_admin_active = 1`
- Default bank = first option with `is_finance_default = 1`
- Categories from `finance_categories` table
- Subcategories dynamically filtered by selected parent category via JS

### Cashflow (`admin/cashflow.php`)
- Tracks money received by payment method
- `paid` orders, non-cancelled/non-returned, filtered by `payment_date` or `created_at`
- Merges `note_options` with actual order payment methods

### Inventory (`admin/inventory.php`)
- Date-range filter
- Product quantities sold per date range
- Money breakdown: paid vs unpaid
- Cashier print stats
- CSV export

### User Management (`admin/users.php`)
- CRUD: create, update, delete, toggle active
- Per-user Telegram: `telegram_chat_id`, `telegram_thread_id`
- Roles: synced to `user_roles` on every save
- Default roles seeded on load: admin, seller, cashier, scanner
- Password stored as `SHA2(password, 256)`
- Activity logged: `user_create`, `user_update`, `user_delete`
- Main admin (`username = 'admin'`) is protected from deletion/demotion by non-main-admin users

---

## 10. Module Detail: Cashier

### Dashboard (`cashier/dashboard.php`)
- Shows count of unprinted orders
- Controls `app_settings.qr_effective_date` (today / tomorrow) — affects order code date prefix
- Links to: Print Orders, Print History, Profile

### Print Orders (`cashier/print_orders.php`)
- Filter by: date range, seller, status, printed/unprinted
- Checkbox selection with "Check All"
- Batch print → inserts `print_jobs` rows
- Shows receipt card per order (logo + products + delivery + QR)

### Bulk Print (`cashier/bulk_print.php`)
- Alternative bulk printing interface

### Cancelled Orders (`cashier/cancelled_orders.php`)
- View orders where `is_cancelled = 1`

### Broadcast (`cashier/broadcast.php`)
- Send broadcast messages (to sellers? — notification feature)

---

## 11. Telegram Integration (helpers.php)

### `send_order_to_telegram(PDO, int $order_id)`
1. Load order with seller, page, delivery, items
2. Normalize items for display (merge lucky box lines)
3. Build text message
4. If previous `telegram_message_id` exists → reply to it
5. Use per-seller `telegram_chat_id`/`telegram_thread_id` if set, else global
6. If thread not found → retry without thread
7. Save `telegram_message_id` (first) and `telegram_last_message_id` (always latest) to order

### `send_order_cancel_telegram(PDO, int $order_id, string $reason)`
- Same routing logic
- Replies to original message thread

### Multi-target sending
```php
$TELEGRAM_TARGETS = [
    ['chat_id' => '-100xxx', 'thread_id' => null],
    ['chat_id' => '-100yyy', 'thread_id' => 42],
];
// If not set, falls back to $TELEGRAM_CHAT_ID
```

### Marketing take Telegram (`admin/marketing_take_functions.php`)
- Uses HTML parse_mode (orders use plain text)
- Separate `$MARKETING_TELEGRAM_*` config with fallback chain

### Transport
All Telegram sends use:
```php
@file_get_contents($url, false, stream_context_create([
    'http' => ['method' => 'POST', 'content' => http_build_query($data), 'timeout' => 5]
]));
```
Fire-and-forget, 5 second timeout. No retry on error (except thread fallback).

---

## 12. Order Code System

### Main orders
```
Format: E-SHA-YYYYMMDD####
Example: E-SHA-202605270001

Date comes from: app_settings WHERE key = 'qr_effective_date'
  - If NULL or past → use today
  - Cashier can set it to tomorrow (pre-print next day's orders)
Sequence: SELECT order_code FROM orders WHERE order_code LIKE 'E-SHA-YYYYMMDD%' ORDER BY id DESC LIMIT 1
           Take numeric suffix, increment, pad to 4 digits
```

### Marketing takes
```
Format: MT-YYYYMMDD-####
Example: MT-20260527-0001
Sequence: SELECT take_code FROM marketing_takes WHERE take_code LIKE 'MT-YYYYMMDD-%' ORDER BY id DESC LIMIT 1
```

### Purchase orders
```
Format: PO-YYYYMMDD-####
Example: PO-20260527-0001
Sequence: SELECT order_number FROM purchase_orders WHERE order_number LIKE 'PO-YYYYMMDD-%' ORDER BY id DESC LIMIT 1
```

> ⚠️ **Race condition**: all three use a `SELECT ... ORDER BY id DESC LIMIT 1` to find the last code, then increment. Under concurrent inserts this can produce duplicates. The `order_code` column has a UNIQUE constraint (which would cause an insert error), but the code does not handle/retry this case.

---

## 13. User Activity Logging (`user_activity_lib.php`)

Every page view and mutation is logged to `user_activity_log`:

```php
user_activity_log(PDO, array $user, string $action, string $details, array $context = [])
```

- `action` values: `page_view`, `create`, `edit`, `delete`, `login`, `lockout`, `user_create`, `user_update`, `user_delete`
- Captures: user_id, user_name, action, details, context (JSON), ip_address, device, device_name, device_model, user_agent, request_uri
- Table created automatically on first call

---

## 14. Maintenance Mode

- Toggle: create/delete file `.maintenance` at project root
- On `require_login()`: if file exists AND user does not have `maintenance_bypass.view` permission → serve `maintenance.html` and exit
- RBAC disabled fallback: only `username = 'admin'` can bypass

---

## 15. Frontend Patterns

### Admin shell (`public/js/admin-shell.js`)
- Provides `window.adminRunWhenReady(fn)` — runs on DOMContentLoaded or immediately
- Admin sidebar navigation uses `fetch()` to load page content (SPA-like)
- Custom header `X-Admin-Nav-Request: 1` sent on nav fetches
- Server detects `auth_is_admin_nav_fetch()` → returns JSON redirect instead of HTML on session expire

### Admin page layout (`admin/header.php`)
- Bootstrap 5.3 sidebar + topbar
- Sidebar: dark background `#232323`, gold accent `#fdb04c`
- Desktop: fixed sidebar 250px wide; mobile: off-canvas hamburger

### Scanner UI
- Dark theme: background `#1a1a1a` / cards `#232323`, gold `#fdb04c`
- Full-screen QR scanner overlay using html5-qrcode
- OCR for phone number reading via Tesseract.js
- Bottom navigation bar (fixed, 65px)

---

## 16. Known Technical Debt

| Issue | Location | Impact |
|---|---|---|
| **SHA2 password hashing** | `users.php` login + create | Security — must use `password_hash()` |
| **Inline schema migrations** | Every admin page | Performance + reliability |
| **Mixed PDO + mysqli** | Scanner vs main app | Code complexity |
| **No input sanitization on some scanner APIs** | `scanner/save_prepare_items.php` | XSS/SQLi risk (uses prepared stmts but error display leaks) |
| **Race condition in code generation** | `helpers.php` all 3 generators | Duplicate codes under load |
| **`@file_get_contents` for Telegram** | `helpers.php` | Blocking HTTP in request cycle |
| **All SQL inline on pages** | Entire codebase | No separation of concerns |
| **No API versioning** | All JSON endpoints | Breaking changes are silent |
| **No CSRF protection** | All POST forms | CSRF attack surface |
| **Products need `product_costs` row to appear** | `seller/order_new.php` | Confusing for admins |
| **`admin/products.php` is entirely commented out** | `admin/products.php` | Old page replaced (but file still there) |
| **scanner/insert.php is empty (1 line)** | `scanner/insert.php` | Dead file |
| **Hardcoded Telegram chat ID in scanner/config.php** | Line 42 | Not configurable without code edit |
| **`display_errors = 1` in scanner save API** | `scanner/save_prepare_items.php` | Leaks errors to API response |

---

## 17. Recommended Rewrite Stack

Based on the current system's scale and features, here are solid choices:

### Option A: Laravel (PHP) — Minimal Rewrite Friction
- Keep PHP, eliminate boilerplate
- Eloquent ORM replaces inline SQL
- Blade templates replace mixed PHP/HTML
- Laravel Sanctum for session auth
- Laravel migrations replace inline ALTER TABLE
- Queued jobs for Telegram sends
- Laravel Storage for local/S3/R2

### Option B: Node.js + React/Next.js
- Next.js App Router for pages + API routes
- Prisma ORM for DB
- BullMQ for Telegram job queue
- Better for real-time features (unprinted order count, online users)

### Option C: Go + HTMX
- Fast, low-memory
- HTMX for dynamic UI without a JS framework
- sqlx for DB
- Ideal if performance is a priority

### Non-negotiable requirements for rewrite
1. ✅ `password_hash()` / `password_verify()` — not SHA2
2. ✅ Proper migration system with numbered files
3. ✅ CSRF tokens on all forms
4. ✅ Input validation layer (not scattered inline)
5. ✅ Async Telegram sends (queue/job, not blocking HTTP)
6. ✅ Atomic order code generation (DB transaction + UNIQUE + retry on conflict)
7. ✅ One DB driver throughout (not PDO + mysqli mixed)
8. ✅ Separate API layer from page rendering
9. ✅ Environment variables for all secrets (not `config.php` with hardcoded values)
10. ✅ Configurable scanner Telegram chat (not hardcoded)

---

## 18. Summary Count

| Area | Files |
|---|---|
| Admin panel | 128 PHP files |
| Scanner module | 52 PHP files |
| Cashier panel | 9 PHP files |
| Seller panel | 5 PHP files |
| Core (root) | ~12 PHP files |
| **Total PHP files** | **~206** |
| DB tables (approximate) | **~40+** |
| Permission keys | **~80+** |
