<?php
namespace TrelloPageObjects;

#require dirname(__DIR__) . '/vendor/autoload.php';

use \Michelf\Markdown;

/**
 * PageObject
 *
 * ...
 *
 * @package PageObject
 * @author Andy Kirk
 * @copyright Copyright (c) 2021
 * @version 0.1
 **/
#class PageObject implements \RecursiveIterator
class TrelloPageObjects
{
    /**
     * Stores the config data
     * @var array
     **/
    protected $c = [];


    /**
     * TrelloPageObjects::__construct()
     *
     * @param string $config_path Path to the config file
     */
    public function __construct($config = [])
    {
        // Check config path:
        if (empty($config)) {
            trigger_error('TrelloPageObjects error: no config array provided', E_USER_ERROR);
        }

        $this->c = $config;
    }


    #@TODO the twig template should probably come from a file, I guess, and possible there should be a
    # way for the data to specify different 'page types' here too.
    public function generateTwigFile($page, $base_dir) {

        $dir = $base_dir . $page->get('path');
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        $filename = $dir . 'index.html.twig';
        #echo '<pre>'; var_dump($filename); echo '</pre>';

        $twig = <<<TWIG
{% extends "structure.twig" %}

{% block title %}{$page->get('title')}{% endblock %}
{% block head %}
    {{ parent() }}
{% endblock %}
{% block heading %}{$page->get('title')}{% endblock %}
{% block content %}
    {$page->get('body')}
{% endblock %}
TWIG;

        #echo '<pre>'; var_dump($twig_base); echo '</pre>';

        file_put_contents($filename, $twig);

        $children = $page->get('children');

        if (!empty($children)) {
            foreach ($children as $child) {
                $this->generateTwigFile($child, $base_dir);
            }
        }
    }

    public function getTrelloData() {
        $config = $this->c;
        // This should be determined by the Webhook, and possibly a QS param so we can manually trigger
        // a cache refresh. NOTE don't empty the cache, because it's always better to have an outdated
        // cache than no cache.
        $trello_cache_dir = $this->c['dpc_root'] . '/_trello_cache';

        if ($config['use_cache']) {

            $board  = json_decode(file_get_contents($trello_cache_dir . '/board.json'));
            $lists  = json_decode(file_get_contents($trello_cache_dir . '/lists.json'));
            $cards  = json_decode(file_get_contents($trello_cache_dir . '/cards.json'));
            $fields = json_decode(file_get_contents($trello_cache_dir . '/fields.json'));

        } else {

            $trello_api_key  = $config['trello_api_key'];
            $trello_token    = $config['trello_token'];
            $trello_board_id = $config['trello_board_id'];

            $trello_client = new \Stevenmaguire\Services\Trello\Client(array(
                'key'   => $trello_api_key,
                'token' => $trello_token
            ));


            $board  = $trello_client->getBoard($trello_board_id);
            $lists  = $trello_client->getBoardLists($trello_board_id);
            $cards  = $trello_client->getBoardCards($trello_board_id, array('customFieldItems'=>'true'));
            $fields = $trello_client->getBoardCustomFields($trello_board_id);

            file_put_contents($trello_cache_dir . '/board.json',  json_encode($board));
            file_put_contents($trello_cache_dir . '/lists.json',  json_encode($lists));
            file_put_contents($trello_cache_dir . '/cards.json',  json_encode($cards));
            file_put_contents($trello_cache_dir . '/fields.json', json_encode($fields));

        }

        return [
            'board'  => $board,
            'lists'  => $lists,
            'cards'  => $cards,
            'fields' => $fields
        ];
    }

