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

```php
$event = $gateway->verifyWebhook(
    file_get_contents( 'php://input' ),
    $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''
);

if( $event->isCheckoutCompleted() )
{
    $donationId = $event->metadata()['donation_id'] ?? null;
    // mark the donation paid, store $event->paymentIntentId() / subscriptionId()
}
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

## Testing

```bash
composer install
./vendor/bin/phpunit tests
```
