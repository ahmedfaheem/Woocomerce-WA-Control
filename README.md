# WA Meta Control (`v3.0.0`)

Unified WhatsApp automation & CRM plugin for WordPress and WooCommerce powered directly by the **Meta WhatsApp Cloud API** (no third-party BSP required).

**Author:** [Ahmed Faheem](https://www.facebook.com/a7medfaheem)  
**License:** Licensed by Ahmed Faheem — All Rights Reserved

---

## 📋 Requirements

| Requirement | Minimum Version |
| :--- | :--- |
| **WordPress** | 5.8+ |
| **PHP** | 7.4+ |
| **WooCommerce** | 5.0+ (Full **HPOS** Custom Order Tables compatibility) |
| **Meta Account** | WhatsApp Business Account (WABA) + System User Access Token |

---

## ✨ Core Features

### 1. 🤖 Smart 24-Hour Window Routing (`WAMC_Dispatcher` & `WAMC_Window_Tracker`)
Every outgoing message automatically checks the recipient's 24-hour customer service window (tracked by the last 9 digits of their phone number):
- **🟢 Window OPEN** (customer messaged within the last 24h): Sends **Plain Text** or **Interactive Reply Buttons** (free of template charges).
- **🔒 Window CLOSED / UNKNOWN**: Automatically routes through your **Approved Meta Template** with ordered `{{1}}`, `{{2}}` body variables and Quick-Reply button payloads.
- **Mandatory Dual-Config Validation**: Prevents enabling notifications unless both Plain Text and Approved Template formats are configured.

### 2. ✅ Order Confirmation via WhatsApp (`WAMC_Confirm_Control`)
- **Multi-Trigger Detection**: Works with Classic Checkout, WooCommerce Store API (Blocks), REST API orders, Cash on Delivery (COD), manual Admin orders, and status transitions back to `pending`/`on-hold`.
- **Interactive Button Confirmation**:
  - **Open Window**: Sends plain text with interactive reply buttons (`wamc_confirm:{order_id}`, `wamc_cancel:{order_id}`).
  - **Closed Window**: Sends an approved template with 2 Quick-Reply buttons carrying the exact Order ID payload.
- **Automatic Status Updates**: Updates order status to your configured **Confirmed Status** (default: `processing`) or **Cancelled Status** (default: `cancelled`) and records `order_status_changed_by` meta (`WhatsApp-Confirm` / `WhatsApp-Cancel`).
- **Smart Auto-Resend**: If a customer with a pending order replies with unrecognized text instead of tapping a button, the confirmation prompt is re-sent (with a 10-minute cooldown to prevent loops).

### 3. 🔔 Order Status Notifications (`WAMC_Order_Notify`)
- **Per-Status Rules**: Enable/disable WhatsApp notifications for every registered WooCommerce order status (`pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, plus custom statuses).
- **Dual Format per Status**: Configure both Plain Text (for open 24h windows) and Template Name + Language + Variable mapping (for closed windows).
- **Duplicate Guard**: 30-second transient lock prevents duplicate firing on rapid status hooks.
- **Admin Notifications**: Optional notifications to admin phone numbers or a WhatsApp Group.

### 4. 💬 Per-Order CRM Chat & Real-Time Notifications (`WAMC_CRM` & `WAMC_SSE`)
- **Order Editor Chat Box**: Full WhatsApp conversation view inside the WooCommerce Order Edit screen (supports both legacy `shop_order` and HPOS `wc-orders`).
- **Live 24h Window Badge**: Displays whether the 24h window with the customer is currently `🟢 Open` (free text) or `🔒 Closed` (automatically wraps your message in the configured **Fallback Template**).
- **Sender Attribution**: Tracks and displays which WordPress admin user sent each message.
- **Real-Time Delivery**:
  - **Pusher Channels WebSocket**: Zero-polling instant chat updates and floating notification badges when Pusher credentials are configured.
  - **SSE Endpoint**: Built-in Server-Sent Events stream (`GET /wp-json/wamc/v1/stream/{order_id}`) for real-time updates on standard hosting.
- **Floating Unread Notification Widget**: Displays a floating WhatsApp badge on the WooCommerce Orders list showing unread customer replies grouped by order.

### 5. 🚚 QP / Shipping Company Notifications (`WAMC_QP_Notify`)
- **Order Editor Meta Box**: Dedicated sidebar box in the order editor to send instructions directly to the shipping company.
- **Waybill Verification**: Displays the `QpOrderNumber` meta value and blocks sending if the order is not yet registered with the shipping company.
- **Flexible Targets**: Send to multiple **Phone Numbers** (routed via 24h Dispatcher) or directly to a **WhatsApp Group ID**.
- **Rich Context**: Automatically appends store order number, `QpOrderNumber` waybill, customer name, customer phone, order total, and admin sender name.

### 6. 📢 Smart Promotional Bulk Sender (`WAMC_Bulk_Send`)
- **Orders Table Integration**: Adds a `"📢 إرسال عرض ترويجي للطلبات المحددة"` button directly to the WooCommerce Orders list toolbar.
- **Zero Server Blocking**: Sends the first message immediately and schedules remaining messages in the background via staggered `WP-Cron` jobs (`wamc_send_single_promo`) — no `sleep()` calls blocking PHP workers.
- **Campaign Tracking & Deduplication**:
  - Assigns a unique **Campaign ID** (e.g., `promo_2026_11_09`) stored in `_wamc_promo_campaigns` order meta so customers never receive the same campaign twice.
  - Automatically deduplicates repeated phone numbers within the same selection.
- **A/B Message Variation**: Supports two message variants (`msg1` and `msg2`) alternated across recipients.
- **Auto Opt-Out Button**: Appends an interactive `"إيقاف العروض"` button when the 24h window is open, and listens for opt-out keywords (`ايقاف`, `إيقاف العروض`, `الغاء الاشتراك`, `stop`, `unsubscribe`) to automatically blacklist the number from future promos.

### 7. 🛡️ Anti-Ban & Rate Limiter (`WAMC_Rate_Limiter`)
- **Random Human-Like Delay**: Configurable min/max delay (in seconds) between consecutive bulk messages.
- **Daily Message Cap**: Automatically blocks bulk sends once the daily threshold is reached (resets at midnight) with a live progress bar in settings.
- **Per-Recipient Cooldown**: Prevents re-messaging the same phone number within `N` hours (bypassed for admin/shipping alerts).
- **Automatic Blacklist**: Automatically blacklists numbers that return permanent delivery errors (`ERROR`, `NOT FOUND`) or request Opt-Out, with one-click removal in the settings UI.

### 8. 📊 WA Meta Dashboard (`WAMC_Admin_Page`)
Located under **WooCommerce → WA Meta Dashboard**:
- **📤 Test Message Tab**:
  - Send test messages to any Phone Number or Group ID.
  - Inspect or simulate opening the 24h window (`🔍 فحص النافذة` / `⚡ فتح النافذة (للتجربة)`).
  - View raw JSON responses from Meta Graph API.
- **📋 Message Log Tab**:
  - Ring-buffer log (up to 500 entries) of **Sent** and **Received** messages.
  - Displays recipient phone, linked Order ID, 24h Window status (`🟢 Open` / `🔴 Closed`), routing mode (`📱 Plain Text` / `🔒 Template`), sender admin user, live webhook delivery status (`sent`, `delivered`, `read`, `failed`, `skipped`), and detailed Meta error codes.
  - Filter and bulk-delete logs by **Date Range** or clear all logs.
- **🔌 Service Info Tab**:
  - Live query to Meta Graph API displaying Verified Business Name, Display Phone Number, Quality Rating, Messaging Limit Tier, Code Verification Status, and Webhook URL status.
- **👥 WhatsApp Groups Tab**:
  - Create new WhatsApp Groups via Meta Cloud Groups API (`POST /{phone_number_id}/groups`).
  - Automatically fetch Group ID and Invite Link (`POST /{group_id}/invite_link`).
  - Optionally send automatic WhatsApp invitations with the group link to a list of phone numbers upon creation.
  - View and copy saved Group IDs and invite links.

---

## ⚙️ Configuration Options (`WooCommerce → WA Meta Control`)

All settings are stored in the `wamc_settings` WordPress option.

### 🔑 API Connection
| Option Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `api_key` | Access Token (System User) | `""` | Permanent System User Bearer token from Meta Business Settings. Includes **Test Connection** button. |
| *(auto)* | Webhook URL | Auto-generated | `/wp-json/wamc/v1/webhook/{48-char-random-slug}`. Includes **Copy** and **Register at Meta** buttons. |
| `phone_number_id` | Phone Number ID | `""` | WhatsApp Business Phone Number ID from Meta Developer Dashboard. |
| `waba_id` | WhatsApp Business Account ID | `""` | WABA ID used when subscribing the app to webhook events. |
| `app_id` | Meta App ID | `""` | Required for one-click automatic webhook registration. |
| `app_secret` | Meta App Secret | `""` | Used to validate `X-Hub-Signature-256` HMAC-SHA256 webhook signatures and register webhooks. |
| `verify_token` | Webhook Verify Token | Random 24-char | Handshake token verified during Meta's `GET hub.challenge` webhook setup. |
| `api_version` | Graph API Version | `v21.0` | Meta Graph API version (e.g., `v21.0`). |
| `default_country_code` | Default Country Code | `20` | Prepended to local phone numbers starting with `0` (e.g., `20` for Egypt). |
| `template_name` | Fallback Template Name | `""` | Approved Utility template used when sending messages from Order Chat while the 24h window is closed. |
| `template_lang` | Fallback Template Language | `ar` | Language code for the fallback template (e.g., `ar`, `en_US`). |
| `template_vars` | Fallback Template Variables | `{customer_name}, {order_id}, {message}` | Comma-separated variable mapping for the fallback chat template. |

### ⚡ Real-Time Chat WebSocket (Pusher Channels)
| Option Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `pusher_app_id` | Pusher App ID | `""` | Pusher Channels App ID for zero-polling real-time order chat. |
| `pusher_key` | Pusher Key | `""` | Pusher public key used by the admin browser client. |
| `pusher_secret` | Pusher Secret | `""` | Pusher secret key used to sign server-side events on webhook arrival. |
| `pusher_cluster` | Pusher Cluster | `eu` | Pusher cluster code (`eu`, `ap1`, `us2`, `mt1`, etc.). |

### ⚙️ Active Features (Toggles)
| Option Key | Feature | Default |
| :--- | :--- | :--- |
| `enable_confirm` | Order Confirmation via WhatsApp Reply | `1` (Enabled) |
| `enable_crm` | Per-Order CRM Chat (WhatsApp conversation in order editor) | `1` (Enabled) |
| `enable_qp_notify` | QP / Shipping Company Notifications | `1` (Enabled) |
| `enable_order_notify` | Order Notifications (on creation / on status change) | `1` (Enabled) |
| `enable_bulk` | Bulk Message Sender (from orders list) | `1` (Enabled) |

### ✅ Order Confirmation Settings
| Option Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `confirm_message` | نص الرسالة (Plain Text) | `""` | Message body sent when the 24h window is open. |
| `confirm_btn_label` | نص زر التأكيد | `تأكيد الطلب` | Interactive Confirm button title (max 20 chars). |
| `cancel_btn_label` | نص زر الإلغاء | `إلغاء الطلب` | Interactive Cancel button title (max 20 chars). |
| `confirm_template` | اسم القالب (Template) | `""` | Approved Meta template with 2 Quick-Reply buttons (used when 24h window is closed). |
| `confirm_template_lang` | لغة القالب | `ar` | Template language code. |
| `confirm_vars` | متغيرات القالب | `customer_name,order_id,status,total` | Ordered comma-separated variables filling `{{1}}`, `{{2}}`, etc. |
| `confirm_status` | حالة الطلب عند التأكيد | `processing` | Target WooCommerce status when customer taps Confirm. |
| `cancel_status` | حالة الطلب عند الإلغاء | `cancelled` | Target WooCommerce status when customer taps Cancel. |

### 🔔 Order Status Notifications (`status_rules[{status}]`)
For every WooCommerce order status (`pending`, `processing`, `on-hold`, `completed`, `cancelled`, etc.):
- `enabled`: Enable/disable notifications for this status (`1` / `0`).
- `message`: Plain text message when the 24h window is open.
- `template`: Approved Meta template name when the 24h window is closed.
- `lang`: Template language code (default: `ar`).
- `vars`: Ordered template variables (default: `customer_name,order_id,status,total`).

### 🚚 QP / Shipping Company Notifications
| Option Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `qp_notify_mode` | Send To | `phone` | `phone` (Phone Numbers) or `group` (WhatsApp Group ID). |
| `qp_phones` | Phone Numbers | `""` | One phone number per line with country code. |
| `qp_group_id` | WhatsApp Group ID | `""` | Target Group ID (e.g., `120363XXXXXXXXXX@g.us`). |
| `qp_template` | اسم القالب | `""` | Template used when sending to phone numbers outside the 24h window. |
| `qp_template_lang` | لغة القالب | `ar` | Language code for the QP template. |
| `qp_template_vars` | متغيرات القالب | `""` | Comma-separated variables (defaults to sending the full QP message as `{{1}}`). |

### 🛡️ Anti-Ban Rules
| Option Key | UI Label | Default | Description |
| :--- | :--- | :--- | :--- |
| `delay_min` | تأخير عشوائي (من) | `15` | Minimum random delay (seconds) between scheduled bulk messages. |
| `delay_max` | تأخير عشوائي (إلى) | `45` | Maximum random delay (seconds) between scheduled bulk messages. |
| `enable_daily_limit` | تفعيل حد الرسائل اليومي | `1` | Enforce maximum outgoing messages per day. |
| `daily_limit` | حد الرسائل اليومي | `150` | Maximum messages allowed per day before blocking bulk sends. |
| `enable_cooldown` | تفعيل تبريد لكل مستلم | `1` | Prevent re-messaging the same recipient within the cooldown period. |
| `cooldown_hours` | ساعات التبريد | `24` | Hours to wait before sending another message to the same phone number. |
| `enable_blacklist` | تفعيل القائمة السوداء | `1` | Skip numbers that failed delivery (`NOT FOUND` / `ERROR`) or opted out. |

---

## 🧩 Available Template & Message Placeholders

Use `{placeholder}` in Plain Text messages, or list the variable names (comma-separated) in Template Variables fields to map to `{{1}}`, `{{2}}`, `{{3}}` in order:

| Placeholder | Description |
| :--- | :--- |
| `{customer_name}` | Customer's full billing name (`first_name` + `last_name`) |
| `{first_name}` | Customer's billing first name |
| `{order_id}` | WooCommerce order number |
| `{total}` | Formatted order total with currency (HTML stripped) |
| `{status}` | Localized WooCommerce order status label |
| `{items}` | Order items formatted as `Product Name × Qty` joined by ` \| ` |
| `{items_priced}` | Order items with price: `Product Name × Qty = Price` joined by ` \| ` |
| `{items_count}` | Total quantity of items in the order |
| `{payment_method}` | Payment method title |
| `{order_url}` | Customer order view URL |
| `{site_name}` | WordPress site name |
| `{qp_order_number}` | Shipping waybill number from `QpOrderNumber` order meta |
| `{shipper_phone}` | Courier phone number from `shipper_phone` order meta |
| `{customer_note}` | Customer note attached to the order |

**Additional Bulk Sender Placeholders:**
- `{name}`: Customer full name
- `{products}`: Order product names joined by ` - `
- `{order_id}`: Order ID
- `{total}`: Order total + currency
- `{offer}`: Full rendered promotional message (for template variable mapping)

---

## 🔌 REST API & Webhook Endpoints

| Method | Endpoint | Auth | Purpose |
| :--- | :--- | :--- | :--- |
| `GET` | `/wp-json/wamc/v1/webhook/{slug}` | `hub.verify_token` | Meta webhook verification challenge handshake. |
| `POST` | `/wp-json/wamc/v1/webhook/{slug}` | `X-Hub-Signature-256` | Receives incoming WhatsApp messages, button taps, delivery statuses, and group lifecycle events. |
| `GET` | `/wp-json/wamc/v1/stream/{order_id}` | `manage_woocommerce` | Server-Sent Events (SSE) stream for live order chat updates. |

---

## 🗂️ File Structure

```text
wa-meta-control/
├── wa-meta-control.php                 # Plugin bootstrap, constants, activation hook, helpers
├── assets/
│   ├── wamc-admin.css                  # Admin settings & dashboard styles
│   └── wamc-admin.js                   # Admin UI interactions
└── includes/
    ├── class-admin-page.php            # WA Meta Dashboard (Test Sender, Logs, Service Info, Groups)
    ├── class-bulk-sender.php           # Promotional Bulk Sender & WP-Cron background scheduler
    ├── class-cache.php                 # Order & conversation caching layer
    ├── class-dispatcher.php            # Central 24h window router (Plain Text vs. Template)
    ├── class-message-log.php           # Ring-buffer message logger & date-range cleaner
    ├── class-meta-client.php           # Direct Meta Graph API HTTP client
    ├── class-order-chat.php            # Per-Order CRM Chat metabox, Pusher integration & notifications DB
    ├── class-order-confirmation.php    # Webhook endpoint & interactive order confirmation/cancellation
    ├── class-order-lookup.php          # HPOS-compatible phone-to-order lookup utility
    ├── class-order-notify.php          # Automated order creation & status change notifications
    ├── class-rate-limiter.php          # Anti-ban rate limiting, daily cap, cooldown & blacklist
    ├── class-settings.php              # WooCommerce -> WA Meta Control settings page
    ├── class-shipping-notify.php       # QP / Shipping company order metabox & notifications
    ├── class-sse.php                   # Server-Sent Events endpoint for real-time chat
    └── class-window-tracker.php        # Per-phone 24-hour customer service window tracker
```

---

## 👨‍💻 Author & License

- **Author:** [Ahmed Faheem](https://www.facebook.com/a7medfaheem)
- **Copyright:** © 2026 Ahmed Faheem. All Rights Reserved.
- **License:** Proprietary License — Licensed by **Ahmed Faheem**. Unauthorized copying, redistribution, or modification of this software without prior written permission from the author is strictly prohibited.