    public function run() {
        $config = $this->c;
        $trello_data = $this->getTrelloData();
        extract($trello_data);
        #echo '$board<pre>'; var_dump($board); echo '</pre>'; #exit;
        #echo '$lists<pre>'; var_dump($lists); echo '</pre>'; exit;
        #echo '$fields<pre>'; var_dump($fields); echo '</pre>'; exit;
        #echo '$cards<pre>'; var_dump($cards); echo '</pre>'; exit;

        /*
        Home (Board desc)
          |__ Category (List, special intro card that matches card name == list name)
            |__ Recipe (Card)

        */
        $page_bodies = [];

        $site_root = new \PageObject\PageObject();
        $site_root->set('path' , '/');
        $site_root->set('site_title', $board->name);
        $site_root->set('slug' , $board->name);

        $site_root->set('title', $config['home_page_title']);
        // I need to figure out the best way of favouring a list that matches the board name (and first card
        // name) as the body content. It's useful to have both options. The issue is that the board name may
        // be more useable being called 'My Site Content' or similar, so how to make a certain association?
        // Just checking if 'list name in board name' would be really flakey.
        /*
        if (<<theres a list for the home page>>) {
            $site_root->set('title', <<the name of that list>>);
        }
        */
        if (!empty($config['head_stylesheets'])) {
            $site_root->set('head_stylesheets', $config['head_stylesheets']);
        }
        if (!empty($config['head_style'])) {
            $site_root->set('head_style', $config['head_style']);
        }
        



        #$site_root->set('body' , Markdown::defaultTransform($board->desc));
        $page_body = $board->desc;
        #$page_bodies[] = $page_body;
        $site_root->set('body' , $page_body);
        $page_bodies[] =& $site_root->get('body');

        #$page_bodies[0] = 'Test';

        #echo '<pre>'; var_dump($site_root); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($page_bodies); echo '</pre>'; exit;

        $field_name_map      = [];
        $field_type_map      = [];
        $parent_pages        = [];
        $parent_id_path_map  = [];
        $parent_id_name_map  = [];
        $folders_list_id_map = [];



        foreach ($fields as $field) {
            $field_name_map[$field->id] = $field->name;
            $field_type_map[$field->id] = $field->type;
        }
        #echo '<pre>'; var_dump($field_name_map); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($field_type_map); echo '</pre>'; exit;

        $tasks = $config['tasks'];
        $tasks_found = [];

        foreach ($lists as $list) {
            // Handle lists by naming convention:
            #echo '<pre>'; var_dump($list->name); echo '</pre>'; #exit;
            if (substr($list->name, 0, 1) == '/') {
                // This list is a folder, so create it and add to list:
                $dir_name = $config['dpc_root'] . $list->name;
                if (!file_exists($dir_name)) {
                    mkdir($dir_name);
                }
                $folders_list_id_map[$list->id] = $dir_name;
                $task_name = trim($list->name, '/');
                if (array_key_exists($task_name, $tasks)) {
                    $tasks_found[$task_name] = [];
                }
                continue;
            }

            $parent_page = new \PageObject\PageObject();

            $parent_page->set('site_title', $board->name);
            $parent_page->set('title', $list->name);
            $parent_page->set('slug',  $list->name);
            $parent_page->set('head_stylesheets', $config['head_stylesheets']);
            $parent_page->set('head_style', $config['head_style']);
            $slug = $parent_page->get('slug');

            $path = $site_root->get('path') . $slug . '/';

            $parent_page->set('path', $path);
            $parent_page->setParent($site_root);

            $parent_pages[$path]           = $parent_page;
            $parent_id_path_map[$list->id] = $path;
            $parent_id_name_map[$list->id] = $list->name;
        }
        #echo '<pre>'; var_dump($folders_list_id_map); echo '</pre>'; #exit;
        #echo '<pre>'; var_dump($tasks); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($site_root); echo '</pre>'; exit;

        foreach ($cards as $card) {

            if (array_key_exists($card->idList, $folders_list_id_map)) {
                // Create files and folders in the card name:
                $dir          = $folders_list_id_map[$card->idList];
                $path_info    = pathinfo($card->name);

                if ($path_info['dirname'] != '.')  {
                    $dir         .= '/'  . $path_info['dirname'];
                    $parent_perms = fileperms($folders_list_id_map[$card->idList]);
                    if (!file_exists($dir)) {
                        mkdir($dir, $parent_perms, true);
                    }
                }
                $file_path = $dir . '/' . $path_info['basename'];
                file_put_contents($file_path, $card->desc);

                $path_segments = explode('/', $dir);
                $task_name = array_pop($path_segments);
                if (array_key_exists($task_name, $tasks)) {
                    $tasks_found[$task_name][] = ['task' => $path_info['filename'], 'file' => $file_path];
                }

                continue;
            }

            $parent_path = $parent_id_path_map[$card->idList];

            #echo '<pre>'; var_dump($card->name); echo '</pre>'; #exit;
            #echo '<pre>'; var_dump($parent_id_name_map[$card->idList]); echo '</pre>'; #exit;

            $page_body = $card->desc;
            #$page_bodies[] = $page_body;

            #if ($card->name == '_intro') {
            if ($card->name == $parent_id_name_map[$card->idList]) {
                //$parent_pages[$parent_path]->set('body', Markdown::defaultTransform($card->desc));
                $parent_pages[$parent_path]->set('body', $page_body);
                $page_bodies[] =& $parent_pages[$parent_path]->get('body');
                continue;
            }

            $child_page = new \PageObject\PageObject();
            $child_page->set('site_title', $board->name);
            $child_page->set('title', $card->name);
            $child_page->set('slug',  $card->name);
            $child_page->set('head_stylesheets', $config['head_stylesheets']);
            $child_page->set('head_style', $config['head_style']);
            //$child_page->set('body',  Markdown::defaultTransform($card->desc));
            $child_page->set('body', $page_body);
            $page_bodies[] =& $child_page->get('body');

            $path = $parent_path .  $child_page->get('slug') . '/';
            $child_page->set('path', $path);

            foreach ($card->customFieldItems as $field) {
                $field_name = $child_page->slugify($field_name_map[$field->idCustomField]);
                $field_type = $field_type_map[$field->idCustomField];

                switch ($field_type) {
                    case 'number' :
                        $child_page->set(['body_data', $field_name], $field->value->number);
                        break;
                    case 'text' :
                        $child_page->set(['body_data', $field_name], $field->value->text);
                        break;
                }
                #@TODO add the rest of the types.
            }
            $child_page->setParent($parent_pages[$parent_path]);
            #$parent_pages[$parent_path]->set(['children', $path], $child_page);
        }

        #$site_root->set(['children'], $parent_pages);

        $site_data = $site_root->getArrayPage();
        // Now we have the complete site data, we need to render any twig in the body content, then markdown:
        $twig_environment = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
        #echo '<pre>'; var_dump($page_bodies); echo '</pre>'; exit;
        foreach ($page_bodies as $key => &$page_body) {
            $template = $twig_environment->createTemplate($page_body);
            $rendered = $template->render(['data' => ['site' => $site_data]]);
            $transformed = Markdown::defaultTransform($rendered);
            $page_body = $transformed;
            #$page_bodies[$key] = $transformed;
        }
        unset($page_body);
        #echo '<pre>'; var_dump($page_bodies); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($site_root); echo '</pre>'; exit;

        #exit;
        #echo '<pre>'; var_dump($site_root); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($parent_pages); echo '</pre>'; exit;
        #echo '<pre>'; var_dump($config['twig_handler']['data']); echo '</pre>'; exit;

        #######
        /*
            This bit should be generic, just figuring it out for now:
            PageObjectTwigBulder

        */

        #var_dump(iterator_to_array($site_root));


        /*
        function traverseStructure($iterator) {

            while ( $iterator->valid() ) {
        echo $iterator->key() . ' : ' . $iterator->current() . PHP_EOL;
                if ( $iterator->hasChildren() ) {

                    traverseStructure($iterator->getChildren());

                }
                else {
                    echo $iterator->key() . ' : ' . $iterator->current() . PHP_EOL;
                }

                $iterator->next();
            }
        }
        $iterator = new RecursiveArrayIterator($site_root);
        iterator_apply($iterator, 'traverseStructure', array($iterator));

        */
        //$array is your multi-dimensional array

        /*
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator(
                $site_root,
                RecursiveArrayIterator::CHILD_ARRAYS_ONLY
            )
        );

        foreach ($iterator as $key=>$value) {
           echo $iterator->key() . ' : ' . $iterator->current() . PHP_EOL;
        }
        */
        /*
        $recursiveIterator = new \RecursiveIteratorIterator($site_root, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($recursiveIterator as $path=>$page) {
            echo '<pre>'; var_dump($page); echo '</pre>'; #exit;

            $filename = $page->get('path') . 'index.html.twig';
            #echo '<pre>'; var_dump($filename); echo '</pre>';
        }
        */




        #echo '<pre>'; var_dump($site_root); echo '</pre>'; exit;

        $this->generateTwigFile($site_root, $config['dpc_input_dir']);
        #######


        $dependency_handers = [];

        // This will be in the main TrelloStuff class, and the key will come from the handlers $list_name
        $dependency_handers['github'] = new GithubHandler($config);
        $style_handers['scss'] = new ScssHandler($config);

        #echo '$tasks_found<pre>'; var_dump($tasks_found); echo '</pre>';exit;

        // TASKS

        if (!empty($tasks_found)) {

            // Dependencies first:
            if (!empty($tasks_found['_dependencies'])) {
                $dependencies = $tasks_found['_dependencies'];

                foreach ($dependencies as $dependency) {
                    $task_name = $dependency['task'];
                    if (array_key_exists($task_name, $dependency_handers)) {
                        $handler_config = json_decode(file_get_contents($dependency['file']), true);

                        if (is_array($handler_config)) {
                            $path_info = pathinfo($dependency['file']);

                            $sub_dir = $path_info['dirname'] . '/' . $task_name;
                            if (!file_exists($sub_dir)) {
                                $parent_perms = fileperms($path_info['dirname']);
                                mkdir($sub_dir, $parent_perms, true);
                            }

                            $dependency_handers[$task_name]->run($sub_dir, $handler_config);
                        }
                    }
                }
            }

            // Styles next:
            if (!empty($tasks_found['_styles'])) {
                $styles = $tasks_found['_styles'];

                foreach ($styles as $style) {
                    $file_name = $style['file'];
                    $path_info = pathinfo($file_name);
                    if (file_exists($file_name) && array_key_exists($path_info['extension'], $style_handers)) {
                        $style_handers[$path_info['extension']]->run($file_name);
                    }

                }
            }
        }

        #exit;

        return $site_root;

    }
}


