# Neuron Payments

Gateway-agnostic payment processing for the Neuron PHP framework, with a
hosted-checkout Stripe driver.

The component never touches raw card data: donors/customers enter payment
details on the provider's hosted page and are returned to your success/cancel
URLs. Completed payments are confirmed asynchronously via signed webhooks.

## Installation

```bash
composer require neuron-php/payments
```

This pulls in `stripe/stripe-php`.

## Usage

```php
use Neuron\Payments\GatewayFactory;
use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Money;
use Neuron\Payments\Dto\Frequency;

$gateway = GatewayFactory::create( [
    'provider'       => 'stripe',
    'secret_key'     => getenv( 'STRIPE_SECRET_KEY' ),
    'webhook_secret' => getenv( 'STRIPE_WEBHOOK_SECRET' ),
] );

$session = $gateway->createCheckoutSession( new CheckoutSessionRequest(
    amount:      Money::fromMajorUnits( 50.00 ),
    frequency:   Frequency::Monthly,
    successUrl:  'https://example.org/donations/success?session_id={CHECKOUT_SESSION_ID}',
    cancelUrl:   'https://example.org/donations/cancel',
    productName: 'Donation',
    metadata:    [ 'donation_id' => 123 ]
) );

header( 'Location: ' . $session->url );
```

### Webhooks

`verifyWebhook()` returns a gateway-agnostic `WebhookEvent` that covers the full
lifecycle of one-time payments **and** recurring subscriptions:

```php
$event = $gateway->verifyWebhook(
    file_get_contents( 'php://input' ),
    $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''
);

match( true )
{
    // Initial checkout (one-time payment or the first subscription charge)
    $event->isCheckoutCompleted()    => /* mark paid; $event->subscriptionId() != null => recurring */,

    // A subscription invoice was paid; $event->isRenewal() distinguishes a
    // renewal cycle from the initial charge.
    $event->isInvoicePaid()          => /* record renewal via $event->invoiceId() / amountPaid() */,

    // Subscription status / period changed, or it was canceled.
    $event->isSubscriptionUpdated()  => /* $event->subscriptionStatus(), currentPeriodEnd() */,
    $event->isSubscriptionDeleted()  => /* mark canceled */,

    // A renewal payment failed (dunning).
    $event->isInvoicePaymentFailed() => /* flag past_due */,

    default                          => null
};
```

`WebhookEvent` accessors are shape-tolerant across event types:
`metadata()`, `sessionId()`, `invoiceId()`, `subscriptionId()`,
`paymentIntentId()`, `amountTotal()`, `amountPaid()`, `customerEmail()`,
`subscriptionStatus()`, `currentPeriodEnd()`, `billingReason()`, `isRenewal()`.

### Managing subscriptions

```php
$subscription = $gateway->getSubscription( 'sub_123' );
$subscription->isActive();            // bool
$subscription->currentPeriodEnd;      // unix timestamp

// Cancel immediately, or at the end of the paid period.
$gateway->cancelSubscription( 'sub_123' );
$gateway->cancelSubscription( 'sub_123', atPeriodEnd: true );
```

## Recurring cadence

`Frequency` maps human cadences to Stripe subscription intervals:

| Frequency            | Stripe interval | interval_count |
|----------------------|-----------------|----------------|
| `OneTime`            | (payment mode)  | -              |
| `Monthly`            | month           | 1              |
| `Quarterly`          | month           | 3              |
| `SemiAnnual`         | month           | 6              |
| `Annual`             | year            | 1              |

## Configuration reference

`GatewayFactory::create()` accepts:

| Key              | Required | Description                                              |
|------------------|----------|----------------------------------------------------------|
| `provider`       | yes      | Gateway driver. Currently `stripe`.                      |
| `secret_key`     | yes      | Provider API secret (`sk_live_...` / `sk_test_...`).     |
| `webhook_secret` | yes*     | Signing secret (`whsec_...`); required to verify webhooks. |
| `currency`       | no       | ISO 4217 code; defaults to `usd`.                        |

Keep `secret_key` and `webhook_secret` out of source control — load them from an
encrypted secrets store or environment, never a committed config file.

## Using with Neuron CMS

`neuron-php/cms` ships an optional **payments** feature that uses this package.
A single engine handles every payment type — donations, memberships, dues — as
one-time payments or recurring subscriptions, with each renewal recorded and the
subscription lifecycle driven by the webhook. A payment's `purpose` (default
`donation`) tags what it is for. The feature is gated behind `class_exists()` +
config, so the CMS runs unchanged when the package is absent.

### 1. Install

```bash
composer require neuron-php/payments
```

### 2. Configure (`config/neuron.yaml`)

```yaml
payments:
  provider: stripe
  currency: usd
  secret_key: ""      # set in secrets.yml.enc (sk_live_... / sk_test_...)
  webhook_secret: ""  # set in secrets.yml.enc (whsec_...)
  default_form: general
  success_url: "/payments/success"
  cancel_url: "/payments/cancel"
  forms:
    general:
      purpose: donation               # donation (default), membership, ...
      label: "Support Us"
      button: "Donate"
      to: "info@example.org"          # internal notification recipient
      product_name: "Donation"
      success_message: "Thank you! A receipt has been emailed to you."
      amounts: [ 25, 50, 100, 250, 500 ]
      allow_custom_amount: true
      min_amount: 5
      frequencies: [ one_time, monthly, annual ]
      fields:
        - { name: name, label: "Your Name", type: text, required: true, sender_name: true }
        - { name: email, label: "Email", type: email, required: true, reply_to: true }
```

Put the real `secret_key` / `webhook_secret` in `config/secrets.yml.enc` under a
`payments:` section — not in `neuron.yaml`.

> A legacy top-level `donations:` section (same keys) is still honored for
> backward compatibility when `payments` has no `forms`.

### 3. Migrate

```bash
php neuron cms:upgrade --run-migrations   # creates the payments + subscriptions tables
```

### 4. Embed the form

Add the shortcode to any page's content (in a **paragraph** block — Raw HTML
blocks are not shortcode-parsed). `[payment]` is the generic shortcode;
`[donation]` is a friendly alias:

```
[payment]                        # default form
[payment form="membership"]      # a specific form
[donation form="general" title="Support Us" button="Give"]
```

### 5. Register the Stripe webhook

In the Stripe dashboard add an endpoint pointing at your site and subscribe to
these events:

```
https://<your-host>/payments/webhook
```

- `checkout.session.completed` — marks the payment complete; opens a
  subscription record for recurring payments.
- `invoice.paid` (or `invoice.payment_succeeded`) — records each recurring
  renewal as a new charge and refreshes the subscription period.
- `customer.subscription.updated` / `customer.subscription.deleted` — sync
  status / period, mark cancellations.
- `invoice.payment_failed` — flags the subscription `past_due`.

The webhook is the source of truth: it verifies the signature, persists state,
and triggers receipt + internal notification emails. The `success` page is
informational only. (`/donations/webhook` and the other `/donations/*` routes
remain as aliases.)

CMS routes: `POST /payments/checkout`, `GET /payments/success`,
`GET /payments/cancel`, `GET /payments/token`, `POST /payments/webhook`
(each also available under `/donations/*`). Admin screens live at
`/admin/payments` and `/admin/subscriptions` (the latter can cancel an active
subscription).

## Testing

```bash
composer install
./vendor/bin/phpunit tests
```
