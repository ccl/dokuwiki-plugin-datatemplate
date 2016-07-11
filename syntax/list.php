<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@unige.ch>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

// Check for presence of data plugin
$dataPluginFile = DOKU_PLUGIN.'data/syntax/table.php';
if(file_exists($dataPluginFile)){
    require_once $dataPluginFile;
} else {
    msg('datatemplate: Cannot find Data plugin.', -1);
    return;
}

require_once(DOKU_PLUGIN.'datatemplate/syntax/inc/cache.php');

/**
 * This inherits from the table syntax of the data plugin, because it's basically the
 * same, just different output
 */
class syntax_plugin_datatemplate_list extends syntax_plugin_data_table {

    var $dtc = null; // A cache instance
    /**
     * Constructor.
     */
    function __construct(){
        parent::__construct();
        $this->dtc = new datatemplate_cache($this->dthlp);
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *datatemplatelist(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',
                                        $mode, 'plugin_datatemplate_list');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        // We want the parent to handle the parsing, but still accept
        // the "template" paramter. So we need to remove the corresponding
        // line from $match.
        $template = '';
        $lines = explode("\n", $match);
        foreach ($lines as $num => $line) {
            // ignore comments
            $line = preg_replace('/(?<![&\\\\])#.*$/', '', $line);
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/', $line, 2);
            if (strtolower($line[0]) == 'template') {
                $template = $line[1];
                unset($lines[$num]);
            }
        }
        $match = implode("\n", $lines);
        $data = parent::handle($match, $state, $pos, $handler);
        if(!empty($template)) {
            $data['template'] = $template;
        }

        // For caching purposes, we always need to query the page id.
        if(!array_key_exists('%pageid%', $data['cols'])) {
            $data['cols']['%pageid%'] = array('multi' => '', 'key' => '%pageid%',
                                              'title' => 'Title', 'type' => 'page');
            if(array_key_exists('headers', $data))
                array_push($data['headers'], '%pageid%');
        }
        return $data;
    }

    /**
     * The _buildSQL routine of the data table class considers also filtering and
     * limits passed via $_REQUEST. For efficient caching, we need to bypass these once in while.
     * For this purpose, this function strips $_REQUEST of the unwanted fields before calling
     * _buildSQL.
     *
     * @param array $data from the handle function
     * @return string SQL
     */
    function _buildSQL(&$data) {
        // First remove unwanted fields.
        $limit = $data['limit'];
        $dataofs = $_REQUEST['dataofs'];
        $dataflt = $_REQUEST['dataflt'];
        unset($data['limit']);
        unset($_REQUEST['dataofs']);
        unset($_REQUEST['dataflt']);

        $sql = parent::_buildSQL($data);

        // Restore removed fields
        $data['limit'] = $limit;
        $_REQUEST['dataofs'] = $dataofs;
        $_REQUEST['dataflt'] = $dataflt;

        return $sql;
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $R, $data) {

        if(is_null($data)) return false;

        $sql = $this->_buildSQL($data);

        if($format == 'metadata') {
            // Remove metadata from previous plugin versions
            $this->dtc->removeMeta($R);
        }

        if($format == 'xhtml') {
            $R->info['cache'] = false;
            $this->dtc->checkAndBuildCache($data, $sql, $this);

            if(!array_key_exists('template', $data)) {
                // If keyword "template" not present, we will leave
                // the rendering to the parent class.
                msg("datatemplatelist: no template specified, using standard table output.");
                return parent::render($format, $R, $data);
            }

            $datarows = $this->dtc->getData($sql);
            $datarows = $this->_match_filters($data, $datarows);

            if(count($datarows) < $_REQUEST['dataofs']) $_REQUEST['dataofs'] = 0;

            $rows = array();
            $i = 0;
            $cnt = 0;
            foreach($datarows as $row) {
                $i++;
                if($i - 1 < $_REQUEST['dataofs']) continue;
                $rows[] = $row;
                $cnt++;

                if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
            }

            if ($cnt === 0) {
                $this->nullList($data, $clist = array(), $R);
                return true;
            }

            $wikipage = preg_split('/\#/u', $data['template'], 2);

            $R->doc .= $this->_renderPagination($data, count($datarows));
            $this->_renderTemplate($wikipage[0], $data, $rows, $R);
            $R->doc .= $this->_renderPagination($data, count($datarows));
            return true;
        }
        return false;
    }

