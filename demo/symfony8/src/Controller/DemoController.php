<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function home(RuntimeEnvBag $runtimeEnv): Response
    {
        return $this->render('home.html.twig', [
            'sample'     => $runtimeEnv->get('DEMO_SAMPLE_VALUE'),
            'has_sample' => $runtimeEnv->has('DEMO_SAMPLE_VALUE'),
        ]);
    }
}