// Note this is all a mess / proof of concept and would need to be tidied up into it's own lib.
class GithubHandler
{
    /**
     * Stores the config data
     * @var array
     **/
    protected $c = [];

    /**
     * Stores the manifest data
     * @var array
     **/
    protected $m = [];

    public $list_name = 'github';

    /**
     * GithubHandler::__construct()
     *
     * @param Array $config DirProcessCopy config
     */
    public function __construct($config)
    {
        #echo 'GithubHandler<pre>'; var_dump($config); echo '</pre>';#exit;
        #$this->plugins_dir = dirname(__DIR__) . '/Plugins/';
        $this->c = $config;
        #$this->registerPlugins();
    }

    public function run($dest, array $manifest) {
        $this->m = $manifest;

        $tmp_dir = $this->c['dpc_tmp_dir'];
        #echo 'GithubHandler<pre>'; var_dump($manifest); echo '</pre>';#exit;
        if (is_array($this->m)) {
            foreach ($this->m as $name => $src_path) {
                $path_info = pathinfo($src_path);
                $tmp_path = $tmp_dir . '/' . $name . '.' . $path_info['extension'];
                $out_dir = $dest. '/' . $name;

                if (!$this->c['use_cache'] || !file_exists($out_dir)) {
                    #echo 'GithubHandler<pre>'; var_dump($tmp_path); echo '</pre>';#exit;
                    file_put_contents($tmp_path, file_get_contents($src_path));

                    $zip_archive = new \ZipArchive();
                    $result = $zip_archive->open($tmp_path);
                    if ($result === true) {
                        $zip_archive->extractTo($out_dir);
                        $zip_archive->close();
                    }
                }
            }
        }
    }


}

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use ScssPhp\Server\Server;
class ScssHandler
{
    /**
     * Stores the config data
     * @var array
     **/
    protected $c = [];

