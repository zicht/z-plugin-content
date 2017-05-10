<?php
/**
 * For licensing information, please see the LICENSE file accompanied with this file.
 *
 * @author Gerard van Helden <drm@melp.nl>
 * @copyright 2012 Gerard van Helden <http://melp.nl>
 */

namespace Zicht\Tool\Plugin\Content;

use Symfony\Component\Console\Exception\InvalidArgumentException;
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
            ['fmt', 'cmd', 'mysql_local_drop'],
            function(Container $c) {
                if ($c->resolve('drop') && !$c->resolve('table')) {
                    return sprintf(
                        'mysql %1$s -e "DROP DATABASE IF EXISTS %2$s; CREATE DATABASE %2$s;',
                        $c->resolve('fmt.local_mysql_args'),
                        $c->resolve('content.local_db')
                    );
                } else {
                    return null;
                }
            }
        );

        $container->decl(
            ['fmt', 'cmd', 'mysql_pull'],
            function(Container $c) {
                $mysqldump  =  "mysqldump --opt";
                $mysqldump .=  ($c->resolve('VERBOSE')) ? ' -Qv' : " -Q";
                if ($where = $c->resolve('where')) {
                    $mysqldump .= sprintf(' --where="%s"', $where);
                }
                $mysqldump .= " " . $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env')));
                if ($table = $c->resolve('table')) {
                    $mysqldump .= " ${table}";
                }
                if ($file = $c->resolve('file')) {
                    $out = ($file === '-') ? "> /dev/stdout" : "> ${file}";
                } else {
                    $out = sprintf(
                        "| gzip -d - | mysql %s %s",
                        $c->resolve('fmt.local_mysql_args'),
                        $c->resolve('content.local_db')
                    );
                }
                $ssh = $c->resolve(sprintf('envs.%s.ssh', $c->resolve('target_env')));
                return  "ssh ${ssh} \"${mysqldump}\" | gzip -c -9 ${out}";
            }
        );


        $container->decl(
            ['fmt', 'cmd', 'mysql_remote_backup'],
            function(Container $c) {
                if ($c->resolve('backup')) {
                    $cmd = "mysqldump --opt ";
                    if ($c->resolve('VERBOSE')) {
                        $cmd .= "-Qv ";
                    } else {
                        $cmd .= "-Q ";
                    }
                    if ($where = $c->resolve('where')) {
                        $cmd .= sprintf('--where="%s" ', $where);
                    }
                    $cmd .= $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env'))) . " ";
                    if ($table = $c->resolve('table')) {
                        $cmd .= "${table} ";
                    }
                    $cmd .= sprintf(" | gzip -c -9 > %s", $c->resolve('fmt.sql_backup_file'));
                    return $cmd;
                } else {
                    return null;
                }
            }
        );

        $container->decl(
            ['fmt', 'cmd', 'mysql_push'],
            function(Container $c) {
                if ($c->resolve('from_dump')) {
                    $c->set('local_db', $c->resolve('arg'));
                    $cmd = sprintf(
                        "mysqldump --opt %s ",
                        $c->resolve('fmt.local_mysql_args')
                    );
                    if ($c->resolve('VERBOSE')) {
                        $cmd .= "-Qv ";
                    } else {
                        $cmd .= "-Q ";
                    }
                    if ($where = $c->resolve('where')) {
                        $cmd .= sprintf('--where="%s" ', $where);
                    }
                    $cmd .= $c->resolve('content.local_db') . " ";
                    if ($table = $c->resolve('table')) {
                        $cmd .= "${table} ";
                    }
                    $cmd .= "| ";
                } else {
                    if ($file = $c->resolve('arg')) {
                        $cmd = sprintf("cat %s | gzip -d - | ", $c->resolve('arg'));
                    } else {
                        throw new InvalidArgumentException("Missing required file arg.");
                    }
                }

                $cmd .= sprintf(
                    'ssh %s "mysql %s"',
                    $c->resolve(sprintf('envs.%s.ssh', $c->resolve('target_env'))),
                    $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env')))
                );
                return $cmd;
            }
        );

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
                        '%s.%s.%s%s%s.tgz',
                        (new \DateTime())->format('Ymd.U'),
                        $c->resolve('target_env'),
                        $c->resolve(sprintf('envs.%s.db', $c->resolve('target_env'))),
                        (!empty($c->resolve('table'))) ? '.' . $c->resolve('table') : null,
                        (!empty($c->resolve('where'))) ? '.' . rtrim(base64_encode($c->resolve('where')),'=') : null
                    );
                }
            });

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
