# RadicalMart Payment: Tinkoff

**RadicalMart Payment: Tinkoff** is a payment gateway plugin for RadicalMart that integrates the **Tinkoff payment platform** as a payment method.

The plugin acts as a bridge between RadicalMart’s payment system and Tinkoff’s API.
It does not implement order logic and does not alter RadicalMart core behavior.

---

## Purpose

This plugin provides a **Tinkoff-based payment method** for RadicalMart orders.

Its responsibility is limited to:
- initiating payment requests,
- handling payment callbacks and statuses,
- mapping Tinkoff payment states to RadicalMart order states.

All business rules remain in RadicalMart core and related plugins.

---

## What this plugin does

- Registers a payment method backed by Tinkoff.
- Initiates payment sessions using Tinkoff API.
- Redirects the customer to the Tinkoff payment flow.
- Handles payment result callbacks.
- Updates order payment status based on gateway responses.

---

## What this plugin does NOT do

- ❌ Does not create or manage orders
- ❌ Does not calculate prices, taxes, or discounts
- ❌ Does not control order lifecycle logic
- ❌ Does not replace RadicalMart payment orchestration

The plugin is a **payment transport implementation**, not a checkout controller.

---

## Architecture role

Within RadicalMart payment architecture:

```

RadicalMart Order
↓
Payment Orchestration
↓
Payment Plugin (Tinkoff)
↓
External Payment API

```

This plugin represents a **gateway adapter**.

---

## Payment flow

1. RadicalMart prepares an order for payment.
2. The Tinkoff payment plugin creates a payment request.
3. The customer is redirected to the Tinkoff payment interface.
4. Tinkoff processes the payment.
5. Callback data is received and validated.
6. Order payment state is updated in RadicalMart.

---

## Configuration

The plugin exposes configuration options required by the Tinkoff gateway, such as:
- credentials,
- environment settings,
- callback behavior.

All configuration is stored using standard Joomla plugin parameters.

---

## Security considerations

- Callback validation is required to ensure payment authenticity.
- Order updates are performed only after successful verification.
- Sensitive data is never stored in RadicalMart.

---

## Usage

This plugin is intended for:
- projects using Tinkoff as a payment provider,
- RadicalMart installations requiring external payment processing,
- setups where payment logic must remain isolated from business logic.

It can be replaced or complemented by other payment plugins.

---

## Extensibility

The plugin follows RadicalMart’s payment interfaces and event system.
Custom logic can be added via:
- RadicalMart events,
- order state listeners,
- custom payment orchestration plugins.