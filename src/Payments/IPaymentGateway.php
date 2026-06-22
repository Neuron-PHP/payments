<?php

namespace Neuron\Payments;

use Neuron\Payments\Dto\CheckoutSession;
use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Refund;
use Neuron\Payments\Dto\WebhookEvent;
use Neuron\Payments\Exceptions\PaymentException;

/**
 * Gateway-agnostic contract for hosted payment processing.
 *
 * Implementations delegate card handling to the provider's hosted checkout, so
 * the host application never touches raw card data.
 *
 * @package Neuron\Payments
 */
interface IPaymentGateway
{
	/**
	 * Open a hosted checkout session for a one-time or recurring payment.
	 *
	 * @param CheckoutSessionRequest $request
	 * @return CheckoutSession
	 * @throws PaymentException When the session cannot be created.
	 */
	public function createCheckoutSession( CheckoutSessionRequest $request ): CheckoutSession;

	/**
	 * Verify a webhook payload's signature and decode it into an event.
	 *
	 * @param string $payload Raw request body.
	 * @param string $signature Provider signature header.
	 * @return WebhookEvent
	 * @throws PaymentException When the signature is invalid.
	 */
	public function verifyWebhook( string $payload, string $signature ): WebhookEvent;

	/**
	 * Refund a captured payment by its payment intent / charge id.
	 *
	 * @param string $paymentIntentId
	 * @return Refund
	 * @throws PaymentException When the refund fails.
	 */
	public function refund( string $paymentIntentId ): Refund;
}
