# Basebase ↔ Sysfera integration API — v1 (DRAFT)

Contract between **basebase.ru** and the **Warehouse Manager** component of Sysfera Platform installed on an Agent's partner site. Both sides are built against this document. Change it here first, then in code. Status: **Basebase side implemented** (sections 2–4 and 6, plus the outgoing webhooks of section 5). The partner-site endpoints of section 5 are for Sysfera to implement.

## 1. Actors

| Actor | What it is |
|---|---|
| **Agent** | A Basebase user (role `agent`) who runs a Sysfera Platform on their own domain. Has an `AGENT_KEY`. |
| **Partner site** | The Agent's Sysfera install (`https://shop.example.com`) with the Warehouse Manager component. |
| **Seller** | A user registered on the partner site. Has a *managed* Basebase account (a Supplier) created through this API. Belongs to the Agent (`users.agent_id`). |

## 2. Connecting an Agent

1. Warehouse Manager is installed → it generates `AGENT_KEY` (random, 16–80 chars `[A-Za-z0-9_-]`) and shows it to the admin.
2. The Agent enters `AGENT_KEY` + site address in Basebase → **Cabinet → Partner site**. Status becomes `pending`; the Basebase admin is notified.
3. The Basebase admin approves the agent (**Admin → Agents manager**). Basebase generates the per-agent **API secret** (48 hex chars).
4. The Agent sees **Agent ID**, **API address** and **API secret** in **Cabinet → Partner site → API access** (the secret can be regenerated; the old one stops working at once) and enters them in Warehouse Manager.
5. From then on every call is signed (section 3). The `AGENT_KEY` is only an identifier for the human linking step and is never used to sign.

*Planned (not built):* a challenge call `GET {site}/api/basebase/handshake?challenge=…` that proves the site really holds the key before approval.

## 3. Authentication of every request

Headers (both directions):

```
X-BB-Agent:      <agent id on Basebase>
X-BB-Timestamp:  <unix seconds>      reject if older than 5 minutes
X-BB-Signature:  hex(HMAC-SHA256(secret, timestamp + "\n" + METHOD + "\n" + path_with_query + "\n" + body))
```

HTTPS only. JSON bodies (UTF-8). Errors: `{ "error": "code", "message": "…" }` with a proper HTTP status.

## 4. Basebase API (called by the partner site) — base `https://www.basebase.ru/api/v1`

