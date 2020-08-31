<?php
declare(strict_types=1);

/**
 * ADmad\HybridAuth plugin.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */

namespace ADmad\HybridAuth;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Database\TypeFactory;
use Cake\Routing\RouteBuilder;

class Plugin extends BasePlugin
{
    /**
     * @var bool
     */
    protected $bootstrapEnabled = false;

    /**
     * @param \Cake\Routing\RouteBuilder $routes Routes builder instance.
     *
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->scope(
            '/hybrid-auth',
            ['plugin' => 'ADmad/HybridAuth', 'controller' => 'Auth'],
            function (RouteBuilder $routes) {
                $routes->connect(
                    '/login/:provider',
                    ['action' => 'login'],
                    ['pass' => ['provider']]
                );
                $routes->connect(
                    '/callback/:provider',
                    ['action' => 'callback'],
                    ['pass' => ['provider']]
                );
                $routes->connect(
                    '/callback',
                    ['action' => 'callback']
                );
            }
        );
    }
}
