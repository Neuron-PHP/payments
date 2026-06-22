<?php

namespace Neuron\Payments\Stripe;

use Neuron\Payments\Dto\CheckoutSession;
use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Refund;
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
		$recurring = $request->frequency->isRecurring();

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

		$metadata = [];

		foreach( $request->metadata as $key => $value )
		{
			$metadata[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		$metadata['frequency'] = $request->frequency->value;

		$params = [
			'mode'        => $recurring ? 'subscription' : 'payment',
			'success_url' => $request->successUrl,
			'cancel_url'  => $request->cancelUrl,
			'line_items'  => [
				[
					'price_data' => $priceData,
					'quantity'   => 1
				]
			],
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