    public $list_name = 'styles';

    /**
     * GithubHandler::__construct()
     *
     * @param Array $config DirProcessCopy config
     */
    public function __construct($config)
    {
        #echo 'GithubHandler<pre>'; var_dump($config); echo '</pre>';#exit;
        #$this->plugins_dir = dirname(__DIR__) . '/Plugins/';
        $this->c = $config;
        #$this->registerPlugins();
        $parent_perms = fileperms($this->c['dpc_output_dir']);

        $css_dir  = $this->c['dpc_output_dir'] . '/css';
        if (!file_exists($css_dir)) {
            mkdir($css_dir, $parent_perms, true);
        }

        $cache_dir  = $this->c['dpc_root'] . '/_styles/scss_cache';
        if (!file_exists($cache_dir)) {

            mkdir($cache_dir, 0755, true);
        }
    }

    public function run($file) {
        if (!file_exists($file)) {
            return false;
        }

        $path_info = pathinfo($file);
        $scss_dir = $path_info['dirname'];
        $css_dir  = $this->c['dpc_output_dir'] . '/css';

        $compiler = new Compiler();
        $compiler->setImportPaths($scss_dir);

        if (isset($this->c['tasks']['_dependencies'])) {
            $p = $this->c['dpc_root'] . '/_dependencies/' . $this->c['tasks']['_dependencies'];
            $compiler->addImportPath($p);
        }

        #echo 'scss_dir<pre>'; var_dump($scss_dir); echo '</pre>';#exit;
        #echo 'scss_dir<pre>'; var_dump($p); echo '</pre>';#exit;
        $compiler->setOutputStyle(OutputStyle::EXPANDED);

        $server1 = new Server($scss_dir, null, $compiler);
        $server1->compileFile($scss_dir . '/' . $path_info['basename'], $scss_dir . '/' . $path_info['filename'] . '.css');
        /*echo 'JSubMenuHelper error appears here. THe SCSS doesn\'t work either :-('; exit;

        $compiler->setOutputStyle(OutputStyle::COMPRESSED);

        $server2 = new Server($directory1, null, $compiler);
        $server2->compileFile($directory1 . '/theme-npeu.scss', $directory2 . '/theme-npeu.min.css');*/

    }
}