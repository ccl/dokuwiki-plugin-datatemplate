<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@unige.ch>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class datatemplate_cache {

    /**
     * Remove any metadata that might have been stored by previous versions
     * of the plugin.
     * @param Doku_Renderer_metadata $renderer an instance of the dokuwiki renderer.
     */
    public function removeMeta(&$renderer) {
        global $ID;
        foreach(array_keys($renderer->persistent) as $key) {
            if(substr($key, 0, 12) == 'datatemplate') {
                unset($renderer->meta[$key]);
                unset($renderer->persistent[$key]);
            }
        }
    }

    /**
     * Check and generation of cached data to avoid
     * repeated use of the SQLite queries, which can end up
     * being quite slow.
     *
     * @param array $data from the handle function
     * @param string $sql stripped SQL for hash generation
     * @param syntax_plugin_datatemplate_list $dtlist reference, the calling datatemplate list instance
     */
    public function checkAndBuildCache($data, $sql, &$dtlist) {
        // We know that the datatemplate list has a datahelper.
        /** @var $sqlite helper_plugin_sqlite */
        $sqlite = $dtlist->dthlp->_getDB();

        // Build minimalistic data array for checking the cache
        $dtcc = array();
        $dtcc['cols'] = array(
            '%pageid%' => array(
                    'multi' => '',
                    'key' => '%pageid%',
                    'title' => 'Title',
                    'type' => 'page',
        ));
        // Apply also filters. Note, though, that (probably) only filters with respect
        // to the pageid are actually considered.
        $dtcc['filter'] = $data['filter'];
        $sqlcc = $dtlist->_buildSQL($dtcc);
        $res = $sqlite->query($sqlcc);
        $pageids = $sqlite->res2arr($res, $assoc = false);

        // Ask dokuwiki for cache file name
        $cachefile = getCacheName($sql, '.datatemplate');
        if(file_exists($cachefile))
            $cachedate = filemtime($cachefile);
        else
            $cachedate = 0;

        $latest = 0;
        if($cachedate) {
            // Check for newest page in SQL result
            foreach($pageids as $pageid) {
                $modified = filemtime(wikiFN($pageid[0]));
                $latest = ($modified > $latest) ? $modified : $latest;
            }
        }
        if(!$cachedate || $latest > (int) $cachedate  || isset($_REQUEST['purge'])) {
            $res = $sqlite->query($sql);
            $rows = $sqlite->res2arr($res, $assoc = false);
            file_put_contents($cachefile, serialize($rows), LOCK_EX);
        } else {
            // We arrive here when the cache seems up-to-date. However,
            // it is possible that the cache contains items which should
            // no longer be there. We need to find those and remove those.

            // $pageids is an array of arrays, where the latter only contain
            // one entry, the pageid. We need get rid of the second level of arrays:
            foreach($pageids as $k => $v) {
                $pageids[$k] = trim($v[0]);
            }

            // Then create map id->index for the pages that should be there.
            $dataitems = array_flip($pageids);

            // Do the same things for the pages that ARE there.
            // Figure out which row-element is the page id.

            $idx = 0;
            foreach($data['cols'] as $key=>$value) {
                if($key == '%pageid%') break;
                $idx++;
            }
            $cache = $this->getData($sql);
            $cacheitems = array();
            foreach($cache as $num=>$row) {
                $cacheitems[trim($row[$idx])] = $num;
            }
            // Now calculate the difference and update cache if necessary.
            $diff = array_diff_key($cacheitems, $dataitems);
            if(count($diff) > 0) {
                foreach($diff as $key => $num) {
                    unset($cache[$num]);
                }
                file_put_contents($cachefile, serialize($cache), LOCK_EX);
            }
        }
    }

    /**
     * Retrieve cached data.
     * @param string $sql the stripped sql for the data request
     * @return Array containing the rows of the cached sql result
     */
    public function getData($sql) {
        $cachefile = getCacheName($sql, '.datatemplate');
        $datastr = file_get_contents($cachefile);
        $data = unserialize($datastr);
        return $data;
    }
}