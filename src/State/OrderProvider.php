<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class OrderProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        private EntityManagerInterface $entityManager,
        private Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof GetCollection) {
            /** @var \App\Entity\User|null $currentUser */
            $currentUser = $this->security->getUser();

            if (!$currentUser) {
                // Si pas connecté, ne retourner aucune commande
                return [];
            }

            // Vérifier si l'utilisateur est admin
            $isAdmin = in_array('ROLE_ADMIN', $currentUser->getRoles(), true);

            // Vérifier si un filtre user est présent dans la requête
            $filters = $context['filters'] ?? [];
            $hasUserFilter = isset($filters['user']) || isset($filters['user.id']);

            // IMPORTANT: Les utilisateurs normaux ne peuvent voir que leurs propres commandes
            // Les admins peuvent voir toutes les commandes seulement s'ils n'ont pas spécifié de filtre user
            if (!$isAdmin) {
                // Utilisateur normal: TOUJOURS filtrer par son ID, même s'il essaie de passer un autre user
                $context['filters'] = $context['filters'] ?? [];
                $context['filters']['user'] = '/users/' . $currentUser->getId();
            } elseif ($hasUserFilter) {
                // Admin avec filtre user: respecter le filtre (pour voir ses propres commandes)
                // Le filtre est déjà dans le contexte, on le laisse tel quel
            }
            // Si admin et pas de filtre, il peut voir toutes les commandes (pour /admin/orders)

            $result = $this->collectionProvider->provide($operation, $uriVariables, $context);

            // Si c'est une collection, charger explicitement les sellerLots pour chaque commande
            if (is_iterable($result)) {
                foreach ($result as $order) {
                    if ($order instanceof Order) {
                        // Forcer le chargement des sellerLots
                        $order->getSellerLots()->count();
                    }
                }
            }

            return $result;
        }

        if ($operation instanceof Get) {
            $result = $this->itemProvider->provide($operation, $uriVariables, $context);

            if ($result instanceof Order) {
                // Charger explicitement les sellerLots avec leurs vendeurs
                $this->entityManager->getRepository(Order::class)
                    ->createQueryBuilder('o')
                    ->leftJoin('o.sellerLots', 'lot')
                    ->leftJoin('lot.seller', 'seller')
                    ->addSelect('lot')
                    ->addSelect('seller')
                    ->where('o.id = :id')
                    ->setParameter('id', $result->getId())
                    ->getQuery()
                    ->getResult();

                // Forcer le chargement
                $result->getSellerLots()->count();
            }

            return $result;
        }

        // Pour les autres opérations, utiliser le provider par défaut
        return $this->itemProvider->provide($operation, $uriVariables, $context);
    }
}