    /**
     * Rendering of the template. The code is heavily inspired by the templater plugin by
     * Jonathan Arkell. Not taken into consideration are correction of relative links in the
     * template, and circular dependencies.
     *
     * @param string $wikipage the id of the wikipage containing the template
     * @param array $data output of the handle function
     * @param array $rows the result of the sql query
     * @param Doku_Renderer_xhtml $R the dokuwiki renderer
     * @return boolean Whether the page has been correctly (not: succesfully) processed.
     */
    function _renderTemplate($wikipage, $data, $rows, &$R) {
        global $ID;
        resolve_pageid(getNS($ID), $wikipage, $exists);          // resolve shortcuts

        // check for permission
        if (auth_quickaclcheck($wikipage) < 1) {
            $R->doc .= '<div class="datatemplatelist"> No permissions to view the template </div>';
            return true;
        }

        // Now open the template, parse it and do the substitutions.
        // FIXME: This does not take circular dependencies into account!
        $file = wikiFN($wikipage);
        if (!@file_exists($file)) {
            $R->doc .= '<div class="datatemplatelist">';
            $R->doc .= "Template {$wikipage} not found. ";
            $R->internalLink($wikipage, '[Click here to create it]');
            $R->doc .= '</div>';
            return true;
        }
        //collect column key names
        $clist = array_keys($data['cols']);

        // Construct replacement keys
        foreach ($clist as $num => $head) {
            $replacers['keys'][] = "@@" . $head . "@@";
            $replacers['raw_keys'][] = "@@!" . $head . "@@";
        }

        // Get the raw file, and parse it into its instructions. This could be cached... maybe.
        $rawFile = io_readfile($file);

        // embed the included page
        $R->doc .= "<div class=\"${data['classes']}\">";

        // We only want to call the parser once, so first do all the raw replacements and concatenate
        // the strings.
        $raw = "";
        $i = 0;
        $replacers['vals_id'] = array();
        $replacers['keys_id'] = array();
        foreach ($rows as $row) {
            $replacers['keys_id'][$i] = array();
            foreach($replacers['keys'] as $key) {
                $replacers['keys_id'][$i][] = "@@[" . $i . "]" . substr($key,2);
            }
            $replacers['vals_id'][$i] = array();
            $replacers['raw_vals'] = array();
            foreach($row as $num => $cval) {
                $replacers['raw_vals'][] = trim($cval);
                $replacers['vals_id'][$i][] = $this->dthlp->_formatData($data['cols'][$clist[$num]], $cval, $R);
            }

            // First do raw replacements
            $rawPart = str_replace($replacers['raw_keys'], $replacers['raw_vals'], $rawFile);
            // Now mark all remaining keys with an index
            $rawPart = str_replace($replacers['keys'], $replacers['keys_id'][$i], $rawPart);
            $raw .= $rawPart;
            $i++;
        }
        $instr = p_get_instructions($raw);

        // render the instructructions on the fly
        $text = p_render('xhtml', $instr, $info);
        // remove toc, section edit buttons and category tags
        $patterns = array('!<div class="toc">.*?(</div>\n</div>)!s',
                          '#<!-- SECTION \[(\d*-\d*)\] -->#e',
                          '!<div class="category">.*?</div>!s');
        $replace  = array('','','');
        $text = preg_replace($patterns,$replace,$text);
        // Do remaining replacements
        foreach($replacers['vals_id'] as $num => $vals) {
            $text = str_replace($replacers['keys_id'][$num], $vals, $text);
        }

        /** @deprecated 18 May 2013 column key names are used in stead of (localized) headers */
        if(strpos($text, '@@Page@@') !== false) {
            msg("datatemplate plugin: Use of @@Page@@ in '{$wikipage}' is deprecated. Replace it by @@%title%@@ please.", -1);
        }

        // Replace unused placeholders by empty string
        $text = preg_replace('/@@.*?@@/', '', $text);

        $R->doc .= $text;
        $R->doc .= '</div>';

        return true;
    }

