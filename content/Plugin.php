<?php
/**
 * For licensing information, please see the LICENSE file accompanied with this file.
 *
 * @author Gerard van Helden <drm@melp.nl>
 * @copyright 2012 Gerard van Helden <http://melp.nl>
 */

namespace Zicht\Tool\Plugin\Content;

use Zicht\Tool\Container\Container;
use Zicht\Tool\Plugin as BasePlugin;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Content plugin
 */
class Plugin extends BasePlugin
{
    /**
     * @{inheritDoc}
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $filter = function ($s) {
            return array_filter(array($s));
        };
        $rootNode
            ->children()
                ->arrayNode('content')
                    ->children()
                        ->arrayNode('dir')
                            ->prototype('scalar')->end()
                            ->performNoDeepMerging()
                        ->end()
                        ->arrayNode('exclude')
                            ->prototype('scalar')->end()
                            ->performNoDeepMerging()
                        ->end()
                        ->arrayNode('db')
                            ->children()
                                ->arrayNode('structure')
                                    ->beforeNormalization()->ifString()->then($filter)->end()
                                    ->prototype('scalar')->end()
                                    ->performNoDeepMerging()
                                ->end()
                                ->arrayNode('full')
                                    ->beforeNormalization()->ifString()->then($filter)->end()
                                    ->prototype('scalar')->end()
                                    ->performNoDeepMerging()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }


    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        $container->decl(['content','exclude_string'], function(Container $c) {

            if (empty($c->resolve('content.exclude'))) {
                return null;
            }
            $return = [];
            foreach ($c->resolve('content.exclude') as $exlude) {
                $return[] = sprintf('--exclude=%s', $exlude);
            }
            return implode(' ', $return);

        });
        $container->decl(['content','push_backup_file'], function(Container $c) {
            return sprintf(
                '%s.%s.%s%s%s.tar.gz',
                (new \DateTime())->format('Ymd.U'),
                $c->resolve('target_env'),
                $c->resolve('envs')[$c->resolve('target_env')]['db'],
                (!empty($c->resolve('table'))) ? '.' . $c->resolve('table') : null,
                (!empty($c->resolve('where'))) ? '.' .rtrim(base64_encode($c->resolve('where')),'=') : null
            );
        });
        $container->decl(['content','sql_where'], function(Container $c) {
            return (!empty($c->resolve('where'))) ? sprintf('--where=\'%s\' ',$c->resolve('where')) : null;
        });

        $container->decl(['content','local_db_args'], function(Container $c) {
            $line[] = sprintf('-u%s', !empty($c->resolve('local_user')) ? $c->resolve('local_user') : 'dev');
            $line[] = sprintf('-h%s', !empty($c->resolve('local_host')) ? $c->resolve('local_host') : 'dev3');
            if (!empty($c->resolve('local_password'))) {
                $line[] = sprintf('-p%s', $c->resolve('local_password'));
            }
            if (!empty($c->resolve('local_port'))) {
                $line[] = sprintf('-P%s', $c->resolve('local_port'));
            }
            if (false === ($local = $c->resolve('local_db'))) {
                $line[] = $c->resolve('envs')[$c->resolve('target_env')]['db'] . '.local';
            } else {
                $line[] = $local;
            }
            return implode(' ', $line);
        });

    }
}