<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Clausen <christoph.clausen@unige.ch>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
$dataEntryFile = DOKU_PLUGIN.'data/syntax/table.php';
if(file_exists($dataEntryFile)){
	require_once $dataEntryFile;
} else {
	msg('datatemplate: Cannot find Data plugin.', -1);
	return;
}

/**
 * This inherits from the table syntax, because it's basically the
 * same, just different output
 */
class syntax_plugin_datatemplate_list extends syntax_plugin_data_table {

	/**
	 * Connect pattern to lexer
	 */
	function connectTo($mode) {
		$this->Lexer->addSpecialPattern('----+ *datatemplatelist(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_datatemplate_list');
	}

	function handle($match, $state, $pos, &$handler){
		// We want the parent to handle the parsing, but still accept
		// the "template" and "filterform" paramter. So we need to remove the corresponding
		// line from $match.
		$template = '';
		$lines = explode("\n",$match);
		foreach ( $lines as $num => $line ) {
			// ignore comments
			$line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
			$line = str_replace('\\#','#',$line);
			$line = trim($line);
			if(empty($line)) continue;
			$line = preg_split('/\s*:\s*/',$line,2);
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
			$data['cols']['%pageid%'] = array('multi' => '', 'key' => '%pageid%', 'title' => 'Title', 'type' => 'page');
			if(array_key_exists('headers', $data)) 
				array_push($data['headers'], '%pageid%');
		}
		return $data;
	}
	
	function _getCleanSQL($data) {
		// Build SQL from original $data, but without limits, offset
		// and additional filters.
        unset($data['limit']);
		$dataofs = $_REQUEST['dataofs'];
        unset($_REQUEST['dataofs']);
        $filter = $_REQUEST['dataflt'];
        unset($_REQUEST['dataflt']);
        $sql = $this->_buildSQL($data);
        $_REQUEST['dataofs'] = $dataofs;
		$_REQUEST['dataflt'] = $filter;
		return $sql;
	}

	/**
	 * Create output
	 */
	function render($format, &$R, $data) {
		global $ID;
		if(is_null($data)) return false;
		
		$sql = $this->_getCleanSQL($data);
        $mkey = 'datatemplate_' . md5($sql);
	
		if($format == 'metadata') {
			$this->_checkAndBuildMeta($data, $sql, $mkey);			
		}
		
		if($format == 'xhtml') {
			$R->info['cache'] = false;
						
			if(!array_key_exists('template', $data)) {
				// If keyword "template" not present, we will leave
				// the rendering to the parent class.
				static $msgout = false;
				if($msgout) {
					msg("datatemplatelist: no template specified, using standard table output.");
					$msgout = false;
				}
				parent::render($format, $R, $data);
				return;
			}
			
			$cnt = 0;
			
			$datarows = $rows ? $rows : p_get_metadata($ID, $mkey . "_data");
			//dbg("Datarows: " . count($datarows) . "\n" . "Rows: " . count($rows));
			$datarows = $this->_match_filters($data, $datarows);

			if(count($datarows) < $_REQUEST['dataofs']) $_REQUEST['dataofs'] = 0;
			
			$rows = array();
			$i = 0;					
			foreach($datarows as $row) {
				$i++;
				if($i - 1 < $_REQUEST['dataofs']) continue;	
				$rows[] = $row;
				$cnt++;
				
				if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
			}
	
			if ($cnt === 0) {
				$this->nullList($data, $clist, $R);
				return true;
			}
	
			// The following code is taken more or less from the templater plugin.
			// We are not using the plugin directly, because we want to use the
			// data plugin's treatment of URLs. Hence, we are going to do the
			// substitutions after the parsing
			$wikipage = preg_split('/\#/u', $data['template'], 2);
	
			$R->doc .= $this->_renderPagination($data, count($datarows));
			$this->_renderTemplate($wikipage, $data, $rows, $R);
			$R->doc .= $this->_renderPagination($data, count($datarows));
			return true;
		}
		return false;
	}
	
