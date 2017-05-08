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
                        ->arrayNode('local')
                            ->children()
                                ->arrayNode('db')
                                    ->children()
                                        ->scalarNode('host')->end()
                                        ->scalarNode('user')->end()
                                        ->scalarNode('password')->end()
                                    ->end()
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

        $container->fn(
            ['fmt', 'path'],
            function(...$parts) {
                $path = "";
                for ($c = 0, $ci = count($parts); $c < $ci; $c++) {
                    if (DIRECTORY_SEPARATOR !== substr($parts[$c], -1)) {
                        $parts[$c] .= DIRECTORY_SEPARATOR;
                    }
                    $path .= $parts[$c];
                }
                return $path;
            }
        );

        $container->decl(
            ['fmt', 'mysql_pull'],
            function(Container $c) {
                $line = [];
                if ($c->resolve('drop') && !$c->resolve('table')) {
                    $line[] = ["mysql", 0];
                    $line[] = [$c->resolve('fmt.local_mysql_args'), 8];
                    $line[] = [sprintf('-e "DROP DATABASE IF EXISTS %1$s; CREATE DATABASE %1$s;" &&', $c->resolve('content.local_db')), 8];
                }
                $line[] = [sprintf('ssh %s', $c->resolve(sprintf('envs.%s.ssh', $c->resolve('target_env')))), 4];
                $line[] = ['"mysqldump', 8];
                $line[] = ['--quote-names', 12];
                $line[] = ['--opt', 12];
                if ($c->resolve('VERBOSE')) {
                    $line[] = ['--verbose', 12];
                }
                if ("" !== ($where = $c->resolve('where'))) {
                    $line[] = [sprintf('--where="%s"', $where), 12];
                }
                $line[] = [sprintf('%s', $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env')))), 12];
                if (null !== ($table = $c->resolve('table'))) {
                    $line[] = [$table, 12];
                }
                $line[] = ['| gzip -c -9 "', 12];
                if ("" !== ($file = $c->resolve('file'))) {
                    $line[] = [sprintf('> %s', $file), 4];
                } else {
                    $line[] = [sprintf('| gzip -d -', $file), 4];
                    $line[] = [sprintf('| mysql %s %s',  $c->resolve('fmt.local_mysql_args'), $c->resolve('content.local_db')), 4];
                }
                return implode(
                    " \\\n",
                    array_filter(
                        array_map(
                            function($v) {
                                list($value, $indent) = $v;
                                return !(empty($value)) ? str_repeat(" ", $indent) . $value : null;
                            },
                            $line
                        )
                    )
                );
            }
        );

        $container->decl(
            ['fmt', 'local_mysql_args'],
            function(Container $c) {
                // need to check for var-name and var_name, because of a bug/conflict
                // in z/symfony that it will not set the default option in var-name and
                // the value given in a option not in var_name if option has a _ in the name.
                $args = [];
                // get input arg
                if (null !== ($host = $c->resolve('local-host'))) {
                    $args[] = sprintf("-h%s", $host);
                } else {
                    // get default value
                    if ("" !== ($host = $c->resolve('local_host'))) {
                        $args[] = sprintf("-h%s", $host);
                    } else {
                        // fallback to default settings
                        $args[] = sprintf("-h%s", $c->resolve('content.local.db.host'));
                    }
                }
                // get input arg
                if (null !== ($user = $c->resolve('local-user'))) {
                    $args[] = sprintf("-u%s", $user);
                } else {
                    // get default value
                    if ("" !== ($user = $c->resolve('local_user'))) {
                        $args[] = sprintf("-u%s", $user);
                    } else {
                        // fallback to default settings
                        $args[] = sprintf("-u%s", $c->resolve('content.local.db.user'));
                    }
                }
                // get input arg
                if (null !== ($password = $c->resolve('local-password'))) {
                    $args[] = sprintf("-p%s", $password);
                } else {
                    // get default value
                    if ("" !== ($password = $c->resolve('local_password'))) {
                        $args[] = sprintf("-p%s", $password);
                    } else {
                        // set if a global one is defined
                        if (null !== ($password = $c->resolve('content.local.db.password'))) {
                            $args[] = sprintf("-p%s", $password);
                        }
                    }
                }
                if (null !== ($port = $c->resolve('local-port'))) {
                    $args[] = sprintf('-P%s', $port);
                }
                return implode(' ', $args);
            }
        );

        $container->decl(['fmt','sql_backup_file'],
            function(Container $c) {
                if ($file = $c->resolve('file')) {
                    return $file;
                } else {
                    return sprintf(
                        '%s.%s.%s%s%s.tar.gz',
                        (new \DateTime())->format('Ymd.U'),
                        $c->resolve('target_env'),
                        $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env'))),
                        (!empty($c->resolve('table'))) ? '.' . $c->resolve('table') : null,
                        (!empty($c->resolve('where'))) ? '.' . rtrim(base64_encode($c->resolve('where')),'=') : null
                    );
                }
        });

        $container->method(
            ['fmt', 'cmd', 'rsync'],
            function(Container $c, $src, $dest) {
                if (null !== ($args = $c->resolve('rsync-flags'))) {
                    return sprintf("rsync %s %s %s", $args, $src, $dest);
                } else {
                    $long = [];
                    $short = [
                        'r', //recursive
                        'u', //update
                    ];
                    if ($c->resolve('VERBOSE')) {
                        $short[] = "v"; // verbose
                        $short[] = "h"; // human-readable;
                        $short[] = "p"; // progress
                    }
                    if ($c->resolve('simulate')) {
                        $short[] = "n"; // dry-run
                    }
                    if ($c->resolve('delete')) {
                        $long[] = "delete";
                    }
                    if (null !== ($exclude = $c->resolve('content.exclude'))) {
                        foreach((array) $exclude as $e) {
                            $long[] = sprintf("exclude=%s", $e);
                        }
                    }
                    return preg_replace(
                        '#\s{2,}#',
                        '',
                        sprintf(
                            "rsync -%s %s %s %s",
                            implode(
                                '',
                                $short
                            ),
                            implode(
                                " ",
                                array_map(
                                    function ($v) {
                                        return  "--${v}";
                                    },
                                    $long
                                )
                            ),
                            $src,
                            $dest
                        )
                    );
                }
            }
        );

        $container->decl(
            ['content', 'local_db'],
            function(Container $c) {
                // check if db is given as argument.
                if (false !== ($db = $c->resolve('local_db'))) {
                    return $db;
                } else {
                    // check if a local db is defined
                    if (null !== ($db = $c->resolve('envs.local.db'))) {
                        return $db;
                    } else {
                        // fallback to target envs db
                        return $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env')));
                    }
                }
            }
        );
    }
}
