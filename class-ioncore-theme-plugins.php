<?php
/**
 * Ioncore theme plugins.
 */

/**
 * Ioncore theme plugins class.
 *
 * @codingStandardsIgnoreLine
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Ioncore_Theme_Plugins { //@codingStandardsIgnoreLine

    /**
     * Single theme instance.
     *
     * @var Ioncore_Theme_Plugins
     */
    protected static $instance;

    /**
     * Configuration
     *
     * @var object
     */
    protected $config;

    /**
     * List of all plugins.
     *
     * @var array
     */
    protected $plugins = array();

    /**
     * List of local plugins.
     *
     * @var array
     */
    protected $local = array();

    /**
     * List of installed local plugins that need to be updated.
     *
     * @var array|boolean
     */
    protected $updates;

    /**
     * List of installed local plugins that are updated.
     *
     * @var array|boolean
     */
    protected $no_updates;


    /**
     * Protected constructor.
     *
     * @param array $config Configuration.
     *
     * @access protected.
     * @return void
     */
    protected function __construct(array $config) {
        $this->config = (object) $config;
        $this->init();

    }//end __construct()


    /**
     * Add default values for missing local plugin values.
     *
     * @param array $info Plugin configuration.
     *
     * @access protected.
     * @return object|boolean Returns plugin configuration object or false if configuration is missing needed fields.
     */
    protected function get_plugin_configuration(array $info) {
        $plugin = (object) $info;
        if (empty($plugin->download_link) === true) {
            return false;
        }

        $file = $plugin->download_link;
        if (file_exists($file) === false) {
            return false;
        }

        $plugin->last_updated = gmdate('Y-m-d H:i:s', filemtime($file));

        if (empty($plugin->author) === true) {
            $plugin->author = 'Author';
        }

        if (empty($plugin->rating) === true) {
            $plugin->rating = 100;
        }

        if (empty($plugin->num_ratings) === true) {
            $plugin->num_ratings = 100;
        }

        if (empty($plugin->active_installs) === true) {
            $plugin->active_installs = 100;
        }

        if (empty($plugin->icons) === true) {
            $plugin->icons = array('default' => '');
        }

        return $plugin;

    }//end get_plugin_configuration()


    /**
     * Parse the configuration object.
     *
     * @access protected.
     * @return boolean Returns true if valid configuration was found.
     */
    protected function parse_config() {
        if (empty($this->config->plugins) === true || is_array($this->config->plugins) === false) {
            return false;
        }

        $plugins = $this->config->plugins;

        foreach ($plugins as $slug => $info) {
            if ($info !== true) {
                // Local plugin.
                $plugin = $this->get_plugin_configuration($info);

                if ($plugin !== false) {
                    $this->local[$slug] = $plugin;
                }
            }//end if

            $this->plugins[] = $slug;
        }//end foreach

        $this->plugins = array_reverse($this->plugins);
        return true;

    }//end parse_config()


    /**
     * Initialize class.
     *
     * @access protected.
     * @return void
     */
    protected function init() {
        if ($this->parse_config() === false) {
            return;
        }

        add_action('admin_menu', array($this,'admin_menu_action'));
        add_action('admin_init', array($this,'admin_init_action'));
        add_filter('plugins_api', array($this,'plugins_api_filter'), 10, 3);
        add_action('current_screen', array($this,'current_screen_action'));
        add_filter('pre_set_site_transient_update_plugins', array($this,'update_plugins_filter'));

    }//end init()


    /**
     * Returns an array of installed local plugins.
     *
     * @access public.
     * @return array Installed local plugins.
     */
    public function installed_local_plugins() {
        $installed = array();
        foreach ($this->local as $slug => $local) {
            $plugin = get_plugins('/'.$slug);
            if (empty($plugin) === false) {
                $key = array_keys($plugin);
                $key = reset($key);
                $local->slug = $slug;
                $local->installed = (object) reset($plugin);
                $installed[$slug.'/'.$key] = $local;
            }
        }

        return $installed;

    }//end installed_local_plugins()


    /**
     * Adds local plugins update informations.
     *
     * @param object $updates Updates object.
     *
     * @access public.
     * @return object
     */
    public function update_plugins_filter($updates) {
        if (isset($updates->response) === false) {
            $updates->response = array();
        }

        if (isset($updates->no_update) === false) {
            $updates->no_update = array();
        }

        $local_updates = $this->updates();
        if ($local_updates !== false) {
            $updates->response = array_merge($updates->response, $local_updates);
            foreach (array_keys($local_updates) as $key) {
                unset($updates->no_update[$key]);
            }
        }

        $local_no_updates = $this->no_updates();
        if ($local_no_updates !== false) {
            $updates->no_update = array_merge($updates->no_update, $local_no_updates);
            foreach (array_keys($local_no_updates) as $key) {
                unset($updates->response[$key]);
            }
        }

        return $updates;

    }//end update_plugins_filter()


    /**
     * Fetches plugin information using WordPress plugin api.
     *
     * @param object $args Search paramenters.
     *
     * @access protected.
     * @return object
     */
    protected function get_plugins_info($args) {
        $args->browse = '';
        $args->fields['group'] = false;
        $args->fields['description'] = false;
        $args->fields['short_description'] = true;
        foreach ($this->plugins as $slug) {
            $args->slug = $slug;
            $plugin = plugins_api('plugin_information', $args);
            if (is_wp_error($plugin) === false) {
                $plugins[] = $plugin;
            }
        }

        $res = (object) array(
            'info' => array(
                'page' => 1,
                'pages' => 1,
                'results' => count($plugins),
            ),
            'plugins' => $plugins,
        );
        return $res;

    }//end get_plugins_info()


    /**
     * Adds local plugins information when 'plugin_information' api method is used.
     *
     * @param object|boolean $res    Result.
     * @param string         $action Api method.
     * @param object         $args   Search paramenters.
     *
     * @access public.
     * @return object|boolean Result.
     */
    public function plugins_api_filter($res, $action, $args) {
        if ($action === 'plugin_information' && empty($this->local[$args->slug]) === false) {
            $res = $this->local[$args->slug];
            $res->slug = $args->slug;
        }//end if
        return $res;

    }//end plugins_api_filter()


    /**
     * Rewrites the activation link when using multisite.
     *
     * @param array $links Action links.
     *
     * @access public.
     * @return array Rewritten links.
     */
    public function rewrite_activation(array $links) {
        if (empty($links) === false && strpos($links[0], 'action=activate') !== false) {
            $links[0] = str_replace(network_admin_url('plugins.php'), self_admin_url('plugins.php'), $links[0]);
        }

        return $links;

    }//end rewrite_activation()


    /**
     * Rewrites the install link when using multisite.
     *
     * @param array $links Action links.
     *
     * @access public.
     * @return array Rewritten links.
     */
    public function rewrite_install(array $links) {
        if (empty($links) === false && strpos($links[0], 'action=install-plugin') !== false) {
            $links[0] = str_replace('?', '?pagenow=import&', $links[0]);
        }

        return $links;

    }//end rewrite_install()


    /**
     * Rewrites the plugin-information link when using multisite.
     *
     * @param array $links Action links.
     *
     * @access public.
     * @return array Rewritten links.
     */
    public function rewrite_information(array $links) {
        if (empty($links[1]) === false && strpos($links[1], '#') !== false) {
            unset($links[1]);
        }

        return $links;

    }//end rewrite_information()


    /**
     * Filters out the thickbox link in multisite mode.
     *
     * @param string $url Action links.
     *
     * @access public.
     * @return string Filtered url.
     */
    public function clean_url_filter($url) {
        if (empty($url) === false && strpos($url, 'tab=plugin-information') !== false) {
            $url = '#';
        }

        return $url;

    }//end clean_url_filter()


    /**
     * Intercept 'query_plugins' api search and replaces it with the custom list of theme required plugins.
     *
     * @param object|boolean $res    Result.
     * @param string         $action Api method.
     * @param object         $args   Search paramenters.
     *
     * @access public.
     * @return object|boolean Search results.
     */
    public function replace_query($res, $action, $args) {
        if ($action === 'query_plugins') {
            $res = $this->get_plugins_info($args);
        }//end if

        return $res;

    }//end replace_query()


    /**
     * Adds various hooks only in the class admin page.
     *
     * @access public.
     * @return void
     */
    public function current_screen_action() {
        $screen = get_current_screen();
        if (empty($screen) === false && $screen->id === 'appearance_page_ioncore-theme-plugins') {
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts_action'));
            add_filter('plugins_api', array($this,'replace_query'), 10, 3);
            if (is_multisite() === true) {
                add_filter('plugin_install_action_links', array($this,'rewrite_activation'), 10, 1);
                add_filter('plugin_install_action_links', array($this,'rewrite_install'), 10, 1);
                add_filter('plugin_install_action_links', array($this,'rewrite_information'), 10, 1);
                add_filter('clean_url', array($this,'clean_url_filter'), 10, 1);
            }
        }

    }//end current_screen_action()


    /**
     * Enqueue the custom js script.
     *
     * @access public.
     * @return void
     */
    public function admin_enqueue_scripts_action() {
        $rel = str_replace(realpath(get_stylesheet_directory()), '', dirname(__FILE__));
        wp_register_script(
            'ioncore-theme-plugins',
            get_theme_file_uri("/$rel/js/ioncore-theme-plugins.js"),
            array('updates', 'plugin-install')
        );

        wp_localize_script(
            'ioncore-theme-plugins',
            'ioncore_theme_plugins',
            array('multisite' => is_multisite())
        );
        wp_enqueue_script('ioncore-theme-plugins');

        // Disable thickbox in multisite because it throws security violation.
        if (is_multisite() === false) {
            add_thickbox();
        }

    }//end admin_enqueue_scripts_action()


    /**
     * Checks if local plugin need to be updated.
     *
     * @access protected.
     * @return object|boolean List of plugins that have to be updated.
     */
    protected function updates() {
        if (isset($this->updates) === true) {
            return $this->updates;
        }

        $installed = $this->installed_local_plugins();

        if (empty($installed) === true) {
            $this->updates = false;
            $this->no_updates = false;
            return false;
        }

        $updates = array();
        $no_updates = array();
        foreach ($installed as $file => $plugin) {
            $entry = (object) array(
                'slug' => $plugin->slug,
                'plugin' => $file,
                'local' => true,
                'new_version' => $plugin->version,
                'package' => $plugin->download_link,
            );
            if (version_compare($plugin->version, $plugin->installed->Version, '>') === true) {
                $updates[$file] = $entry;
            } else {
                $no_updates[$file] = $entry;
            }
        }

        if (empty($updates) === true) {
            $updates = false;
        }

        if (empty($no_updates) === true) {
            $no_updates = false;
        }

        $this->updates = $updates;
        $this->no_updates = $no_updates;
        return $updates;

    }//end updates()


    /**
     * List of local plugins already updated to the latest version.
     *
     * @access protected.
     * @return object|boolean List of plugins that do not have to be updated.
     */
    protected function no_updates() {
        $this->updates();
        return $this->no_updates;

    }//end no_updates()


    /**
     * Deletes the update transient when it doesn't includes updated informations about local plugin versions.
     *
     * @access public.
     * @return void
     */
    public function force_update() {
        $delete = false;
        $update = get_site_transient('update_plugins');
        if (empty($update) === true) {
            return;
        }

        $local_updates = $this->updates();
        if ($local_updates !== false) {
            foreach (array_keys($local_updates) as $file) {
                if (empty($update->response[$file]) === true) {
                    $delete = true;
                    break;
                }
            }
        }

        $no_updates = $this->no_updates();
        if ($no_updates !== false) {
            foreach (array_keys($no_updates) as $file) {
                if (empty($update->no_update[$file]) === true) {
                    $delete = true;
                    break;
                }
            }
        }

        if ($delete === true) {
            delete_site_transient('update_plugins');
        }

    }//end force_update()


    /**
     * Check for local plugin updates.
     *
     * @access public.
     * @return void
     */
    public function admin_init_action() {
        $this->force_update();

    }//end admin_init_action()


    /**
     * Adds the custom menu.
     *
     * @access public.
     * @return void
     */
    public function admin_menu_action() {
        $title = esc_attr($this->config->title);
        add_theme_page($title, $title, 'edit_theme_options', 'ioncore-theme-plugins', array($this, 'admin_page'));

    }//end admin_menu_action()


    /**
     * Renders the admin page.
     *
     * @access public.
     * @return void
     */
    public function admin_page() {
        $wp_list_table = _get_list_table('WP_Plugin_Install_List_Table');
        $wp_list_table->prepare_items();

        do_action('ioncore_theme_plugins_pre');
        printf('<form id="%s" method="%s">', 'plugin-filter', 'post');
        $wp_list_table->display();
        printf('</form>');
        wp_print_request_filesystem_credentials_modal();
        wp_print_admin_notice_templates();
        do_action('ioncore_theme_plugins_post');

    }//end admin_page()


    /**
     * Returns the single class instance.
     *
     * @param array $config Configuration.
     *
     * @access public.
     * @return IoncoreThemePlugins
     */
    public static function instance(array $config) {
        if (isset(static::$instance) === false) {
            static::$instance = new static($config);
        }

        return static::$instance;

    }//end instance()


}//end class