	function _checkAndBuildMeta($data, $sql, $mkey) {
		global $ID;
		// Check and generation of cached data to avoid
		// repeated use of the SQLite queries, which can end up
		// being quite slow.
		$sqlite = $this->dthlp->_getDB();
		
		// Build minimalistic data array for checking the cache
		$dtcc = array();
		$dtcc['cols'] = array(
            '%pageid%' => array(
                    'multi' => '',
                    'key' => '%pageid%',
                    'title' => 'Title',
                    'type' => 'page',
                ));
        $dtcc['filter'] = $data['filter'];
        $sqlcc = $this->_buildSQL($dtcc);
        $res = $sqlite->query($sqlcc);
        $pageids = sqlite_fetch_all($res, SQLITE_NUM);
        
        $cachedate = p_get_metadata($ID, $mkey . "_cachedate");
        //dbg("Cachedate: " . $cachedate);
        
        $latest = 0;
        if($cachedate) {	        	
        	// Check for newest page in SQL result        	
        	foreach($pageids as $pageid) {
        		$modified = filemtime(wikiFN($pageid[0]));
        		$latest = ($modified > $latest) ? $modified : $latest;
        	}
        	//dbg("Latest: " . $latest);
        }
        if(!$cachedate || $latest > (int) $cachedate  || isset($_REQUEST['purge'])) {       		
        	//dbg("Rebuilding cache.");
        	$res = $sqlite->query($sql);
        	$rows = sqlite_fetch_all($res, SQLITE_NUM);
        	$md = array($mkey . "_cachedate" => time(), $mkey . "_data" => $rows);
        	p_set_metadata($ID, $md);
        } else {
        	// We arrive here when the cache seems up-to-date. However,
        	// it is possible that the cache contains items which should
        	// no longer be there. We need to find those and remove those.
        	
	        // First create map id->index for the pages that should be there.
	        $dataitems = array();
	        foreach($pageids as $num=>$row)
	        	$dataitems[trim($row[0])] = $num;
	        $cache = p_get_metadata($ID, $mkey . "_data");
	        
	        // Now do the same things for the pages that ARE there.
	        // Figure out which row-element is the page id.
	        $idx = 0;
	        foreach($data['cols'] as $key=>$value) {
	        	if($key == '%pageid%') break;
	        	$idx++;
	        }
	        $cacheitems = array();
	        foreach($cache as $num=>$row) 
	        	$cacheitems[trim($row[$idx])] = $num;
	        
	        // Now calculate the difference and update cache
	        $diff = array_diff_key($cacheitems, $dataitems);
	        if(count($diff) > 0) {
	        	foreach($diff as $key => $num)
	        		unset($cache[$num]);
	        	$md = array($mkey . "_cachedate" => time(), $mkey . "_data" => $cache);
	        	p_set_metadata($ID, $md);
	        }
        }
	}

	function _renderTemplate($wikipage, $data, $rows, $R) {
		global $ID;
		resolve_pageid(getNS($ID), $wikipage[0], $exists);          // resolve shortcuts

		// check for permission
		if (auth_quickaclcheck($wikipage[0]) < 1) {
			// False means no permissions
			$R->doc .= '<div class="datatemplatelist"> No permissions to view the template </div>';
			return true;
		}
			
		// Now open the template, parse it and do the substitutions.
		// FIXME: This does not take circular dependencies into account!
		$file = wikiFN($wikipage[0]);
		if (!@file_exists($file)) {
			$R->doc .= '<div class="datatemplatelist">';
			$R->doc .= "Template {$wikipage[0]} not found. ";
			$R->internalLink($wikipage[0], '[Click here to create it]');
			$R->doc .= '</div>';
			return true;
		}

		// Construct replacement keys
		foreach ($data['headers'] as $num => $head) {
			$replacers['keys'][] = "@@" . $head . "@@";
			$replacers['raw_keys'][] = "@@!" . $head . "@@";
		}

		// Get the raw file, and parse it into its instructions. This could be cached... maybe.
		$rawFile = io_readfile($file);

		// embed the included page
		$R->doc .= "<div class=\"${data['classes']}\">";

		// We only want to call the parser once, so first do all the raw replacements and concatenate
		// the strings.
		$clist = array_keys($data['cols']);
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
		$R->doc .= $text;
		$R->doc .= '</div>';
	}

	function _renderPagination($data, $numrows) {
		global $ID;
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

            for($i=1; $i <= 1 + $numrows / $data['limit']; $i++) {
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
            
            if($text != '&nbsp;') 
            	return '<div class="prevnext">' . $text . '</div>';
            return "";
        }
	}
	
	function _match_filters($data, $datarows) {
		/* Get whole $data as input and
		 * - generate keys
		 * - treat multi-value columns specially, i.e. add 's' to key and look at individual values
		 */
		$out = array();
		$keys = array();
		foreach($data['headers'] as $k => $v)
			$keys[$v] = $k;
		$filters = $this->dthlp->_get_filters();
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
				if($f['logic'] == 'AND')
					$matched = $matched && $colmatch;
				else
					$matched = $matched || $colmatch;
			}
			if($matched) $out[] = $dr;
		}
		return $out;
	}
	
	function _match_wildcard( $wildcard_pattern, $haystack ) {
		$regex = str_replace(
 	    	array("%", "\?"), // wildcard chars
    	 	array('.*','.'),   // regexp chars
     		preg_quote($wildcard_pattern)
   		);
		return preg_match('/^\s*'.$regex.'$/im', $haystack);
	}
	
	function nullList($data, $clist, &$R) {
		$R->doc .= '<div class="templatelist">Nothing.</div>';
	}
}