    /**
     * Render page navigation area if applicable.
     *
     * @param array $data The output of the handle function.
     * @param int $numrows the total number of rows in the sql result.
     * @return string The html for the pagination.
     */
    function _renderPagination($data, $numrows) {
        global $ID;
        $text = '';
        // Add pagination controls
        if($data['limit']){
            $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
            //$params['datasrt'] = $_REQUEST['datasrt'];
            $offset = (int) $_REQUEST['dataofs'];
            if($offset){
                $prev = $offset - $data['limit'];
                if($prev < 0) $prev = 0;

                // keep url params
                $params['dataofs'] = $prev;

                $text .= '<a href="'.wl($ID,$params).
                    '" title="'.'Previous'.
                    '" class="prev">'.'&larr; Previous Page'.'</a>';
            } else {
                $text .= '<span class="prev disabled">&larr; Previous Page</span>';
            }

            for($i=1; $i <= ceil($numrows / $data['limit']); $i++) {
                $offs = ($i - 1) * $data['limit'];
                $params['dataofs'] = $offs;
                $selected = $offs == $_REQUEST['dataofs'] ? ' class="selected"': '';
                $text .= '<a href="'.wl($ID, $params).'"' . $selected . '>' . $i. '</a>';
            }

            if($numrows - $offset > $data['limit']){
                $next = $offset + $data['limit'];

                // keep url params
                $params['dataofs'] = $next;

                $text .= '<a href="'.wl($ID,$params).
                    '" title="'.'Next'.
                    '" class="next">'.'Next Page &rarr;'.'</a>';
            } else {
                $text .= '<span class="next disabled">Next Page &rarr;</span>';
            }
            return '<div class="prevnext">' . $text . '</div>';
        }
        return $text;
    }

    /**
     * Apply filters to the (unfiltered) sql output.
     *
     * @param array $data The output of the handle function.
     * @param array $datarows The output of the sql request
     * @return array The filtered sql output.
     */
    function _match_filters($data, $datarows) {
        /* Get whole $data as input and
         * - generate keys
         * - treat multi-value columns specially, i.e. add 's' to key and look at individual values
         */
        $out = array();
        $keys = array();
        foreach($data['headers'] as $k => $v) {
            $keys[$v] = $k;
        }
        $filters = $this->dthlp->_get_filters();
        if(!$datarows) return $out;
        foreach($datarows as $dr) {
            $matched = True;
            $datarow = array_values($dr);
            foreach($filters as $f) {
                if (strcasecmp($f['key'], 'any') == 0) {
                    $cols = array_keys($keys);
                } else {
                    $cols = array($f['key']);
                }
                $colmatch = False;
                foreach($cols as $col) {
                    $multi = $data['cols'][$col]['multi'];
                    if($multi) $col .= 's';
                    $idx = $keys[$col];
                    switch($f['compare']) {
                        case 'LIKE':
                            $comp = $this->_match_wildcard($f['value'], $datarow[$idx]);
                            break;
                        case 'NOT LIKE':
                            $comp = !$this->_match_wildcard($f['value'], $datarow[$idx]);
                            break;
                        case '=':
                            $f['compare'] = '==';
                        default:
                            $evalstr = $datarow[$idx] . $f['compare'] . $f['value'];
                            $comp = eval('return ' . $evalstr . ';');
                    }
                    $colmatch = $colmatch || $comp;
                }
                if($f['logic'] == 'AND') {
                    $matched = $matched && $colmatch;
                } else {
                    $matched = $matched || $colmatch;
                }
            }
            if($matched) $out[] = $dr;
        }
        return $out;
    }

    /**
     * Match string against SQL wildcards.
     * @param $wildcard_pattern
     * @param $haystack
     * @return boolean Whether the pattern matches.
     */
    function _match_wildcard( $wildcard_pattern, $haystack ) {
        $regex = str_replace(array("%", "\?"), // wildcard chars
                             array('.*','.'),   // regexp chars
                             preg_quote($wildcard_pattern)
            );
        return preg_match('/^\s*'.$regex.'$/im', $haystack);
    }

    function nullList($data, $clist, &$R) {
        $R->doc .= '<div class="templatelist">Nothing.</div>';
    }
}
/* Local Variables: */
/* c-basic-offset: 4 */
/* End: */
