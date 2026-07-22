# Omnis Solutio Magento 2 Extension — Complete Development Plan

> **Context:** Omnis Solutio IS the payment gateway (Razorpay-style). This extension is the official Magento 2 plugin your merchants will `composer require`. Payment happens in your hosted iframe/modal (card data never touches the merchant's server → merchants stay in PCI SAQ-A scope). Your server confirms payment via callback + webhook.
>
> **Dev environment:** markshust/docker-magento, Magento 2.4.7-p3, module already scaffolded and symlinked via path repository at `/var/www/html/modules/omnis-solutio-payment-gateway`. Phases 1–2 (scaffold + composer wiring) are DONE. DO NOT TOUCH THE universal_js_sdk and wordpress_plugin FILES, THEY ARE FOR REFERENCE ONLY.

---

## 0. The payment flow you're building (agree on this first)

This is the Razorpay-pattern flow. Every phase below implements a piece of it.

```
CHECKOUT (frontend)
 1. Customer reaches payment step, selects "Omnis Solutio"
 2. Customer clicks "Place Order"
 3. JS calls Magento backend  ──►  Magento calls Omnis Solutio API:
                                    POST /orders  (amount, currency, receipt=quote_id)
                                    ◄── returns gateway_order_id
 4. JS opens your checkout iframe/modal with gateway_order_id + merchant key_id
 5. Customer pays inside YOUR iframe (your existing SDK, same one WP used)
 6. Iframe success handler returns { payment_id, order_id, signature } to page JS

SERVER-SIDE CONFIRMATION (two independent paths — BOTH must work)
 7a. CALLBACK path: JS posts payment_id+signature to Magento →
     Magento verifies HMAC signature → places/updates the order → success page
 7b. WEBHOOK path (safety net): your gateway POSTs payment.captured /
     payment.failed to /omnissolutio/webhook → verify webhook signature →
     reconcile order state (handles closed-browser / dropped-connection cases)

ADMIN (backoffice)
 8. Refund from credit memo → Magento calls your Refund API
 9. (If you support auth/capture) Capture & Void from the order view
```

**Decision to lock in now:** *order-first* vs *payment-first*.
- **Order-first (recommended, what Razorpay does):** Magento order is created in `pending_payment` state *before* the iframe opens, then moved to `processing` on confirmation. Survives browser crashes; webhook can always find the order.
- Payment-first (order created only after payment succeeds) seems cleaner but loses orders when the success callback never arrives. Avoid.

---

## Phase A — Merchant configuration (admin panel)

**Goal:** A merchant installs the module and can configure it under
**Stores → Configuration → Sales → Payment Methods → Omnis Solutio**.

Files:
```
etc/adminhtml/system.xml    ← the config form
etc/config.xml              ← defaults
etc/acl.xml                 ← admin permission for the config section
```

Config fields (mirror what your WP plugin asks for):
- `active` (yes/no)
- `title` (what the customer sees at checkout, default "Omnis Solutio")
- `environment` (sandbox / live)
- `key_id` (public — goes to the frontend iframe)
- `key_secret` (private — **backend_model:** `Magento\Config\Model\Config\Backend\Encrypted` so it's encrypted at rest)
- `webhook_secret` (encrypted, used to verify webhook HMAC)
- `payment_action` (`authorize_capture` default; add `authorize` only if your API supports auth-then-capture)
- `order_status` after payment (default `processing`)
- `allowspecific` / `specificcountry`, `min_order_total`, `max_order_total`
- `debug` (verbose logging toggle)

In `config.xml`, set `<model>OmnisSolutioPaymentGatewayFacade</model>` (virtual type, Phase B), `<is_gateway>1</is_gateway>`, `<can_refund>1</can_refund>`, etc.

**Done when:** config saves, secret shows as `******`, and `bin/magento config:show payment/omnissolutio` returns your values.

---

## Phase B — Payment method via the Gateway Command pattern

Use `Magento\Payment\Gateway\*` (the modern command-pattern architecture used by Braintree/Adyen), **not** the deprecated `AbstractMethod`. As a gateway vendor shipping to the public, this matters for Marketplace review and credibility.

Everything is wired in `etc/di.xml` as virtual types — you write small single-purpose classes:

```
etc/di.xml                              ← wiring (facade, command pool, value handlers)
Gateway/Config/Config.php               ← typed access to Phase A config
Gateway/Http/Client/ApiClient.php       ← thin transport (uses Model/Api/Client)
Gateway/Request/RefundRequestBuilder.php
Gateway/Response/RefundHandler.php
Gateway/Validator/ResponseValidator.php
Gateway/Command/ (only if custom commands needed)
```

Commands to register in the command pool:
- `initialize` or `order` — creates the gateway order (step 3 of the flow)
- `capture` — for the iframe flow this is mostly a *record-keeping* command: the charge already happened in the iframe; capture command verifies the payment_id server-side (fetch payment from your API, check amount + status) and sets `last_trans_id`
- `refund` — calls your Refund API (Phase F)
- `void` / `cancel` — cancel unpaid gateway order

**Critical server-side check inside capture/verify:** fetch the payment from YOUR API by `payment_id` and validate **amount, currency, and gateway_order_id match the Magento order**. Never trust what the browser posted.

**Done when:** `bin/magento module:status` clean, method instantiates without error, placing an order with a stubbed client transitions order state correctly.

---

## Phase C — Server-side API client (PHP SDK layer)

Your WP plugin leaned on your Node SDK tooling. In Magento you have two options:

1. **If you have a PHP SDK** (like `razorpay/razorpay-php`): add it to the module's `composer.json` `require`. Merchants get it automatically.
2. **If you don't:** write a thin client inside the module using `Magento\Framework\HTTP\Client\Curl` or Guzzle (already in Magento's vendor). Honestly recommended even if an SDK exists — fewer dependency conflicts across merchant installs.

```
Model/Api/Client.php          ← createOrder(), fetchPayment(), refund(), auth headers
Model/Api/UrlProvider.php     ← sandbox vs live base URL from config
Logger/Logger.php + Handler   ← dedicated var/log/omnissolutio.log, masks secrets
```

Requirements:
- Basic-auth (or however your API authenticates) using key_id/key_secret from config
- Timeouts (10s) + meaningful exceptions mapped to user-safe messages
- Log request/response when `debug=1`, **always redacting** secrets & customer PII
- Idempotency keys on createOrder/refund if your API supports them (use quote_id / creditmemo increment_id)

**Done when:** a throwaway CLI command or unit test can create a sandbox order against your real sandbox API from inside the container.

---

## Phase D — Frontend checkout integration (the iframe)

This is the Magento equivalent of your WP iframe work. No Node build needed — Magento checkout uses RequireJS + Knockout; you ship plain AMD modules. Your hosted `checkout.js` (the same script the iframe SDK uses on WP) gets loaded remotely at runtime.

```
view/frontend/layout/checkout_index_index.xml           ← inject renderer into payment step
view/frontend/web/js/view/payment/omnissolutio.js       ← registers the renderer
view/frontend/web/js/view/payment/method-renderer/
    omnissolutio-method.js                              ← the real logic
view/frontend/web/template/payment/omnissolutio.html    ← Knockout template (title, logo, pay button)
Model/Ui/ConfigProvider.php                             ← implements ConfigProviderInterface;
                                                          exposes key_id, env, SDK URL to window.checkoutConfig
requirejs-config.js                                     ← map your remote checkout.js as a dependency
Controller/Order/Create.php  (or a REST endpoint via etc/webapi.xml)
                                                        ← "create gateway order for current quote"
Controller/Payment/Callback.php                         ← receives payment_id+signature from JS (step 7a)
```

Renderer logic (`placeOrder` override), matching flow step-by-step:
1. Validate agreement checkboxes etc. (call default validators)
2. Place the Magento order (order-first: use the standard `placeOrder` action → order lands in `pending_payment`)
3. Ajax → your Create controller → returns `gateway_order_id` + `key_id`
4. `OmnisSolutio.open({ order_id, key, handler, modal: { ondismiss } })` — your iframe SDK
5. On success handler → POST payment_id/order_id/signature to Callback controller → redirect to success page
6. On dismiss/failure → show message, order stays `pending_payment` (Phase G cleans it up)

Gotchas to plan for:
- **CSP:** Magento 2.4.7 enforces Content-Security-Policy. Ship `etc/csp_whitelist.xml` allowing your SDK/iframe domains (`script-src`, `frame-src`, `connect-src`) or the iframe silently won't load.
- Guest checkout AND logged-in checkout must both work (quote masking differs).
- Test with **multishipping disabled assumption** documented (or explicitly unsupported).

**Done when:** you can pay a sandbox order end-to-end in the browser on `https://magento.test` and land on the success page with the order in `processing`.

---

## Phase E — Webhook endpoint (the safety net)

The most important reliability piece. Browsers close; webhooks don't.

```
Controller/Webhook/Index.php   ← implements CsrfAwareActionInterface
                                  (createCsrfValidationException → null,
                                   validateForCsrf → true) or POSTs will 403
etc/frontend/routes.xml        ← route: /omnissolutio/webhook
Model/Webhook/Processor.php    ← the actual event handling
```

Requirements:
- **Verify HMAC signature** of the raw body against `webhook_secret` (`hash_equals`, never `==`) before touching anything. Reject with 401 otherwise.
- **Idempotent:** store processed webhook event IDs (small table via `db_schema.xml`, or check current order state first). Your gateway will retry; duplicates must be no-ops. Return 200 for already-processed.
- Events to handle (map to your actual event names):
  - `payment.captured` / `payment.success` → find order by gateway_order_id (store it in `sales_order_payment.additional_information` at create time) → validate amount → invoice + move to `processing` *if callback didn't already do it*
  - `payment.failed` → add order comment; optionally cancel
  - `refund.processed` → comment/reconcile (refunds initiated from your dashboard, not Magento)
- **Race with the callback (7a vs 7b):** both paths may fire near-simultaneously. Take a row-level approach — reload the order fresh, check state before transitioning, wrap in try/catch on duplicate-invoice. This is the bug every payment plugin gets wrong first.
- Respond fast (<5s): do the minimum inline; heavy work → queue if needed.
- Merchant docs: "add `https://yourstore.com/omnissolutio/webhook` in the Omnis Solutio dashboard" — put this in README + config comment field.

**Done when:** replaying a signed sandbox webhook via curl transitions a `pending_payment` order to `processing`, and replaying it again changes nothing.

---

## Phase F — Admin operations

- **Refund:** implement the `refund` gateway command → your Refund API. Enables **online credit memos** (admin refunds from Magento, money actually moves). Support partial refunds if your API does; validate refund total ≤ captured amount.
- **Capture / Void:** only if you offer auth-then-capture. If your iframe always auto-captures (most Razorpay-style flows), set `can_capture` appropriately and skip.
- **Fetch payment status button (nice-to-have v1.1):** admin order-view button "Check payment status at Omnis Solutio" for support teams reconciling stuck orders.
- Show `payment_id`, gateway `order_id`, method (card/UPI/wallet if applicable) in the admin order payment block via `additional_information` + `info` template.

**Done when:** an online credit memo issues a real sandbox refund and the credit memo saves with the refund transaction ID.

---

## Phase G — Lifecycle & edge cases

- **Abandoned `pending_payment` orders:** cron job (`etc/crontab.xml` + `Cron/CancelPending.php`) that cancels orders stuck in `pending_payment` beyond N minutes (configurable, default 30) — *after* one last fetchPayment check against your API so you never cancel a paid order whose webhook was delayed.
- **Amount/currency mismatch** between Magento order and gateway payment → do NOT complete; flag with an order comment + log + (optionally) hold the order.
- **Currency support:** validate store currency against your gateway's supported list in `isAvailable()`.
- **Quote restoration:** on modal dismiss/failure, restore the customer's cart (`checkout/session` restoreQuote) so they can retry without rebuilding the cart.

---

## Phase H — Security checklist (you'll be audited by merchants)

- [ ] All signature comparisons via `hash_equals`
- [ ] Secrets encrypted at rest (Encrypted backend model) and never logged
- [ ] Webhook controller validates signature BEFORE any DB read/write
- [ ] Server-side re-verification of every payment (never trust browser-posted payment data alone)
- [ ] No card data ever touches the module (iframe only) — state SAQ-A explicitly in README
- [ ] CSRF handled deliberately (webhook exempted, callback protected by form key or signature)
- [ ] `etc/csp_whitelist.xml` scoped to exactly your domains
- [ ] Input from webhooks treated as untrusted (typed extraction, no variable-variable tricks)

---

## Phase I — Testing matrix

Local (your docker setup):
- Unit tests: `Test/Unit/` for signature verification, request builders, response handlers → `bin/cli vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist modules/omnis-solutio-payment-gateway/Test/Unit`
- Manual E2E on sandbox: guest checkout, logged-in checkout, discount/tax orders, failed payment, dismissed modal, webhook-only completion (kill the tab after paying), refund, partial refund, duplicate webhook replay
- Static analysis before every tag: `bin/cli vendor/bin/phpcs --standard=Magento2 modules/omnis-solutio-payment-gateway`

Compatibility matrix to verify before 1.0 (spin up per the docker guide):
| Magento | PHP | Priority |
|---|---|---|
| 2.4.7-p3 | 8.3 | primary (current setup) |
| 2.4.8 | 8.3/8.4 | before release |
| 2.4.6-p* | 8.2 | decide: support or set floor at 2.4.7 |

---

## Phase J — Release & distribution (from the workflow doc)

1. README: install steps, config walkthrough with screenshots, webhook setup URL, supported Magento/PHP versions, SAQ-A note, changelog
2. LICENSE (MIT), drop hardcoded `version` from composer.json (tags drive versions)
3. `phpcs --standard=Magento2` clean
4. Tag `v1.0.0`, push, submit to Packagist (+ GitHub/Bitbucket auto-update hook per the workflow doc)
5. Merchant install becomes: `composer require omnissolutio/payment-gateway && bin/magento module:enable OmnisSolutio_PaymentGateway && bin/magento setup:upgrade`
6. **Decide:** also submit to Adobe Commerce Marketplace? (Their review enforces coding standard + `composer.json` metadata strictly — cheaper to comply from day one than retrofit.)

---

## Build order (what to actually do next, in sequence)

1. **A** — system.xml + config.xml + acl.xml → config panel working
2. **C** — API client against your sandbox (you control the API, so this is fast)
3. **B** — gateway config/command skeleton wired in di.xml
4. **D** — ConfigProvider + renderer + iframe → first end-to-end sandbox payment 🎉
5. **E** — webhook endpoint + idempotency
6. **F** — refunds
7. **G** — cron cleanup + edge cases
8. **H/I** — security pass + test matrix
9. **J** — tag v1.0.0 → Packagist

Dev-loop reminders: edits are live via the symlink; run `bin/magento setup:upgrade` after `etc/` or `db_schema.xml` changes, `bin/magento setup:di:compile` isn't needed in developer mode, `bin/magento cache:flush` after layout/config XML changes, and tail `bin/log magento.log` + your `omnissolutio.log` while testing.