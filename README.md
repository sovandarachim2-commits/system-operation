# LuckyBox — Order & Warehouse Management System

A full-featured PHP web application for managing sales orders, warehouse operations, purchasing, marketing, and finance — built for multi-role teams with Telegram integration and mobile-first UI.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Installation & Database Setup](#2-installation--database-setup)
3. [Configuration](#3-configuration)
4. [Running the Application](#4-running-the-application)
5. [User Roles](#5-user-roles)
6. [Module Overview](#6-module-overview)
7. [Project Structure](#7-project-structure)
8. [RBAC Permission System](#8-rbac-permission-system)
9. [Telegram Integration](#9-telegram-integration)
10. [Scanner Module](#10-scanner-module)
11. [Storage: Local vs Cloudflare R2](#11-storage-local-vs-cloudflare-r2)
12. [Maintenance Mode](#12-maintenance-mode)
13. [Responsiveness & UI](#13-responsiveness--ui)

---

## 1. Requirements

| Requirement | Details |
|---|---|
| **Web Server** | XAMPP (Apache + MySQL/MariaDB + PHP 8.x) |
| **PHP** | 8.0 or higher (PDO + mysqli extensions required) |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ |
| **Browser** | Chrome, Edge, Firefox (mobile or desktop) |

Place the project folder at:

```
c:\xampp\htdocs\LuckyBox (2)\
```

Access it at:

```
http://localhost/LuckyBox%20(2)/
```

> **Tip:** Rename the folder to something without spaces (e.g., `LuckyBox`) for cleaner URLs:
> `http://localhost/LuckyBox/`

---

## 2. Installation & Database Setup

1. Open **phpMyAdmin**: `http://localhost/phpmyadmin`
2. Go to **Import**.
3. Select and import:
   ```
   c:\xampp\htdocs\LuckyBox (2)\schema.sql
   ```
4. Click **Go**.

This creates the `order_system` database with all required tables:

| Tables |
|---|
| `users`, `roles`, `user_roles`, `permissions`, `role_permissions` |
| `products`, `product_sets` |
| `orders`, `order_items`, `print_jobs` |
| `pages`, `delivery_types`, `delivery_costs`, `logos` |
| `purchase_orders`, `purchase_vendors`, `purchase_payments` |
| `marketing_takes`, `marketing_take_items` |
| `storage_locations`, `storage_receipts` |
| `cashflow`, `cashflow_categories` |
| `invoice_settings`, `app_settings` |

A default **admin** user is seeded:
- **Username:** `admin`
- **Password:** `admin123`

> ⚠️ **Change the admin password after first login.**

---

## 3. Configuration

Edit the main config file:

```
config.php
```

### Database

```php
$DB_HOST = 'localhost';
$DB_NAME = 'order_system';
$DB_USER = 'root';
$DB_PASS = '';
```

### Timezone & Base URL

```php
date_default_timezone_set('Asia/Bangkok');

$BASE_URL  = '/LuckyBox';   // Adjust to match your folder name
$DOMAIN    = 'http://localhost/LuckyBox';
```

### Telegram Bot

```php
$TELEGRAM_BOT_TOKEN = '';   // Your BotFather token
$TELEGRAM_CHAT_ID   = '';   // Default group/channel chat ID

// Optional: per-topic / multi-target support
$TELEGRAM_TARGETS = [
    ['chat_id' => '-100xxxxxxxxx', 'thread_id' => null],
    ['chat_id' => '-100yyyyyyyyy', 'thread_id' => 123],
];
```

**To set up Telegram:**
1. Create a bot via [@BotFather](https://t.me/BotFather) and copy the token.
2. Add the bot to your group/channel.
3. Get the chat ID (e.g., via `@userinfobot` or the Telegram API).
4. Paste the values into `config.php`.

If left empty, Telegram sending is gracefully disabled.

### Scanner-Specific Telegram

The scanner module uses a dedicated chat ID, configured in `scanner/config.php`:

```php
define('TELEGRAM_CHAT_ID', '-1002942067666'); // scanner group
```

### Cloudflare R2 (Optional)

```php
$SCANNER_STORAGE_DRIVER       = 'r2';           // 'local' or 'r2'
$SCANNER_R2_ACCOUNT_ID        = '';
$SCANNER_R2_BUCKET            = '';
$SCANNER_R2_ACCESS_KEY_ID     = '';
$SCANNER_R2_SECRET_ACCESS_KEY = '';
$SCANNER_R2_PUBLIC_BASE_URL   = '';
$SCANNER_R2_OBJECT_PREFIX     = 'scanner';
```

---

## 4. Running the Application

1. Start **Apache** and **MySQL** from the XAMPP control panel.
2. Navigate to:
   ```
   http://localhost/LuckyBox/login.php
   ```
3. Log in with `admin` / `admin123`.
4. After login, you are redirected based on your role:

| Role | Landing Page |
|---|---|
| **Admin** | `admin/statistics.php` |
| **Seller** | `seller/statistics.php` |
| **Cashier** | `cashier/dashboard.php` |
| **Scanner** | `scanner/home.php` |

---

## 5. User Roles

| Role | Description |
|---|---|
| **Admin** | Full access to all modules — orders, products, finance, purchasing, marketing, user management, RBAC |
| **Seller** | Create and manage customer orders; view personal sales statistics; send orders to Telegram |
| **Cashier** | View unprinted orders; batch print receipts/cards; view print history and cancelled orders |
| **Scanner** | Warehouse scanning operations — prepare items, dispatch, returns, set preparation |

> Custom roles can be created and assigned granular permissions via the RBAC system (`admin/roles.php`, `admin/role_permissions.php`).

---

## 6. Module Overview

### 📦 Admin Panel

#### Orders
| Page | Purpose |
|---|---|
| `admin/order_management.php` | View and manage all orders |
| `admin/order_filter.php` | Advanced order filtering & export |
| `admin/sold_products.php` | Sold products report |
| `admin/order_edit_audit.php` | Audit log for order edits |
| `admin/create_receipt_order.php` | Create receipt/storage orders |
| `admin/history_receipt.php` | Receipt order history |
| `admin/return_report.php` | Order return report |

#### Statistics & Reports
| Page | Purpose |
|---|---|
| `admin/dashboard.php` | Monthly revenue/order count chart |
| `admin/statistics.php` | Sales statistics with date range filter |
| `admin/daily_summary.php` | Daily order summary |
| `admin/daily_seller.php` | Per-seller daily summary |
| `admin/activity.php` | User activity log viewer |
| `admin/delivery_report.php` | Delivery report |
| `admin/commission_summary.php` | Seller commission summary |

#### Products & Inventory
| Page | Purpose |
|---|---|
| `admin/products.php` | Product list (CRUD) |
| `admin/inventory.php` | Inventory management |
| `admin/inventory_view.php` | Read-only inventory view |
| `admin/stock_operations.php` | Stock in/out operations |
| `admin/product_movement_tracking.php` | Track product movements |
| `admin/stock_dashboard.php` | Stock dashboard |
| `admin/stock_reports.php` | Stock reports |
| `admin/eod_eom_stock_reports.php` | End-of-day / end-of-month reports |
| `admin/manage_categories.php` | Product category management |
| `admin/brands.php` | Brand management |
| `admin/product_costs.php` | Product cost management |
| `admin/cost_history.php` | Cost change history |
| `admin/profit_analysis.php` | Profit analysis |

#### Product Sets & Lucky Box
| Page | Purpose |
|---|---|
| `admin/product_set_management.php` | Manage product sets (bundles) |
| `admin/lucky_box_sets.php` | Mark sets as "Lucky Box" for seller picker |
| `admin/product_set_qr_code_settings.php` | QR code settings for product sets |
| `admin/product_set_qr_labels.php` | Print QR labels for sets |
| `admin/product_set_qr_label_history.php` | QR label print history |
| `admin/product_set_report.php` | Product set report |

#### Marketing Takes
| Page | Purpose |
|---|---|
| `admin/marketing_take_list.php` | List of marketing takes (code: `MT-YYYYMMDD-####`) |
| `admin/marketing_take_create.php` | Create a marketing take |
| `admin/marketing_take_edit.php` | Edit a marketing take |
| `admin/marketing_take_approve.php` | Approve marketing takes |
| `admin/marketing_take_reconcile.php` | Reconcile marketing takes |
| `admin/marketing_take_report.php` | Marketing take report |
| `admin/generate_marketing_invoice.php` | Generate marketing invoice |

#### Purchasing
| Page | Purpose |
|---|---|
| `admin/purchase_orders.php` | Purchase orders (code: `PO-YYYYMMDD-####`) |
| `admin/purchase_vendors.php` | Vendor management |
| `admin/purchase_receiving.php` | Receive incoming purchase orders |
| `admin/purchase_returns.php` | Return purchased items to vendor |
| `admin/purchase_payments.php` | Track purchase payments |
| `admin/purchase_reports.php` | Purchasing reports |

#### Finance
| Page | Purpose |
|---|---|
| `admin/finance_dashboard.php` | Finance overview, top-up management |
| `admin/add_spending.php` / `edit_spending.php` | Spending management |
| `admin/finance_reports.php` | Finance reports |
| `admin/cashflow.php` | Cashflow tracker |
| `admin/cashflow_categories.php` | Cashflow category management |
| `admin/bank_transfer_add.php` / `bank_transfer_history.php` | Bank transfer tracking |
| `admin/payment_methods.php` | Payment method management |
| `admin/payment_management.php` | Payment tracking |
| `admin/topup_report.php` | Top-up report & export |

#### Accountant
| Page | Purpose |
|---|---|
| `admin/accountant_dashboard.php` | Accountant overview |
| `admin/accountant_daily_reports.php` | Daily reports |
| `admin/accountant_product_reports.php` | Product-level reports |
| `admin/accountant_financial_summary.php` | Financial summary |
| `admin/closing_report.php` | Period closing report |

#### System Settings
| Page | Purpose |
|---|---|
| `admin/users.php` | User management (add/edit/delete, assign roles) |
| `admin/roles.php` | Role management |
| `admin/role_permissions.php` | Assign permissions to roles |
| `admin/pages.php` | Facebook page management |
| `admin/delivery_types.php` | Delivery type management |
| `admin/delivery_costs.php` | Delivery cost management |
| `admin/logos.php` | Logo management (receipt branding) |
| `admin/invoice_settings.php` | Invoice/receipt company info |
| `admin/manage_note_options.php` | Order note options |
| `admin/money_exchange.php` | Currency exchange rates |
| `admin/telegram_bot_remider.php` | Telegram bot reminder config |
| `admin/box_units_settings.php` | Box unit settings |
| `maintenance.php` | Toggle maintenance mode |

---

### 🛒 Seller Panel

| Page | Purpose |
|---|---|
| `seller/order_new.php` | Create a new customer order |
| `seller/orders.php` | View personal order history |
| `seller/order_edit.php` | Edit an existing order |
| `seller/statistics.php` | Personal sales statistics |
| `seller/daily.php` | Daily order summary |

**New Order includes:**
- Customer name, phone (validates Cambodian number formats)
- Product selection (catalog + Lucky Box sets)
- Delivery type & cost
- Location, Facebook page
- Payment status (Paid/Unpaid) with note
- Auto-generated order code: `E-SHA-YYYYMMDD####`
- Automatic Telegram notification on save

---

### 🖨️ Cashier Panel

| Page | Purpose |
|---|---|
| `cashier/dashboard.php` | Dashboard showing unprinted order count |
| `cashier/print_orders.php` | Filter & batch print orders |
| `cashier/bulk_print.php` | Bulk print queue |
| `cashier/print_sessions.php` | Print session list |
| `cashier/print_session_report.php` | Per-session print report |
| `cashier/print_history.php` | Full print history |
| `cashier/cancelled_orders.php` | View cancelled orders |
| `cashier/broadcast.php` | Broadcast messages |
| `cashier/inventory.php` | Inventory view |

---

### 📷 Scanner Module (`scanner/`)

Mobile warehouse scanning app. Available in Khmer/English.

| Feature | Files |
|---|---|
| **Home Dashboard** | `home.php` |
| **Prepare Items** (វេចខ្ចប់ឥវ៉ាន់) | `prepare_items.php`, `get_prepare_items.php`, `save_prepare_items.php`, `edit_prepare_items.php`, `delete_prepare_items.php`, `view_prepare_items.php` |
| **Out Items** (ដាក់ឥរ៉ាន់ចេញ) | `out_items.php`, `get_out_items.php`, `save_out_items.php`, `edit_out_items.php`, `delete_out_items.php`, `view_out_items.php` |
| **Prepare Sets** (រៀបចំឥវ៉ាន់Set) | `prepare_set.php`, `get_prepare_set.php`, `save_prepare_set.php`, `edit_prepare_set.php`, `delete_prepare_set.php`, `view_prepare_set.php`, `lookup_set_by_qr.php` |
| **Return Items** (Returnឥវ៉ាន់) | `return_items.php`, `get_return_items.php`, `save_return_items.php`, `edit_return_items.php`, `delete_return_items.php`, `view_return_items.php` |
| **Confirm** | `comfirm.php`, `save_confirm.php`, `get_confirm_data.php`, `view_confirm_items.php`, `delete_confirm.php` |
| **Delivery** | `view_pay_delivery.php`, `get_pay_delivery.php`, `get_amount_delivery.php`, `view_amount_delivery.php`, `get_delivery_by_barcode.php` |
| **Reports** | `report_menu.php`, `sourcedata.php`, `getsourcedata.php`, `view_status.php`, `get_status.php` |
| **All Items View** | `view_all_items.php`, `get_all_items.php` |
| **Upload to R2** | `upload_local_uploads_to_r2.php` |
| **Cron** | `cron_send_status.php` |

---

## 7. Project Structure

```
LuckyBox (2)/
├── config.php                  # DB, Telegram, Base URL, timezone, R2 settings
├── db.php                      # PDO connection helper (get_db_connection())
├── auth.php                    # Session, RBAC, require_login(), require_role(), has_permission()
├── helpers.php                 # Utility functions: Telegram, order codes, receipt display
├── user_activity_lib.php       # Page-view and action activity logging
├── logout.php                  # Session destroy + redirect
├── auth.php                    # Login handler & session start
├── receipt.php                 # Printable receipt view
├── maintenance.php             # Maintenance mode toggle (admin only)
│
├── layout/
│   ├── header.php              # Bootstrap navbar + sidebar (admin pages)
│   └── footer.php              # Scripts + layout end
│
├── public/
│   ├── css/app.css             # Custom responsive styles
│   ├── js/admin-shell.js       # Admin SPA-like navigation shell
│   └── image.png               # Default logo/image
│
├── admin/                      # Admin panel pages (see Module Overview)
├── seller/                     # Seller order creation & history
├── cashier/                    # Cashier print queue & dashboard
└── scanner/                    # Warehouse scanning module
    ├── config.php              # Scanner-specific config (extends main config.php)
    ├── storage.php             # Storage driver abstraction (local / R2)
    ├── api_config.php          # API configuration for scanner
    ├── send_telegram.php       # Scanner Telegram sending
    ├── current_user.php        # Current user API for scanner JS
    ├── data_base.db            # SQLite (legacy/local cache, scanner use)
    ├── uploads/                # Locally uploaded scanner images
    └── out_items_config/
        └── out_items_delivery_by.php
```

---

## 8. RBAC Permission System

The system supports both **legacy single-role** and **multi-role RBAC**:

- **Legacy mode**: Each user has one role (`admin`, `seller`, `cashier`, `scanner`) stored in `users.role`.
- **RBAC mode**: Roles are defined in the `roles` table; users can have multiple roles via `user_roles`; permissions are stored in `permissions` and assigned via `role_permissions`.

RBAC is automatically detected — if the `permissions` and `role_permissions` tables exist, RBAC mode activates.

### Key Auth Functions

| Function | Description |
|---|---|
| `require_login()` | Enforce session, maintenance mode check, update last-seen |
| `require_role(array $roles)` | Restrict to specific roles (falls back to permission check) |
| `require_permission(string $permKey)` | Restrict to a specific permission key |
| `require_role_or_permission(array $roles, ...$permKeys)` | Allow by role OR permission |
| `has_permission(string $permKey)` | Check current user has a permission |
| `current_user()` | Get current logged-in user record |

### Permission Key Format

Permissions follow a `resource.action` convention:

```
orders.view         statistics.view       inventory.view
marketing_take.view purchase_orders.view  finance_dashboard.view
scanner_home.view   print_orders.view     cashier_date.view
users.view          role_permissions.view maintenance.view
```

---

## 9. Telegram Integration

Orders are sent to Telegram automatically when created or updated.

### Features
- **New order message**: code, seller, customer, phone, location, page, delivery, items, total, status
- **Order update message**: reply to original message thread
- **Cancellation message**: replies to original, includes reason
- **Per-seller overrides**: each user can have their own `telegram_chat_id` and `telegram_thread_id`
- **Multi-target**: `$TELEGRAM_TARGETS` array sends to multiple groups/topics
- **Topic fallback**: if a thread ID is invalid, retries to the group root

### Lucky Box on Telegram

Lucky Box order items are merged into a single line:
```
- Lucky box x 3 = $45.00
  Order code: E-SHA-20260527001
  · Product A · QTY 2
  · Product B · QTY 1
```

---

## 10. Scanner Module

The scanner module is a separate mobile-first web app at `/scanner/`.

### Roles with scanner access
`admin`, `cashier`, `scanner`, or users with `scanner_home.view` permission.

### Database connections
- Uses **mysqli** (in addition to the main PDO connection) for compatibility with scanner API scripts.
- Shares the same `order_system` database.

### Image uploads
- Default: stored in `scanner/uploads/YYYY/MM/`
- Optional: Cloudflare R2 (set `SCANNER_STORAGE_DRIVER = 'r2'`)

### Cron jobs
`scanner/cron_send_status.php` — scheduled to send delivery status reports via Telegram.

---

## 11. Storage: Local vs Cloudflare R2

Scanner image uploads support two drivers:

| Driver | Config | Storage path |
|---|---|---|
| `local` | `$SCANNER_STORAGE_DRIVER = 'local'` | `scanner/uploads/YYYY/MM/` |
| `r2` | `$SCANNER_STORAGE_DRIVER = 'r2'` | Cloudflare R2 bucket with prefix |

When using R2, images are served from `$SCANNER_R2_PUBLIC_BASE_URL`.

To migrate existing local uploads to R2, run:
```
http://localhost/LuckyBox/scanner/upload_local_uploads_to_r2.php
```

---

## 12. Maintenance Mode

Maintenance mode is toggled by creating/deleting the file:

```
.maintenance        (at project root)
```

- **When active**: all users see the maintenance page (`maintenance.html`) except those with the `maintenance_bypass.view` permission (or the `admin` username in legacy mode).
- **Admin access**: `maintenance.php` provides a toggle UI.

---

## 13. Responsiveness & UI

- **Bootstrap 5.3** + **Bootstrap Icons 1.11**
- **Mobile-first** layouts; large buttons (`btn-lg`) and inputs for touch screens
- **Admin sidebar** collapses to hamburger on small screens
- **Scanner module**: dark theme (`#232323` background, `#fdb04c` gold accent), full-screen button layout optimized for warehouse use on phones
- **Admin shell** (`public/js/admin-shell.js`): SPA-like navigation; handles session expiry with a JSON redirect response instead of HTML redirect loops
- **User online presence**: `last_seen_at` updated every 90 seconds; users are shown as "online" within a 5-minute window

---

## Quick Reference

| Item | Value |
|---|---|
| Default admin login | `admin` / `admin123` |
| Order code format | `E-SHA-YYYYMMDD####` |
| Marketing take code | `MT-YYYYMMDD-####` |
| Purchase order code | `PO-YYYYMMDD-####` |
| Default timezone | `Asia/Bangkok` |
| Scanner Telegram chat | `-1002942067666` |
| Online threshold | 5 minutes (`last_seen_at`) |
| Heartbeat interval | 90 seconds |
