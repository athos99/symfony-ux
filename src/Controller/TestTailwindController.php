<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TestTailwindController extends AbstractController
{
    #[Route('/test_tailwind', name: 'app_test_tailwind')]
    public function index(): Response
    {
        return $this->render('test_tailwind/index.html.twig');
    }
}
