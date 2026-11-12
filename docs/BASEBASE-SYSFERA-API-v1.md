# Basebase ↔ Sysfera integration API — v1 (DRAFT)

Contract between **basebase.ru** and the **Warehouse Manager** component of Sysfera Platform installed on an Agent's partner site. Both sides are built against this document. Change it here first, then in code. Status: draft — nothing below is implemented in Basebase yet except the Agent role, the Agency fee and the commission split (section 6).

## 1. Actors

| Actor | What it is |
|---|---|
| **Agent** | A Basebase user (role `agent`) who runs a Sysfera Platform on their own domain. Has an `AGENT_KEY`. |
| **Partner site** | The Agent's Sysfera install (`https://shop.example.com`) with the Warehouse Manager component. |
| **Seller** | A user registered on the partner site. Has a *managed* Basebase account (a Supplier) created through this API. Belongs to the Agent (`users.agent_id`). |

## 2. Connecting an Agent (handshake)

1. Warehouse Manager is installed → it generates `AGENT_KEY` (random, 32+ chars `[A-Za-z0-9_-]`) and shows it to the admin.
2. The Agent enters `AGENT_KEY` + site address in Basebase → **Cabinet → Partner site**. Status becomes `pending`.
3. Basebase calls `GET {site}/api/basebase/handshake?challenge={random}` and expects `{ "agent_key_hash": sha256(AGENT_KEY + challenge) }`. A match proves the site really holds the key.
4. Basebase generates a per-agent **signing secret** and returns it in the next handshake step; both sides store it. Status → `active` after the Basebase admin approves.
5. From then on every call is signed (section 3). The raw `AGENT_KEY` is never sent again.

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
| `POST /sellers` | Create a managed seller account `{ email, name, shop_name, phone, external_id }` → `{ seller_id, shop_slug }`. Linked to the calling Agent. Idempotent by `external_id`. |
| `PUT /sellers/{seller_id}/products/{external_id}` | Create/update a product (see 4.1). Idempotent. |
| `DELETE /sellers/{seller_id}/products/{external_id}` | Hide the product (status `draft`). |
| `PATCH /sellers/{seller_id}/products/{external_id}/stock` | `{ stock }` or per-variant `{ variants: [{name, stock, price?}] }`. |
| `GET /sellers/{seller_id}/orders?since=…&status=…` | Orders containing this seller's items (see 4.2). |
| `PATCH /sellers/{seller_id}/orders/{item_id}` | Change item status `{ status }` (`prepare`, `shipped`, `delivered`, …). |
| `GET /sellers/{seller_id}/wallet` | `{ balance, transactions: [...] }`. |
| `POST /sellers/{seller_id}/wallet/withdraw` | `{ amount }` — uses the seller's saved payout profile; same rules as the cabinet (minimum 100 ₽, automatic payout, unknown error → admin review). |
| `GET /sellers/{seller_id}/messages?since=…` | Read messages (threads and bodies). |
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
| `GET /handshake` | Section 2. |
| `POST /webhook` | Basebase pushes events: `order.created`, `order.status_changed`, `message.received`, `wallet.credited`. Signed like section 3. Retried with back-off; the receiver must be idempotent (`event_id`). |

## 6. Commission (implemented)

All percentages are of the **margin = price − promo price** per unit.

* `platform total = margin × Platform commission %` (global setting, default 10%).
* With a Blogger: `blogger = margin − platform total`. Without: the seller keeps `margin − platform total`.
* With an Agent: `agency fee = margin × Agency fee %`, capped at the platform total. It is **part of** the platform total (never added on top): `Basebase part = platform total − agency fee`. Seller and blogger receive the same amounts as without an agent.
* Wallet legs on `Delivered` (refs `oi{id}:s` seller, `:r` blogger, `:g` agent, `:a` Basebase). `Returned` reverses all of them.

## 7. Open points (to decide before building the API)

1. Rate limits and maximum payload sizes.
2. Do managed sellers ever get a password / can they log in to Basebase directly? (Proposal: yes, through a "set password" email link, same as guest accounts.)
3. Images: pushed as URLs (Basebase downloads and stores them) — confirm.
4. Orders: webhook only, or webhook + polling fallback? (Proposal: both.)
5. Versioning: `/api/v1`, breaking changes only in `/api/v2`; Basebase supports the previous major for 12 months.
