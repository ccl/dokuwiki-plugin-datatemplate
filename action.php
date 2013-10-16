<?php
/**
 * Datatemplate plugin.
 *
 * Action plugin component, for cache validity determination
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@gmail.com>
 */
if(!defined('DOKU_INC')) die();  // no Dokuwiki, no go

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_datatemplate extends DokuWiki_Action_Plugin {

    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Christoph Clausen',
            'email'  => 'christoph.clausen@gmail.com',
            'date'   => '2011-08-27',
            'name'   => 'Datatemplate Plugin',
            'desc'   => 'A template extension for the data plugin',
            'url'    => 'http://www.dokuwiki.org/plugin:datatemplate',
            );
    }

    /**
     * plugin should use this method to register its handlers with the dokuwiki's event controller
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PARSER_CACHE_USE','BEFORE', $this, '_cache_prepare');
    }

    /**
     * prepare the cache object for default _useCache action
     */
    function _cache_prepare(&$event, $param) {
        $cache =& $event->data;

        // we're only interested in wiki pages and supported render modes
        if (!isset($cache->page)) return;
        if (!isset($cache->mode) || $cache->mode != 'metadata') return;

        $files = $this->_get_dependencies($cache->page);
        $cache->depends['files'] = array_merge($cache->depends['files'], $files);
    }

    /**
     * Get list of files that this page depends on. Usually, this would just be
     * a single template file.
     */
    function _get_dependencies($id) {
        $hasPart = p_get_metadata($id, 'relation haspart');
        if(empty($hasPart) || !is_array($hasPart)) return array();

        $files = array();
        foreach($hasPart as $file => $data) {
            if(empty($data['owner']) || $data['owner'] != $this->getPluginName()) continue;
            $files[] = $file;
        }
        return $files;
    }
}