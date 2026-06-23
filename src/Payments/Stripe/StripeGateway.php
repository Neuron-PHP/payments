<?php

namespace Neuron\Payments\Stripe;

use Neuron\Payments\Dto\CheckoutSession;
use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Frequency;
use Neuron\Payments\Dto\LineItem;
use Neuron\Payments\Dto\Refund;
use Neuron\Payments\Dto\Subscription;
use Neuron\Payments\Dto\WebhookEvent;
use Neuron\Payments\Exceptions\PaymentException;
use Neuron\Payments\IPaymentGateway;
use Stripe\StripeClient;

/**
 * Stripe implementation of {@see IPaymentGateway} using hosted Checkout.
 *
 * Card data is collected on Stripe's hosted Checkout page; this class only
 * builds the session, redirects, and reconciles results via signed webhooks.
 *
 * @package Neuron\Payments\Stripe
 */
class StripeGateway implements IPaymentGateway
{
	private ?StripeClient $_client;

	/**
	 * @param string $secretKey Stripe secret API key ( sk_... ).
	 * @param string $webhookSecret Signing secret for webhook verification ( whsec_... ).
	 * @param StripeClient|null $client Optional pre-built client ( for testing ).
	 * @param int $tolerance Max webhook timestamp age in seconds.
	 */
	public function __construct(
		private readonly string $secretKey,
		private readonly string $webhookSecret = '',
		?StripeClient $client = null,
		private readonly int $tolerance = 300
	)
	{
		$this->_client = $client;
	}

	/**
	 * @inheritDoc
	 */
	public function createCheckoutSession( CheckoutSessionRequest $request ): CheckoutSession
	{
		try
		{
			$session = $this->client()->checkout->sessions->create( $this->buildSessionParams( $request ) );
		}
		catch( \Throwable $e )
		{
			throw new PaymentException( 'Unable to create Stripe checkout session: ' . $e->getMessage(), 0, $e );
		}

		$url = $session->url ?? '';

		if( $url === '' )
		{
			throw new PaymentException( 'Stripe did not return a checkout URL.' );
		}

		return new CheckoutSession( (string) $session->id, (string) $url );
	}

