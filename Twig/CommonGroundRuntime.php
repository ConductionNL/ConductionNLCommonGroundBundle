<?php

// src/Twig/Commonground.php

namespace Conduction\CommonGroundBundle\Twig;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\RuntimeExtensionInterface;

class CommonGroundRuntime implements RuntimeExtensionInterface
{
    private $commongroundService;
    private $params;
    private $router;

    public function __construct(CommonGroundService $commongroundService, ParameterBagInterface $params, RouterInterface $router)
    {
        $this->commongroundService = $commongroundService;
        $this->params = $params;
        $this->router = $router;
    }

    public function getResource($resource)
    {
        return $this->commongroundService->getResource($resource);
    }

    public function isResource($resource)
    {
        return $this->commongroundService->isResource($resource);
    }

    public function getResourceList($url, $query = null)
    {
        return $this->commongroundService->getResourceList($url, $query);
    }

    public function getComponentList()
    {
        return $this->commongroundService->getComponentList();
    }

    public function getComponentHealth($component)
    {
        return $this->commongroundService->getComponentHealth($component);
    }

    public function getComponentResources($component)
    {
        return $this->commongroundService->getComponentResources($component);
    }

    public function getApplication()
    {
        return $this->commongroundService->getApplication();
    }

    public function cleanUrl($url = false, $resource = false, $autowire = true)
    {
        return $this->commongroundService->cleanUrl($url, $resource, $autowire);
    }

    public function getPath(string $route, array $route_parameters = [], $relative = false)
    {
        if ($this->params->get('app_subpath') && $this->params->get('app_subpath') != 'false') {
            return '/'.$this->params->get('app_subpath').$this->router->generate($route, $route_parameters, $relative);
        } else {
            return $this->router->generate($route, $route_parameters, $relative);
        }
    }

    public function iterableArray(array $item, string $data)
    {
        $result = '';
        foreach ($item as $subItem) {
            if (is_array($subItem)) {
                $temp = $this->iterableArray($subItem, $data);
            } else {
                $temp = $data;
            }

            $result .= '<li>'.$temp.'</li>';
        }

        return '<ul>'.$result.'</ul>';
    }
}
