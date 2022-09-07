<?php
namespace Grav\Plugin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\User\User;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class StatisticsPlugin extends Plugin
{
    protected $route = 'stats';
    protected $enable = false;
    protected $page_stats_cache_id;

    protected $data_path;
    protected $totals_file;
    protected $likes_file;
    protected $totals_data;
    protected $likes_data;

    const TOTALS_FILE = 'totals.json';
    const LIKES_FILE = 'likes.json';
    const LIKE = true;
    const DISLIKE = false;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        $this->data_path = Grav::instance()['locator']->findResource('log://popularity', true, true);
        $this->likes_file = $this->data_path . '/' . self::LIKES_FILE;
        $this->totals_file   = $this->data_path . '/' . self::TOTALS_FILE;
        
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    public function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $this->grav['twig']->likes = $this->getLikesAll();
        $this->grav['twig']->views = $this->getTotalsAll();
    }

    public function initializeFrontend()
    {
        $this->calculateEnable();
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onPageInitialized' => ['onPageInitialized', 10],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_STATISTICS.STATISTICS'] = ['route' => $this->route, 'icon' => 'fa-bar-chart'];
    }

    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'statistics';
    }

    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.statistics.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables() {
        $path = $this->grav['uri']->path();
        $enabled = $this->enable;
        $likes = $this->getLikes($path);
        
        $this->grav['twig']->twig_vars['enable_statistics_plugin'] = $enabled;
        $this->grav['twig']->twig_vars['statistics'] = $likes; 
    }

    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        $uid = "";
        $path = $this->grav['uri']->path();

        if (!$this->active) {
            return;
        }

        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            $uid = $user->authenticated ? $user->ID : "";
        }

        if (empty($uid)) {
            return;
        }

        
        switch ($action) {
            case 'upvote':
                $this->updateLikes($path, $uid, LIKE);
                break;
            case 'downvote':
                $this->updateLikes($path, $uid, DISLIKE);
                break;
        }
    }

    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.statistics.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.statistics.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * @param string $url
     * 
     */
    protected function updateLikes(string $url, string $uid, boolean $action)
    {
        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (!array_key_exists($url, $this->likes_data)) {
            if ($action === LIKE) {
                array_push($this->likes_data[$url], $uid);
            }
        } else {
            if (($action === LIKE) && !in_array($uid, $this->likes_data[$url])) {
                array_push($this->likes_data[$url], $uid);
            } else if (($action === DISLIKE) && in_array($uid, $this->likes_data[$url])) {
                $key = array_search($uid, $this->likes_data[$url]);
                unset($this->likes_data[$url][$key]);
            }
        }

        file_put_contents($this->likes_file, json_encode($this->likes_data));
    }

    /**
     * @return int
     */
    protected function getLikes($url)
    {
        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (array_key_exists($url, $this->likes_data)) {
            return $this->likes_data[$url];
        }

        return 0;
    }

    /**
     * @return array
     */
    protected function getLikesAll()
    {
        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (isset($this->likes_data)) {
            return $this->likes_data;
        }

        return [];
    }

    /**
     * @return array
     */
    protected function getTotalsAll()
    {
        if (!$this->totals_data) {
            $this->totals_data = $this->getData($this->totals_file);
        }

        if (isset($this->totals_data)) {
            return $this->totals_data;
        }

        return [];
    }

    /**
     * @param string $path
     * @return array
     */
    protected function getData(string $path)
    {
        if (file_exists($path)) {
            return (array)json_decode(file_get_contents($path), true);
        }

        return [];
    }
}