| Method & path | Purpose |
|---|---|
| `GET /ping` | Check credentials → `{ ok, agent_id, agency_fee, platform_commission, time }`. |
| `GET /categories` | Category tree `[{ id, name, children: [{ id, name }] }]` for `category_id` / `category` in products. |
| `POST /sellers` | Create a managed seller account `{ email, name, shop_name, phone, external_id }` → `201 { seller_id, shop_slug, created: true }`. Linked to the calling Agent. Idempotent by `external_id` (`200 … created:false`). `409 email_taken` if the email already has a Basebase account. The seller receives an email with a "set password" link. |
| `GET /agent/wallet` | The Agent's own balance (Agency fee) and last transactions, read-only. |
| `GET /sellers/{seller_id}` | Seller summary + shop URL. |
| `GET /sellers/{seller_id}/profile` | Payout profile: `{ payout_type, types: { self|company|personal: { label, section, fields: [{ id, label, type, required }] } }, values: { type: { "27": "…" } }, missing_required, shop_name }`. Lets an agent site render and prefill the same form as the Basebase cabinet. |
| `PUT /sellers/{seller_id}/profile` | Payout profile `{ payout_type: "self"\|"company"\|"personal", fields: { "27": "Иванов", … } }` (field ids from `database/profile-fields.csv`) → `{ payout_type, missing_required: [labels] }`. Needed before the first withdrawal. |
| `PUT /sellers/{seller_id}/products/{external_id}` | Create/update a product (see 4.1). Idempotent. |
| `DELETE /sellers/{seller_id}/products/{external_id}` | Hide the product (status `draft`). |
| `PATCH /sellers/{seller_id}/products/{external_id}/stock` | `{ stock }` or per-variant `{ variants: [{name, stock, price?}] }`. |
| `GET /sellers/{seller_id}/orders?since_id=…&status=…&limit=…` | Order items of this seller in ascending `item_id` order (see 4.2); poll with the last `item_id` you saw. Max 200. |
| `PATCH /sellers/{seller_id}/orders/{item_id}` | Change item status `{ status }` (`prepare`, `shipped`, `delivered`, …). |
| `GET /sellers/{seller_id}/wallet` | `{ balance, transactions: [...] }`. |
| `POST /sellers/{seller_id}/wallet/withdraw` | `{ amount }` — uses the seller's saved payout profile; same rules as the cabinet. Result `{ status, message, balance }`: `200 sent`; `202 review` (unknown payment error, admin will check); `422` with `profile_incomplete`, `too_small` (min 100 ₽), `insufficient`, `failed`. |
| `GET /sellers/{seller_id}/messages?since_id=…&limit=…` | Messages in and out `{ id, direction: in\|out, peer_user_id, peer_name, product_id, body, created_at }`. |
| `POST /sellers/{seller_id}/messages` | Reply `{ to_user_id, body }` (acts under the seller's shop name). |

### 4.1 Product payload

```json
{
  "title": "…", "description": "…", "category": "Kitchen",
  "price": 2450.00,          // public price
  "promo_price": 1800.00,    // what the seller receives
  "stock": 12,
  "variants": [{ "name": "Blue / M", "stock": 4, "price": 2450.00 }],
  "images": ["https://…/1.jpg"],
  "fields": { "Material": "Ceramic", "Volume": "350 ml" },   // custom fields → shown as specs on the product page
  "sku": "WM-0042"
}
```

Custom fields are stored as `products.specs` (ordered key/value list). Any new key is allowed; Basebase shows them as-is.

### 4.2 Order payload (per item)

```json
{ "order_id": 1055, "item_id": 8841, "status": "pending", "created_at": "…",
  "customer": { "name": "…", "phone": "…", "address": "…", "delivery": "CDEK", "notes": "…" },
  "product": { "external_id": "WM-0042", "title": "…", "variant": "Blue / M" },
  "qty": 2, "price": 2450.00, "seller_receives": 4425.00 }
```

## 5. Partner-site API (called by Basebase) — base `{site}/api/basebase`

| Method & path | Purpose |
|---|---|
| `POST /webhook` | Basebase pushes `{ event_id, event, created_at, data }`. Signed like section 3 (verify it!). Reply `2xx` to acknowledge; otherwise Basebase retries after 2, 4, 8 … minutes, up to 8 attempts. The receiver must be idempotent by `event_id`. Delivery is best-effort — poll `/orders` and `/messages` as a fallback. |
| `GET /handshake` | Planned, see section 2. |

Events (`data` always contains `seller_id`):

| `event` | `data` |
|---|---|
| `order.created` | `order_id, item_id, title, external_id, variant, qty, price, seller_receives, customer{name,phone,address,delivery,notes}` |
| `order.status_changed` | `order_id, item_id, status, previous` |
| `message.received` | `from_user_id, from_name, body, product_id` |
| `wallet.credited` | `amount, type, note, balance` |

## 6. Commission (implemented)

All percentages are of the **margin = price − promo price** per unit.

* `platform total = margin × Platform commission %` (global setting, default 10%).
* With a Blogger: `blogger = margin − platform total`. Without: the seller keeps `margin − platform total`.
* With an Agent: `agency fee = margin × Agency fee %`, capped at the platform total. It is **part of** the platform total (never added on top): `Basebase part = platform total − agency fee`. Seller and blogger receive the same amounts as without an agent.
* Wallet legs on `Delivered` (refs `oi{id}:s` seller, `:r` blogger, `:g` agent, `:a` Basebase). `Returned` reverses all of them.

## 7. Open points (to decide before building the API)

1. Rate limits and maximum payload sizes.
2. ~~Managed sellers' login~~ — decided: they get a "set password" email link and can use basebase.ru directly.
3. ~~Images~~ — decided: pushed as `https` URLs; Basebase downloads and stores them (≤ 5 MB each, max 8, re-downloaded only when the URL list changes).
4. ~~Orders~~ — decided: webhooks plus polling fallback.
5. Versioning: `/api/v1`, breaking changes only in `/api/v2`; Basebase supports the previous major for 12 months.

## Roles and privacy
* **Sysfera Admin** — administrator of the main Sysfera site (core.sysfera.ru) and of Basebase.
* **Agent** — administrator of a Sysfera Core installed on its own domain (the "partner site").
* **User** — anyone registered on a partner site.

Rules: an Agent manages its users (suspend/activate the shop, release) but does **not** see their orders or products in Basebase. The Agent's Dashboard shows only two totals: *Product total* and *Agent's total profit* (Agency fee). Every administrator (Sysfera Admin, Agent) can post and manage their own products. A User can move to another connected site in *Settings → Partner site* (products and balance stay with the account; enter the Seller ID on the new site under Basebase → Connection). Orders already placed keep the Agency fee of the site they were made on. The API (`/sellers/{id}/orders`) is what the user's own connector uses to deliver orders to the warehouse, as before.
