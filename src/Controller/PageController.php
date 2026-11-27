<?php

namespace App\Controller;

use App\Repository\RegionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PageController extends AbstractController
{
    public function __construct(
        private readonly RegionRepository $regions,
        private readonly CacheInterface $cache
    )
    {
    }

    #[Route('/', name: 'game_home', methods: ['GET'])]
    public function home(): Response
    {
        try {
            $globeMarkers = $this->cache->get('home.globe_markers.v1', function (ItemInterface $item): array {
                $item->expiresAfter(300);

                return array_map(static fn (array $region): array => [
                    'id' => (int) $region['id'],
                    'name' => (string) $region['name'],
                    'lat' => (float) $region['center_lat'],
                    'lon' => (float) $region['center_lng'],
                ], $this->regions->listRegionsAssociative());
            });
        } catch (\Throwable) {
            $globeMarkers = [];
        }

        return $this->render('home/index.html.twig', [
            'cesium_token' => $_SERVER['CESIUM_TOKEN'] ?? $_ENV['CESIUM_TOKEN'] ?? '',
            'globe_markers' => $globeMarkers,
        ]);
    }

    #[Route('/play', name: 'game_play', methods: ['GET'])]
    #[Route('/game', name: 'game_index', methods: ['GET'])]
    #[Route('/game.html', name: 'game_legacy', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('game/index.html.twig');
    }

    #[Route('/challenge-hub', name: 'challenge_hub', methods: ['GET'])]
    public function challengeHub(): Response
    {
        return $this->render('challenge_hub/index.html.twig');
    }

    #[Route('/creator-center', name: 'creator_center', methods: ['GET'])]
    public function creatorCenter(): Response
    {
        return $this->render('creator_center/index.html.twig');
    }

    #[Route('/admin', name: 'admin_center', methods: ['GET'])]
    public function adminCenter(): Response
    {
        return $this->render('admin/index.html.twig');
    }

    #[Route('/friends', name: 'friends', methods: ['GET'])]
    public function friends(): Response
    {
        return $this->render('friends/index.html.twig');
    }
}
