<?php
/**
 * For licensing information, please see the LICENSE file accompanied with this file.
 *
 * @author Gerard van Helden <drm@melp.nl>
 * @copyright 2012 Gerard van Helden <http://melp.nl>
 */

namespace Zicht\Tool\Plugin\Content;

use Symfony\Component\Console\Input\InputOption;
use Zicht\Tool\Command\TaskCommand;
use Zicht\Tool\Container\Container;
use Zicht\Tool\Plugin as BasePlugin;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Zicht\Tool\PluginTaskListenerInterface;

/**
 * Content plugin
 */
class Plugin extends BasePlugin implements PluginTaskListenerInterface
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
            'if_exist',
            function($fmt, ...$args) {
                $file = (count($args) > 0) ? sprintf($fmt, ...$args) : $fmt;
                return file_exists($file) ? $file : false;
            }
        );

        $container->decl(
            ['content', 'fmt', 'defaults','remote'],
            function(Container $c){

                if ($input = $c->resolve('defaults_remote')) {
                    return "--default-file=${input}";
                }

                if ($c->resolve('local') && is_file(sprintf('./etc/mysql/.%s.cnf', $c->resolve('target_env')))) {
                    return sprintf('--defaults-file=./etc/mysql/.%s.cnf', $c->resolve('target_env'));
                }

                return false;
            }
        );

        $container->fn(
            ['content', 'fmt','ssh','prefix'],
            function(Container $c, $env){
                return sprintf('ssh -C %s "', $c->resolve("envs.${env}.ssh", true));
            },
            true
        );

        $container->fn(
            ['content', 'fmt','ssh','suffix'],
            function(Container $c, $env){
                return '"';
            },
            true
        );

        $container->method(
            ['content', 'fmt', 'cmd', 'rsync'],
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

    }

    /**
     * @{inheritDoc}
     */
    public function getTaskListeners()
    {
        return [
            'content.db.pull' => 'updateCommands',
            'content.db.push' => 'updateCommands'
        ];
    }

    /**
     * Update some command with extra descriptions for help.
     *
     * @param TaskCommand $command
     */
    public function updateCommands(TaskCommand $command)
    {
        $definition = $command->getDefinition();
        $options = $definition->getOptions();

        foreach (array_keys($options) as $name) {
            switch ($name) {
                case 'where':
                    $options['where'] = new InputOption('where', null, InputOption::VALUE_REQUIRED, 'Dump only rows selected by given WHERE condition.');
                    break;
                case 'database':
                    $options['database'] = new InputOption('database', null, InputOption::VALUE_REQUIRED, 'The local db to push to.');
                    break;
                case 'defaults-local':
                    $options['defaults-local'] = new InputOption('defaults-local', null, InputOption::VALUE_REQUIRED, 'The defaults file used fot the mysql client. <comment>(defaults to ./etc/mysql/local.cnf if exitsts.)</comment>');
                    break;
                case 'defaults-remote':
                    $definition->addOption(new InputOption('defaults-remote', null, InputOption::VALUE_REQUIRED, 'The defaults file used fot the mysqldump. <comment>(defaults to ~/.my.cnf on remote.)</comment>'));
//                    $options['defaults-remote'] = new InputOption('defaults-remote', 'ddd', InputOption::VALUE_REQUIRED, 'The defaults file used fot the mysqldump. <comment>(defaults to ~/.my.cnf on remote.)</comment>');
                    break;
                case 'table':
                    $options['table'] = new InputOption('table', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'Dump only the given table.');
                    break;
                case 'no-drop':
                    $options['no-drop'] = new InputOption('no-drop', null, InputOption::VALUE_NONE, 'Not drop local database (default if a table or where option is given).');
                    break;
                case 'drop':
                    $options['drop'] = new InputOption('drop', null, InputOption::VALUE_NONE, 'Drop local database (default if no table or where option is given).');
                    break;
                case 'no-local':
                    $options['no-local'] = new InputOption('no-local', null, InputOption::VALUE_NONE, 'Not wrap the mysqldump in a ssh command.');
                    break;
                case 'local':
                    $options['local'] = new InputOption('local', null, InputOption::VALUE_NONE, 'Wrappes the mysqldump in a ssh command <comment>(default true)</comment>.');
                    break;
                case 'no-stdout':
                    $options['no-stdout'] = new InputOption('no-stdout', null, InputOption::VALUE_NONE, 'Will forward the mysqldump stdout to a mysqlclient local <comment>default true</comment>.');
                    break;
                case 'stdout':
                    $options['stdout'] = new InputOption('stdout', 'o', InputOption::VALUE_NONE, 'Print the mysqldump to stdout.');
                    break;
                case 'file':
                    $options['file'] = new InputOption('file', null, InputOption::VALUE_REQUIRED, 'Cat file and redirect output to mysql client.');
                    break;
                case 'no-backup':
                    $options['no-backup'] = new InputOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create backup before pushing to the remote.');
                    break;
                case 'backup':
                    $options['backup'] = new InputOption('backup', null, InputOption::VALUE_NONE, 'Create a backup from a remote dump before pushing <comment>(default true)</comment>.');
                    break;
            }
        }
        $definition->setOptions($options);
    }
}
