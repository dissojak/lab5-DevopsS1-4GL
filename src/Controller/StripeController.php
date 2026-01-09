<?php

namespace App\Controller;

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Order;
use App\Service\EmailService;

class StripeController extends AbstractController
{
    private string $stripeSecretKey;
    private EntityManagerInterface $entityManager;
    private EmailService $emailService;

    public function __construct(
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ) {
        $this->entityManager = $entityManager;
        $this->emailService = $emailService;
        // Clé secrète Stripe - à mettre dans .env
        $this->stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? 'sk_test_...';
        Stripe::setApiKey($this->stripeSecretKey);
    }

    #[Route('/api/create-checkout-session', name: 'stripe_checkout', methods: ['POST'])]
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            // Créer les line items pour Stripe
            $lineItems = [];
            foreach ($data['items'] as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => $item['name'],
                            'images' => isset($item['image']) ? [$item['image']] : [],
                        ],
                        'unit_amount' => (int)($item['price'] * 100), // Stripe utilise les centimes
                    ],
                    'quantity' => $item['quantity'],
                ];
            }

            // Ajouter les frais de livraison
            if (isset($data['shippingCost']) && $data['shippingCost'] > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Frais de livraison',
                        ],
                        'unit_amount' => (int)($data['shippingCost'] * 100),
                    ],
                    'quantity' => 1,
                ];
            }

            // Créer la session de paiement Stripe
            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $data['successUrl'] ?? 'http://localhost:5173/order-confirmation?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $data['cancelUrl'] ?? 'http://localhost:5173/checkout',
                'customer_email' => $data['customerEmail'] ?? null,
                'metadata' => [
                    'order_reference' => $data['orderReference'] ?? null,
                    'user_id' => $data['userId'] ?? null,
                ],
            ]);

            return new JsonResponse([
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    #[Route('/api/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $sigHeader = $request->headers->get('stripe-signature');
            $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

            error_log("=== WEBHOOK RECEIVED ===");
            error_log("Webhook secret configured: " . ($webhookSecret ? 'YES' : 'NO'));
            error_log("Signature header present: " . ($sigHeader ? 'YES' : 'NO'));

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );

            error_log("Event type: " . $event->type);

            if ($event->type === 'checkout.session.completed') {
                error_log("=== CHECKOUT SESSION COMPLETED ===");
                $session = $event->data->object;

                // Vérifier si metadata existe
                if (!isset($session->metadata)) {
                    error_log("ERROR: No metadata in session");
                    return new JsonResponse(['status' => 'success', 'note' => 'no metadata']);
                }

                error_log("Metadata exists");

                // Vérifier order_reference
                $orderRef = $session->metadata->order_reference ?? null;
                if (!$orderRef) {
                    error_log("ERROR: No order_reference in metadata");
                    return new JsonResponse(['status' => 'success', 'note' => 'no order_reference']);
                }

                error_log("Order reference: " . $orderRef);

                $order = $this->entityManager->getRepository(Order::class)
                    ->findOneBy(['reference' => $orderRef]);

                if (!$order) {
                    error_log("ERROR: Order not found: " . $orderRef);
                    return new JsonResponse(['status' => 'success', 'note' => 'order not found']);
                }

                error_log("Order found: ID=" . $order->getId());
                error_log("Order status: " . $order->getStatus());
                error_log("Order paymentIntentId: " . ($order->getPaymentIntentId() ?? 'NULL'));

                // Envoyer l'email si la commande n'a pas encore de paymentIntentId
                // (cela signifie que le webhook n'a pas encore traité cette commande)
                $shouldSendEmail = !$order->getPaymentIntentId();

                // Mettre à jour le statut si nécessaire
                if ($order->getStatus() !== 'confirmed') {
                    $order->setStatus('confirmed');
                }

                // Toujours mettre à jour le paymentIntentId
                if (!$order->getPaymentIntentId()) {
                    $order->setPaymentIntentId($session->payment_intent);
                }

                $this->entityManager->flush();

                if ($shouldSendEmail) {
                    error_log("Order updated, sending email...");

                    // Send email only once
                    $emailSent = $this->emailService->sendOrderConfirmation($order);

                    error_log("Email sent: " . ($emailSent ? 'SUCCESS' : 'FAILED'));
                } else {
                    error_log("Order already processed (has paymentIntentId), skipping email");
                }
            }

            return new JsonResponse(['status' => 'success']);

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log("STRIPE SIGNATURE ERROR: " . $e->getMessage());
            return new JsonResponse(['error' => 'Invalid signature', 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log("WEBHOOK EXCEPTION: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return new JsonResponse([
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    #[Route('/api/stripe/verify-session/{sessionId}', name: 'stripe_verify', methods: ['GET'])]
    public function verifySession(string $sessionId): JsonResponse
    {
        try {
            $session = Session::retrieve($sessionId);

            return new JsonResponse([
                'payment_status' => $session->payment_status,
                'customer_email' => $session->customer_email,
                'amount_total' => $session->amount_total / 100,
                'metadata' => $session->metadata,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/orders/by-reference/{reference}', name: 'order_by_reference', methods: ['GET'])]
    public function getOrderByReference(string $reference): JsonResponse
    {
        try {
            $order = $this->entityManager->getRepository(Order::class)
                ->findOneBy(['reference' => $reference]);

            if (!$order) {
                return new JsonResponse(['error' => 'Commande non trouvée'], 404);
            }

            // Sérialiser la commande manuellement pour éviter les problèmes de référence circulaire
            $orderData = [
                'id' => $order->getId(),
                'reference' => $order->getReference(),
                'status' => $order->getStatus(),
                'totalAmount' => $order->getTotalAmount(),
                'createdAt' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
                'deliveryFirstName' => $order->getDeliveryFirstName(),
                'deliveryLastName' => $order->getDeliveryLastName(),
                'deliveryStreet' => $order->getDeliveryStreet(),
                'deliveryZipCode' => $order->getDeliveryZipCode(),
                'deliveryCity' => $order->getDeliveryCity(),
                'deliveryCountry' => $order->getDeliveryCountry(),
                'paymentMethod' => $order->getPaymentMethod(),
                'shippingMethod' => $order->getShippingMethod(),
                'paymentIntentId' => $order->getPaymentIntentId(),
                'cartId' => $order->getCart() ? $order->getCart()->getId() : null,
                'items' => []
            ];

            // Ajouter les items de la commande
            if ($order->getItems()) {
                foreach ($order->getItems() as $item) {
                    $product = $item->getProduct();
                    $seller = $item->getSeller();
                    $itemData = [
                        'id' => $item->getId(),
                        'quantity' => $item->getQuantity(),
                        'unitPrice' => $item->getUnitPrice(),
                        'selectedColor' => $item->getSelectedColor(),
                        'selectedSize' => $item->getSelectedSize(),
                        'product' => [
                            'id' => $product->getId(),
                            'name' => $product->getName(),
                            'price' => $product->getPrice(),
                            'images' => array_map(function($img) {
                                return ['url' => $img->getUrl()];
                            }, $product->getImages()->toArray())
                        ]
                    ];

                    // Ajouter le vendeur (InnovShop si null)
                    if ($seller) {
                        $itemData['seller'] = [
                            'id' => $seller->getId(),
                            'shopName' => $seller->getShopName(),
                        ];
                    } else {
                        $itemData['seller'] = [
                            'id' => null,
                            'shopName' => 'InnovShop',
                        ];
                    }

                    $orderData['items'][] = $itemData;
                }
            }

            return new JsonResponse($orderData);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
