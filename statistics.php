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
    protected $plugin_enabled = false;
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

        $views = $this->getTotalsAll();
        foreach ($views as $path => $data) {
            $summary = array('views' => $data, 'likes' => $this->getLikes($path));
            $views[$path] = $summary;
        }

        $this->grav['twig']->views = $views;
    }

    public function initializeFrontend()
    {
        $this->calculatePluginEnabled();
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->plugin_enabled) {
            $this->enable([
                'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
                'onTask.likes.like'    => ['likesController', 0],
                'onTask.likes.dislike' => ['likesController', 0]
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

    public function onTwigSiteVariables()
    {
        $path = $this->grav['uri']->path();
        $likes = $this->getLikes($path);
        $user_like = $this->isUserLikePage($path);
        
        $this->grav['twig']->twig_vars['enable_statistics_plugin'] = $this->plugin_enabled;
        $this->grav['twig']->twig_vars['likes'] = $likes;
        $this->grav['twig']->twig_vars['user_like'] = $user_like;
    }

    public function likesController()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $uid = "";
        $path = $uri->path();
        $task = $_POST['task'] ?? $uri->param('task');

        if (!$this->active) {
            return;
        }

        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            $uid = $user->authenticated ? $user->username : "";
        }

        if (empty($uid)) {
            return;
        }

        switch ($task) {
            case 'likes.like':
                $this->updateLikes($path, $uid, self::LIKE);
                break;
            case 'likes.dislike':
                $this->updateLikes($path, $uid, self::DISLIKE);
                break;
        }
    }

    private function calculatePluginEnabled() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.statistics.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.statistics.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->plugin_enabled = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->plugin_enabled = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * @param string $path
     * 
     */
    protected function updateLikes(string $path, string $uid, bool $action)
    {
        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (!array_key_exists($path, $this->likes_data)) {
            $this->likes_data[$path] = array();
            if ($action === self::LIKE) {
                array_push($this->likes_data[$path], $uid);
            }
        } else {
            if (($action === self::LIKE) && !in_array($uid, $this->likes_data[$path])) {
                array_push($this->likes_data[$path], $uid);
            } else if (($action === self::DISLIKE) && in_array($uid, $this->likes_data[$path])) {
                $key = array_search($uid, $this->likes_data[$path]);
                unset($this->likes_data[$path][$key]);
            }
        }

        file_put_contents($this->likes_file, json_encode($this->likes_data));
    }

    /**
     * @return bool
     */
    protected function isUserLikePage(string $path)
    {
        $uid = "";
        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            $uid = $user->authenticated ? $user->username : "";
        }

        if (empty($uid)) {
            return false;
        }

        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (array_key_exists($path, $this->likes_data)) {
            if (in_array($uid, $this->likes_data[$path])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int
     */
    protected function getLikes(string $path)
    {
        if (!$this->likes_data) {
            $this->likes_data = $this->getData($this->likes_file);
        }

        if (array_key_exists($path, $this->likes_data)) {
            return count($this->likes_data[$path]);
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