	/**
	 * Translate a request into the Stripe Checkout Session parameters.
	 *
	 * Extracted for unit testing; the live call is a thin wrapper around this.
	 *
	 * @param CheckoutSessionRequest $request
	 * @return array<string, mixed>
	 */
	public function buildSessionParams( CheckoutSessionRequest $request ): array
	{
		// Cart mode is one-time only; explicit line items override the single amount.
		$recurring = $request->frequency->isRecurring() && !$request->hasLineItems();

		$metadata = [];

		foreach( $request->metadata as $key => $value )
		{
			$metadata[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		$metadata['frequency'] = $recurring ? $request->frequency->value : Frequency::OneTime->value;

		$params = [
			'mode'        => $recurring ? 'subscription' : 'payment',
			'success_url' => $request->successUrl,
			'cancel_url'  => $request->cancelUrl,
			'line_items'  => $this->buildLineItems( $request, $recurring ),
			'metadata'    => $metadata
		];

		if( $request->customerEmail !== null && $request->customerEmail !== '' )
		{
			$params['customer_email'] = $request->customerEmail;
		}

		// Mirror metadata onto the subscription so it is present on renewal events.
		if( $recurring )
		{
			$params['subscription_data'] = [ 'metadata' => $metadata ];
		}

		return $params;
	}

	/**
	 * Build the Stripe line_items array: either the explicit cart items or the
	 * single amount-based item ( the original single-charge behavior ).
	 *
	 * @param CheckoutSessionRequest $request
	 * @param bool $recurring
	 * @return array<int, array<string, mixed>>
	 */
	private function buildLineItems( CheckoutSessionRequest $request, bool $recurring ): array
	{
		if( $request->hasLineItems() )
		{
			$items = [];

			foreach( $request->lineItems as $item )
			{
				if( !$item instanceof LineItem )
				{
					continue;
				}

				$items[] = [
					'price_data' => [
						'currency'     => $item->unitAmount->currency,
						'unit_amount'  => $item->unitAmount->amount,
						'product_data' => [ 'name' => $item->name ]
					],
					'quantity'   => max( 1, $item->quantity )
				];
			}

			if( $items !== [] )
			{
				return $items;
			}
		}

		$priceData = [
			'currency'     => $request->amount->currency,
			'unit_amount'  => $request->amount->amount,
			'product_data' => [ 'name' => $request->productName ]
		];

		if( $recurring )
		{
			$priceData['recurring'] = [
				'interval'       => $request->frequency->stripeInterval(),
				'interval_count' => $request->frequency->stripeIntervalCount()
			];
		}

		return [
			[
				'price_data' => $priceData,
				'quantity'   => 1
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function verifyWebhook( string $payload, string $signature ): WebhookEvent
	{
		if( $this->webhookSecret === '' )
		{
			throw new PaymentException( 'Webhook secret is not configured.' );
		}

		$this->assertSignature( $payload, $signature );

		$event = json_decode( $payload, true );

		if( !is_array( $event ) || !isset( $event['type'] ) )
		{
			throw new PaymentException( 'Malformed webhook payload.' );
		}

		$object = $event['data']['object'] ?? [];

		return new WebhookEvent( (string) $event['type'], is_array( $object ) ? $object : [] );
	}

	/**
	 * @inheritDoc
	 */
	public function refund( string $paymentIntentId ): Refund
	{
		try
		{
			$refund = $this->client()->refunds->create( [ 'payment_intent' => $paymentIntentId ] );
		}
		catch( \Throwable $e )
		{
			throw new PaymentException( 'Unable to refund Stripe payment: ' . $e->getMessage(), 0, $e );
		}

		return new Refund( (string) $refund->id, (string) ( $refund->status ?? 'unknown' ) );
	}

	/**
	 * @inheritDoc
	 */
	public function getSubscription( string $subscriptionId ): Subscription
	{
		try
		{
			$subscription = $this->client()->subscriptions->retrieve( $subscriptionId );
		}
		catch( \Throwable $e )
		{
			throw new PaymentException( 'Unable to retrieve Stripe subscription: ' . $e->getMessage(), 0, $e );
		}

		return $this->subscriptionFromStripe( $subscription );
	}

	/**
	 * @inheritDoc
	 */
	public function cancelSubscription( string $subscriptionId, bool $atPeriodEnd = false ): Subscription
	{
		try
		{
			if( $atPeriodEnd )
			{
				$subscription = $this->client()->subscriptions->update(
					$subscriptionId,
					[ 'cancel_at_period_end' => true ]
				);
			}
			else
			{
				$subscription = $this->client()->subscriptions->cancel( $subscriptionId );
			}
		}
		catch( \Throwable $e )
		{
			throw new PaymentException( 'Unable to cancel Stripe subscription: ' . $e->getMessage(), 0, $e );
		}

		return $this->subscriptionFromStripe( $subscription );
	}

	/**
	 * Map a Stripe subscription object ( SDK object or array ) to a Subscription DTO.
	 *
	 * @param mixed $subscription
	 * @return Subscription
	 */
	public function subscriptionFromStripe( mixed $subscription ): Subscription
	{
		$get = static function( string $key ) use ( $subscription )
		{
			if( is_array( $subscription ) )
			{
				return $subscription[ $key ] ?? null;
			}

			return $subscription->$key ?? null;
		};

		$metadata = $get( 'metadata' );

		if( $metadata !== null && !is_array( $metadata ) && method_exists( $metadata, 'toArray' ) )
		{
			$metadata = $metadata->toArray();
		}

		$periodEnd  = $get( 'current_period_end' );
		$canceledAt = $get( 'canceled_at' );

		return new Subscription(
			id:               (string) ( $get( 'id' ) ?? '' ),
			status:           (string) ( $get( 'status' ) ?? 'unknown' ),
			currentPeriodEnd: $periodEnd === null ? null : (int) $periodEnd,
			canceledAt:       $canceledAt === null ? null : (int) $canceledAt,
			metadata:         is_array( $metadata ) ? $metadata : []
		);
	}

	/**
	 * Verify the Stripe-Signature header against the raw payload.
	 *
	 * Implements Stripe's documented scheme: signed_payload = "{t}.{payload}",
	 * compared via HMAC-SHA256 with the webhook signing secret.
	 *
	 * @param string $payload
	 * @param string $header
	 * @return void
	 * @throws PaymentException
	 */
	private function assertSignature( string $payload, string $header ): void
	{
		$timestamp = null;
		$signatures = [];

		foreach( explode( ',', $header ) as $part )
		{
			$pair = explode( '=', trim( $part ), 2 );

			if( count( $pair ) !== 2 )
			{
				continue;
			}

			[ $key, $value ] = $pair;

			if( $key === 't' )
			{
				$timestamp = $value;
			}
			elseif( $key === 'v1' )
			{
				$signatures[] = $value;
			}
		}

		if( $timestamp === null || $signatures === [] )
		{
			throw new PaymentException( 'Invalid webhook signature header.' );
		}

		if( $this->tolerance > 0 && abs( time() - (int) $timestamp ) > $this->tolerance )
		{
			throw new PaymentException( 'Webhook timestamp outside of tolerance.' );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->webhookSecret );

		foreach( $signatures as $signature )
		{
			if( hash_equals( $expected, $signature ) )
			{
				return;
			}
		}

		throw new PaymentException( 'Webhook signature verification failed.' );
	}

	/**
	 * Lazily build the Stripe client so signature checks and param building
	 * work without the SDK being instantiated.
	 *
	 * @return StripeClient
	 * @throws PaymentException
	 */
	private function client(): StripeClient
	{
		if( $this->_client !== null )
		{
			return $this->_client;
		}

		if( !class_exists( StripeClient::class ) )
		{
			throw new PaymentException( 'The stripe/stripe-php package is not installed.' );
		}

		if( $this->secretKey === '' )
		{
			throw new PaymentException( 'Stripe secret key is not configured.' );
		}

		return $this->_client = new StripeClient( $this->secretKey );
	}
}
